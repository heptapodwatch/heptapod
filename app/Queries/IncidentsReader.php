<?php

declare(strict_types=1);

namespace Heptapod\Queries;

use Carbon\CarbonImmutable;
use Heptapod\Checks\CheckRunPresentations;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\EngineMessage;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Contracts\SecretRedactor;
use Heptapod\Data\Ui\IncidentData;
use Heptapod\Data\Ui\IncidentDeliveryData;
use Heptapod\Data\Ui\IncidentEvidenceData;
use Heptapod\Data\Ui\IncidentFilterData;
use Heptapod\Data\Ui\IncidentNotificationGroupData;
use Heptapod\Data\Ui\IncidentNotificationPageData;
use Heptapod\Data\Ui\IncidentsIndexData;
use Heptapod\Data\Ui\IncidentSummaryData;
use Heptapod\Data\Ui\IncidentTimelineEntryData;
use Heptapod\Data\Ui\PaginationData;
use Heptapod\Enums\AlertSensitivity;
use Heptapod\Enums\DeliveryGroupOutcome;
use Heptapod\Enums\DeliveryOutcome;
use Heptapod\Enums\IncidentCause;
use Heptapod\Events\EventPresenter;
use Heptapod\Events\Notifications\NotificationEventTypes;
use Heptapod\Events\Notifications\UptimeAlertEpisode;
use Heptapod\Incidents\UptimeDependencies;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Policies\SitePolicy;
use Heptapod\Settings\InstanceSettings;
use Heptapod\Support\RedactionContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilderBase;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

final readonly class IncidentsReader
{
    /** Event types the episode timeline shows, each with its mail group; other incident events' mail is listed separately. */
    public const array TimelineEventTypes = ['incident.opened', 'incident.cause_changed', 'incident.still_down', 'incident.resolved'];

    /** The Incidents list and its export show open incidents unless the URL asks for resolved or all. */
    public const string DefaultStatus = 'open';

    public function __construct(private SitePolicy $policy, private SitesReader $sites, private InstanceSettings $settings, private NotificationEventTypes $notificationTypes, private CheckRunPresentations $presentations, private EventPresenter $eventPresenter) {}

    /** @return Builder<Incident> */
    public function scoped(User $user, bool $includeArchived = false): Builder
    {
        $sites = $this->policy->visibleSites($user, $includeArchived)->getQuery()->select('sites.id');
        $query = Incident::query()->with(['site', 'check']);
        $query->getQuery()->whereIn('site_id', $sites);

        return $query;
    }

    /** @return Builder<Incident> */
    public function scopedForSearch(User $user, string $pattern, ?int $incidentId): Builder
    {
        $visibleSiteIds = $this->policy->visibleSiteIds($user);
        $sites = Site::query()->getQuery();
        $sites->whereIntegerInRaw('sites.id', $visibleSiteIds)
            ->where(static function (QueryBuilderBase $query) use ($pattern): void {
                $query->where('friendly_name', 'ilike', $pattern)->orWhere('url', 'ilike', $pattern);
            });
        /** @var list<int> $matchingSiteIds */
        $matchingSiteIds = $sites->pluck('sites.id')->all();

        $query = Incident::query()->with(['site', 'check']);
        $query->getQuery()->whereIntegerInRaw('site_id', $visibleSiteIds)
            ->where(static function (QueryBuilderBase $query) use ($matchingSiteIds, $incidentId): void {
                $query->whereIntegerInRaw('site_id', $matchingSiteIds);
                if ($incidentId !== null) {
                    $query->orWhere('incidents.id', $incidentId);
                }
            });

        return $query;
    }

    /** @param Collection<int, Incident> $incidents
     * @param  Collection<int, CheckRun>  $latestRuns
     * @return list<IncidentData>
     */
    private function rows(Collection $incidents, Collection $latestRuns): array
    {
        $openQuery = Incident::query()->with('check');
        $openQuery->getQuery()->whereIn('site_id', $incidents->pluck('site_id')->unique())->whereNull('resolved_at');
        $open = $openQuery->get();
        $held = UptimeDependencies::heldAfterRecovery(array_values($incidents->map(static fn (Incident $incident): int => $incident->id)->all()));
        $rows = [];
        foreach ($incidents as $incident) {
            $site = $incident->site ?? throw new \LogicException('Incident must belong to a site.');
            $check = $incident->check ?? throw new \LogicException('Incident must belong to a check.');
            $context = RedactionContext::forSite($site, $check, $incident->evidence);
            $summary = $incident->evidence['summary'] ?? null;
            if ($summary !== null && ! is_string($summary)) {
                throw new \LogicException('Incident opening summary is invalid.');
            }
            $summary = $summary === null ? null : SecretRedactor::redact($summary, $context->secrets, $context->urls);
            $params = $this->redactedSummaryParams($incident->evidence['summary_params'] ?? [], $context);
            if ($summary !== null) {
                ['key' => $summary, 'params' => $params] = EngineMessage::normalize($summary, $params);
            }
            $check->setRelation('site', $site);
            $presentation = $this->presentations->forIncident($incident, $latestRuns->get($check->id), isset($held[$incident->id]));
            $rows[] = new IncidentData($incident->id, $site->id, $site->displayName(), $site->client_id, $check->type, $incident->cause,
                (int) $incident->first_failure_at->diffInSeconds($incident->resolved_at ?? now()), $incident->first_failure_at->toIso8601String(),
                $incident->resolved_at?->toIso8601String(), null, $incident->snoozed_until?->toIso8601String(),
                $summary, $params, $site->iconUrl(),
                UptimeDependencies::isGrouped($incident) ? UptimeDependencies::parentId($incident) : null,
                $check->type === CheckKey::Uptime ? array_values($open->filter(static fn (Incident $dependent): bool => UptimeDependencies::isGrouped($dependent)
                    && UptimeDependencies::parentId($dependent) === $incident->id)
                    ->map(static fn (Incident $dependent): string => ($dependent->check ?? throw new \LogicException('Dependent check missing.'))->type->value)->all()) : [],
                UptimeDependencies::isGrouped($incident) ? UptimeDependencies::recheckAfter($incident) : null,
                isset($held[$incident->id]), $check->next_run_at->toISOString(), $site->icon_backing, $presentation);
        }

        return $rows;
    }

    /** @return QueryBuilder<Incident> */
    public function filtered(User $user, Request $request): QueryBuilder
    {
        $timezone = $this->settings->timezone;
        $query = QueryBuilder::for($this->scoped($user), $request)->allowedFilters(
            AllowedFilter::callback('status', static function (Builder $query, mixed $value): void {
                match ($value) {
                    'open' => $query->getQuery()->whereNull('resolved_at'),
                    'resolved' => $query->getQuery()->whereNotNull('resolved_at'),
                    'all' => null,
                    default => throw new \LogicException('Incident status filter must be validated.'),
                };
            })->default(self::DefaultStatus),
            AllowedFilter::callback('period', static function (Builder $query, mixed $value): void {
                if ($value === '30d') {
                    $query->getQuery()->where(static fn (QueryBuilderBase $period) => $period->where('first_failure_at', '>=', CarbonImmutable::now('UTC')->subDays(30)->format('Y-m-d H:i:s.uP'))->orWhereNull('resolved_at'));
                }
            }),
            AllowedFilter::exact('cause'),
            AllowedFilter::exact('check', 'check.type'),
            AllowedFilter::exact('client_id', 'site.client_id'),
            AllowedFilter::callback('started_after', static function (Builder $query, mixed $value) use ($timezone): void {
                if (! is_string($value)) {
                    throw new \LogicException('Incident date filter must be validated as a string.');
                }
                $start = CarbonImmutable::parse($value, $timezone)->utc();
                $query->getQuery()->where('first_failure_at', '>=', $start->format('Y-m-d H:i:s.uP'));
            }),
            AllowedFilter::callback('started_before', static function (Builder $query, mixed $value) use ($timezone): void {
                if (! is_string($value)) {
                    throw new \LogicException('Incident date filter must be validated as a string.');
                }
                $end = CarbonImmutable::parse($value, $timezone);
                $wholeDay = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1;
                $end = $wholeDay ? $end->addDay() : $end;
                $query->getQuery()->where('first_failure_at', $wholeDay ? '<' : '<=', $end->utc()->format('Y-m-d H:i:s.uP'));
            }),
        );
        if (! $request->filled('filter.started_after') && ! $request->filled('filter.started_before')
            && ! $request->filled('filter.period')) {
            $query->getEloquentBuilder()->getQuery()->where(static fn (QueryBuilderBase $period) => $period->where('first_failure_at', '>=', CarbonImmutable::now('UTC')->subDays(30)->format('Y-m-d H:i:s.uP'))->orWhereNull('resolved_at'));
        }
        $query->getEloquentBuilder()->getQuery()->orderByRaw('resolved_at IS NOT NULL')->orderByDesc('opened_at')->orderByDesc('id');

        return $query;
    }

    public function index(User $user, Request $request, bool $includeClients = true): IncidentsIndexData
    {
        $page = $this->filtered($user, $request)->paginate(50)->withQueryString();
        $filters = new IncidentFilterData($request->filled('filter.status') ? $request->string('filter.status')->toString() : self::DefaultStatus,
            $request->filled('filter.cause') ? IncidentCause::from($request->string('filter.cause')->toString()) : null,
            $request->filled('filter.check') ? CheckKey::from($request->string('filter.check')->toString()) : null,
            $request->filled('filter.client_id') ? $request->integer('filter.client_id') : null,
            $request->filled('filter.started_after') ? $request->string('filter.started_after')->toString() : null,
            $request->filled('filter.started_before') ? $request->string('filter.started_before')->toString() : null,
            $request->filled('filter.period') ? $request->string('filter.period')->toString() : '30d');

        /** @var \Illuminate\Database\Eloquent\Collection<int, Incident> $incidents */
        $incidents = $page->getCollection();

        return new IncidentsIndexData($this->presentedRows($incidents),
            new PaginationData($page->currentPage(), $page->perPage(), $page->total(), $page->lastPage(), $page->nextPageUrl(), $page->previousPageUrl()), $filters, $includeClients ? $this->sites->clients($user) : []);
    }

    /** @param Collection<int, Incident> $incidents Already bounded, authorized rows.
     * @return list<IncidentData>
     */
    public function presentedRows(Collection $incidents): array
    {
        $currentChecks = $incidents->filter(static fn (Incident $incident): bool => $incident->resolved_at === null
            && ! UptimeDependencies::isGrouped($incident))->map(static fn (Incident $incident): Check => $incident->check ?? throw new \LogicException('Incident check missing.'))
            ->filter(static fn (Check $check): bool => $check->latest_run_id !== null && $check->latest_run_ended_at !== null);
        /** @var Collection<int, CheckRun> $latestRuns */
        $latestRuns = collect();
        if ($currentChecks->isNotEmpty()) {
            // Each physical run reference includes its partition key; retained-away runs simply have no current evidence.
            $query = CheckRun::query();
            $query->getQuery()->where(static function (QueryBuilderBase $query) use ($currentChecks): void {
                foreach ($currentChecks as $check) {
                    $query->orWhere(static function (QueryBuilderBase $query) use ($check): void {
                        $query->where('id', $check->latest_run_id)->where('check_id', $check->id)
                            ->where('ended_at', ($check->latest_run_ended_at ?? throw new \LogicException('Current run needs its partition key.'))->format('Y-m-d H:i:s.uP'));
                    });
                }
            });
            $latestRuns = $query->get()->keyBy('check_id');
        }

        return $this->rows($incidents, $latestRuns);
    }

    public function summary(User $user): IncidentSummaryData
    {
        $query = Incident::query()->getQuery();
        $query->whereIntegerInRaw('site_id', $this->policy->visibleSiteIds($user));
        $now = CarbonImmutable::now('UTC');
        $query->where('first_failure_at', '>=', $now->subDays(30)->format('Y-m-d H:i:s.uP'));
        $duration = 'greatest(0, extract(epoch from (coalesce(resolved_at, ?::timestamptz) - first_failure_at)))';
        $result = $query->selectRaw("count(*) AS incident_count, coalesce(sum($duration), 0) AS duration_total, percentile_cont(0.5) within group (order by $duration) AS duration_median", [$now->format('Y-m-d H:i:s.uP'), $now->format('Y-m-d H:i:s.uP')])->first();
        if ($result === null || ! is_numeric($result->incident_count) || ! is_numeric($result->duration_total)
            || ($result->duration_median !== null && ! is_numeric($result->duration_median))) {
            throw new \LogicException('Incident summary aggregate is invalid.');
        }

        return new IncidentSummaryData((int) $result->incident_count, (int) $result->duration_total,
            $result->duration_median === null ? null : (int) round((float) $result->duration_median));
    }

    /** @return list<IncidentData> */
    public function relatedIncidents(User $user, Incident $incident): array
    {
        $site = $incident->site ?? throw new \LogicException('Incident site missing.');
        $query = $this->scoped($user)->whereHas('site', static fn (Builder $sites): Builder => $sites->where('client_id', $site->client_id));
        $query->getQuery()->whereNull('resolved_at')->where('incidents.id', '<>', $incident->id)
            ->orderByRaw('site_id = ? DESC', [$incident->site_id])->orderByDesc('opened_at')->orderByDesc('id')->limit(10);

        return $this->presentedRows($query->get());
    }

    /**
     * Collapse the whole active episode, not a page of reminders. Distinct anchors and the last saved note
     * break notification groups. Immutable event time orders deliveries, so later SMTP acceptance cannot
     * rename a group. A resolved episode ends with its recovery event and recovery-mail group.
     *
     * @param  CarbonImmutable|null  $asOf  cutoff for events and the note, applied before collapse; null means now
     * @return list<IncidentTimelineEntryData>
     */
    public function timeline(User $user, Incident $incident, ?CarbonImmutable $asOf = null): array
    {
        $cutoff = $asOf ?? CarbonImmutable::now('UTC');
        $noteVisible = $incident->notes !== null && $incident->note_updated_at !== null && $incident->note_updated_at->lessThanOrEqualTo($cutoff);
        $sent = DeliveryState::Sent->sql();
        $logged = DeliveryState::Logged->sql();
        $failed = DeliveryState::Failed->sql();
        $accepted = DeliveryGroupOutcome::Accepted->sql();
        $loggedOutcome = DeliveryGroupOutcome::Logged->sql();
        $failedOutcome = DeliveryGroupOutcome::Failed->sql();
        $suppressed = DeliveryGroupOutcome::Suppressed->sql();
        $pending = DeliveryGroupOutcome::Pending->sql();
        $mixed = DeliveryGroupOutcome::Mixed->sql();
        $unrecorded = DeliveryGroupOutcome::Unrecorded->sql();
        $rows = DB::select(<<<SQL
            WITH episode_events AS MATERIALIZED (
                SELECT id, occurred_at, type, suppression_reason FROM events WHERE incident_id = ? AND site_id = ? AND occurred_at >= ? AND occurred_at <= ?
                AND type IN (?, ?, ?, ?)
            ), episode_deliveries AS (
                SELECT delivery.id, delivery.event_id, delivery.destination_id,
                    event.occurred_at AS time, event.type,
                    CASE WHEN delivery.state = {$sent} THEN {$accepted}
                        WHEN delivery.state = {$logged} THEN {$loggedOutcome}
                        WHEN delivery.suppression_reason = 'smtp_recipient_rejected' THEN {$failedOutcome}
                        WHEN delivery.suppressed THEN {$suppressed} WHEN delivery.state = {$failed} THEN {$failedOutcome}
                        ELSE {$pending} END AS outcome
                FROM episode_events event JOIN deliveries delivery ON delivery.event_id = event.id
                JOIN notification_destinations destination ON destination.id = delivery.destination_id
            ), entries AS (
                SELECT id AS entry_id, id AS event_id, occurred_at AS time,
                    CASE WHEN type = 'incident.still_down' THEN 'notification' ELSE 'event' END AS kind, type,
                    CASE WHEN type = 'incident.still_down' THEN {$unrecorded} ELSE NULL::text END AS outcome,
                    suppression_reason AS unrecorded_reason,
                    NULL::bigint AS destination_id, '0:event:' || lpad(id::text, 20, '0') AS entry_key,
                    CASE WHEN type = 'incident.still_down' THEN 'notification:' || type || ':mail' ELSE 'event:' || id::text END AS group_key FROM episode_events event
                WHERE type <> 'incident.still_down' OR NOT EXISTS (SELECT 1 FROM deliveries delivery WHERE delivery.event_id = event.id)
                UNION ALL
                SELECT id, event_id, time, 'notification', type, outcome, NULL::text, destination_id,
                    '1:delivery:' || lpad(id::text, 20, '0'), 'notification:' || type || ':mail' FROM episode_deliveries
                UNION ALL
                SELECT ?::bigint, NULL::bigint, ?::timestamptz, 'note', 'note', NULL::text, NULL::text, NULL::bigint,
                    '2:note', 'note' WHERE ?::boolean
            ), changes AS (
                SELECT *, CASE WHEN group_key = lag(group_key) OVER (ORDER BY time, entry_key) THEN 0 ELSE 1 END AS changed FROM entries
            ), numbered AS (
                SELECT *, sum(changed) OVER (ORDER BY time, entry_key) AS group_number FROM changes
            )
            SELECT kind, type, CASE WHEN count(DISTINCT outcome) FILTER (WHERE destination_id IS NOT NULL) > 1 THEN {$mixed}
                    ELSE coalesce(min(outcome) FILTER (WHERE destination_id IS NOT NULL), {$unrecorded}) END AS outcome,
                (array_agg(entry_id ORDER BY time, entry_key))[1] AS id, min(event_id) AS event_id,
                count(*) FILTER (WHERE destination_id IS NOT NULL) AS count,
                split_part((array_agg(entry_key ORDER BY time, entry_key))[1], ':', 2) AS first_source,
                split_part((array_agg(entry_key ORDER BY time DESC, entry_key DESC))[1], ':', 2) AS last_source,
                count(*) FILTER (WHERE outcome = {$unrecorded}) AS unrecorded_count,
                jsonb_build_object(
                    'monitoring_stopped', count(*) FILTER (WHERE outcome = {$unrecorded} AND unrecorded_reason = 'monitoring_stopped'),
                    'maintenance', count(*) FILTER (WHERE outcome = {$unrecorded} AND unrecorded_reason = 'maintenance'),
                    'snooze', count(*) FILTER (WHERE outcome = {$unrecorded} AND unrecorded_reason = 'snooze'),
                    'uptime_dependency', count(*) FILTER (WHERE outcome = {$unrecorded} AND unrecorded_reason = 'uptime_dependency')
                ) AS unrecorded_reasons,
                min(time) AS first_at, max(time) AS last_at,
                (array_agg(entry_id ORDER BY time, entry_key))[1] AS first_id,
                (array_agg(entry_id ORDER BY time DESC, entry_key DESC))[1] AS last_id,
                to_json((array_agg(DISTINCT destination_id) FILTER (WHERE destination_id IS NOT NULL))[1:10]) AS destination_ids,
                count(DISTINCT destination_id) AS recipient_count,
                count(*) FILTER (WHERE outcome = {$accepted}) AS accepted_count,
                count(*) FILTER (WHERE outcome = {$loggedOutcome}) AS logged_count,
                count(*) FILTER (WHERE outcome = {$failedOutcome}) AS failed_count,
                count(*) FILTER (WHERE outcome = {$suppressed}) AS suppressed_count,
                count(*) FILTER (WHERE outcome = {$pending}) AS pending_count
            FROM numbered GROUP BY group_number, kind, type ORDER BY min(time), min(entry_key)
            SQL, [$incident->id, $incident->site_id, $incident->first_failure_at->format('Y-m-d H:i:s.uP'), $cutoff->format('Y-m-d H:i:s.uP'), ...self::TimelineEventTypes,
            $incident->id, $incident->note_updated_at?->format('Y-m-d H:i:s.uP'), $noteVisible]);
        $eventIds = [];
        $destinationIds = [];
        foreach ($rows as $row) {
            /** @var \stdClass&object{event_id: int|null, destination_ids: string|null} $row */
            if ($row->event_id !== null) {
                $eventIds[] = $row->event_id;
            }
            $ids = $row->destination_ids === null ? [] : json_decode($row->destination_ids, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($ids)) {
                throw new \LogicException('Timeline recipient identifiers must be a list.');
            }
            foreach ($ids as $id) {
                if (! is_int($id)) {
                    throw new \LogicException('Timeline recipient identifier must be an integer.');
                }
                $destinationIds[] = $id;
            }
        }
        $eventQuery = Event::query()->with(['site.client', 'check', 'incident']);
        $eventQuery->getQuery()->whereIn('id', $eventIds);
        $events = $eventQuery->get()->keyBy('id');
        $destinationQuery = NotificationDestination::query();
        $destinationQuery->getQuery()->whereIn('id', array_unique($destinationIds));
        $destinations = $destinationQuery->get()->keyBy('id');
        $entries = [];
        foreach ($rows as $row) {
            /** @var \stdClass&object{kind: string, type: string, outcome: string|null, id: int, event_id: int|null, count: int, first_source: string, last_source: string, unrecorded_count: int, unrecorded_reasons: string, first_at: string, last_at: string, first_id: int, last_id: int, destination_ids: string|null, recipient_count: int, accepted_count: int, logged_count: int, failed_count: int, suppressed_count: int, pending_count: int} $row */
            $time = CarbonImmutable::parse($row->first_at)->format('Y-m-d\TH:i:s.uP');
            $id = $row->first_source.':'.$row->id;
            if ($row->kind === 'note') {
                $entries[] = new IncidentTimelineEntryData($id, $time, note: $incident->notes, note_author: $incident->noteAuthor?->name);

                continue;
            }
            $event = $events->get($row->event_id) ?? throw new \LogicException('Timeline event missing.');
            if ($row->kind === 'event') {
                $entries[] = new IncidentTimelineEntryData($id, $time, event: $this->eventPresenter->present($event));

                continue;
            }
            /** @var list<int> $ids Validated database identifiers above. */
            $ids = json_decode($row->destination_ids ?? '[]', true, flags: JSON_THROW_ON_ERROR);
            $recipients = [];
            foreach ($ids as $destinationId) {
                $destination = $destinations->get($destinationId) ?? throw new \LogicException('Timeline destination missing.');
                $recipients[] = $this->recipientName($user, $destination);
            }
            /** @var array<string, int> $unrecordedReasons */
            $unrecordedReasons = json_decode($row->unrecorded_reasons, true, flags: JSON_THROW_ON_ERROR);
            $entries[] = new IncidentTimelineEntryData($id, $time, notification_group: new IncidentNotificationGroupData(
                $id, $row->type, $this->deliveryEventLabel($event), DeliveryGroupOutcome::from($row->outcome ?? throw new \LogicException('Notification group outcome missing.')),
                $row->count, $time, CarbonImmutable::parse($row->last_at)->format('Y-m-d\TH:i:s.uP'), $row->first_id, $row->last_id,
                $recipients, $row->recipient_count, $row->accepted_count, $row->failed_count, $row->suppressed_count, $row->pending_count, $row->unrecorded_count, $row->last_source, $unrecordedReasons, $row->logged_count));
        }

        return $entries;
    }

    public function expandedNotifications(User $user, Incident $incident, string $groupId, ?string $cursor, bool $requireGroup = true): ?IncidentNotificationPageData
    {
        $group = null;
        foreach ($this->timeline($user, $incident) as $entry) {
            if ($entry->notification_group?->id === $groupId) {
                $group = $entry->notification_group;
                break;
            }
        }
        if ($group === null) {
            // A saved full-page URL can outlive a group; explicit expansion requests still reject it.
            abort_if($requireGroup, 404);

            return null;
        }
        $query = Delivery::query()->with(['event.check', 'event.incident.check', 'destination']);
        $query->getQuery()->join('events as timeline_event', 'timeline_event.id', '=', 'deliveries.event_id')
            ->select('deliveries.*')->where('timeline_event.incident_id', $incident->id)->where('timeline_event.site_id', $incident->site_id)
            ->where('timeline_event.type', $group->event_type)
            ->where('timeline_event.occurred_at', '>=', $incident->first_failure_at->format('Y-m-d H:i:s.uP'));
        $time = 'timeline_event.occurred_at';
        $query->getQuery()->whereRaw("$time >= ?::timestamptz", [$group->first_at])->whereRaw("$time <= ?::timestamptz", [$group->last_at]);
        if (str_starts_with($group->id, 'delivery:')) {
            $query->getQuery()->whereRaw("($time, deliveries.id) >= (?::timestamptz, ?::bigint)", [$group->first_at, $group->first_id]);
        }
        if ($group->last_entry_kind === 'delivery') {
            $query->getQuery()->whereRaw("($time, deliveries.id) <= (?::timestamptz, ?::bigint)", [$group->last_at, $group->last_id]);
        }
        if ($cursor !== null) {
            $parts = explode('|', $cursor);
            abort_unless(count($parts) === 2 && preg_match('/^[1-9][0-9]*$/D', $parts[1]) === 1, 422);
            $before = CarbonImmutable::parse($parts[0])->format('Y-m-d H:i:s.uP');
            $query->getQuery()->whereRaw("($time, deliveries.id) < (?::timestamptz, ?::bigint)", [$before, $parts[1]]);
        }
        $query->getQuery()->orderByRaw("$time DESC")->orderByDesc('deliveries.id')->limit(51);
        $rows = $query->get();
        $pageRows = $rows->take(50);
        $last = $pageRows->last();
        $next = $rows->count() > 50 && $last !== null ? ($last->event ?? throw new \LogicException('Timeline delivery event missing.'))->occurred_at->format('Y-m-d\TH:i:s.uP').'|'.$last->id : null;

        return new IncidentNotificationPageData($groupId, $this->deliveryRows($user, $pageRows), $next, $cursor !== null);
    }

    public function evidence(Incident $incident): IncidentEvidenceData
    {
        $site = $incident->site ?? throw new \LogicException('Incident site missing.');
        $check = $incident->check ?? throw new \LogicException('Incident check missing.');
        $snapshot = $incident->evidence;
        $context = RedactionContext::forSite($site, $check, $snapshot);
        $facts = SecretRedactor::redactValues($snapshot, $context->secrets, $context->urls);
        if (! is_array($facts)) {
            throw new \LogicException('Incident evidence must be an object.');
        }
        /** @var array<string, mixed> $facts */
        $recoveryQuery = Event::query();
        $recoveryQuery->getQuery()->where('site_id', $incident->site_id)
            ->where('occurred_at', '>=', $incident->opened_at->format('Y-m-d H:i:s.uP'))
            ->where('incident_id', $incident->id)->where('type', 'incident.resolved')->orderBy('occurred_at');
        $recovery = $recoveryQuery->first();
        $recoverySummary = $recovery?->payload['summary'] ?? null;
        if ($recoverySummary !== null && ! is_string($recoverySummary)) {
            throw new \LogicException('Incident recovery summary is invalid.');
        }
        $recoveryParams = $this->redactedSummaryParams($recovery?->payload['summary_params'] ?? [], $context);
        if ($recoverySummary !== null) {
            ['key' => $recoverySummary, 'params' => $recoveryParams] = EngineMessage::normalize($recoverySummary, $recoveryParams);
        }
        $goodResults = null;
        if ($incident->resolved_at !== null && $incident->resolved_at->greaterThanOrEqualTo(CarbonImmutable::now('UTC')->subDays(config()->integer('monitoring.raw_retention_days')))) {
            $runs = CheckRun::query()->getQuery();
            $goodResults = count($runs->where('check_id', $incident->check_id)
                ->where('ended_at', '>', $incident->resolved_at->format('Y-m-d H:i:s.uP'))
                ->where('result', 'succeeded')->limit(21)->pluck('id')->all());
        }

        $summary = $snapshot['summary'] ?? null;
        if ($summary !== null && ! is_string($summary)) {
            throw new \LogicException('Incident opening summary is invalid.');
        }

        $params = $this->redactedSummaryParams($snapshot['summary_params'] ?? [], $context);
        if ($summary !== null) {
            ['key' => $summary, 'params' => $params] = EngineMessage::normalize($summary, $params);
        }

        return new IncidentEvidenceData($facts, $summary === null ? null : SecretRedactor::redact($summary, $context->secrets, $context->urls),
            $params,
            is_string($snapshot['run_ended_at'] ?? null) ? $snapshot['run_ended_at'] : null,
            $recovery?->occurred_at->toIso8601String(), $recoverySummary === null ? null : SecretRedactor::redact($recoverySummary, $context->secrets, $context->urls),
            $recoveryParams, $goodResults === null ? null : min($goodResults, 20), $goodResults !== null && $goodResults > 20);
    }

    /** @return array<string, string|int|float|bool|null> */
    private function redactedSummaryParams(mixed $params, RedactionContext $context): array
    {
        if (! is_array($params)) {
            throw new \LogicException('Incident summary parameters must be an object.');
        }
        foreach ($params as $key => $value) {
            if (! is_string($key) || (! is_scalar($value) && $value !== null)) {
                throw new \LogicException('Incident summary parameters must be scalar.');
            }
        }

        return SecretRedactor::redactStringValues($params, $context);
    }

    public function openingDeliveryExplanation(Incident $incident): ?string
    {
        if ($incident->resolved_at !== null || ! UptimeAlertEpisode::appliesTo($incident)) {
            return null;
        }
        $opening = UptimeAlertEpisode::routingOpening($incident);

        return UptimeAlertEpisode::sentOpening($incident) instanceof Delivery
            ? null : UptimeAlertEpisode::openingSuppressionExplanation($incident, $opening);
    }

    /**
     * The latest 50 mail deliveries of the incident's events plus one truncation marker.
     *
     * @param  list<string>  $exceptEventTypes
     * @return list<IncidentDeliveryData>
     */
    public function deliveries(User $user, Incident $incident, array $exceptEventTypes = []): array
    {
        $deliveryQuery = Delivery::query()->with(['event.site.client', 'event.check', 'event.incident.check', 'destination'])
            ->whereHas('event', static function (Builder $query) use ($incident, $exceptEventTypes): void {
                $query->getQuery()->where('incident_id', $incident->id)->whereNotIn('type', $exceptEventTypes);
            });
        $deliveryQuery->getQuery()->orderByDesc('id')->limit(51);

        return $this->deliveryRows($user, $deliveryQuery->get());
    }

    /** @param Collection<int, Delivery> $rows
     * @return list<IncidentDeliveryData>
     */
    private function deliveryRows(User $user, Collection $rows): array
    {
        /** @var Collection<int, int|string> $previewIds */
        $previewIds = collect();
        if ($user->hasAnyRole(['Owner', 'Admin']) && $rows->contains(static fn (Delivery $delivery): bool => $delivery->state === DeliveryState::Sent && ! $delivery->suppressed && ! $delivery->digest)) {
            $previewQuery = Delivery::query()
                ->whereHas('event', static function (Builder $query): void {
                    $query->scopes('incidentAlertPreviewTypes');
                })
                ->whereHas('destination', fn (Builder $query) => $query->where('channel', 'mail'));
            $previewQuery->getQuery()->whereIn('event_id', $rows->pluck('event_id')->all())
                ->where('state', DeliveryState::Sent->value)->where('suppressed', false)->where('digest', false)
                ->selectRaw('event_id, min(id) as first_id')->groupBy('event_id');
            $previewIds = $previewQuery->getQuery()->pluck('first_id', 'event_id');
        }
        $deliveries = $rows->map(function (Delivery $delivery) use ($user, $previewIds): IncidentDeliveryData {
            $destination = $delivery->destination ?? throw new \LogicException('Incident delivery has no destination.');
            $address = $this->recipientName($user, $destination);
            $event = $delivery->event ?? throw new \LogicException('Incident delivery has no event.');
            $previewId = $previewIds->get($event->id);
            if ($previewId !== null && ! is_int($previewId) && (! is_string($previewId) || preg_match('/^[0-9]+$/D', $previewId) !== 1)) {
                throw new \LogicException('Preview delivery ID must be numeric.');
            }

            $diagnosis = match ($delivery->suppression_reason) {
                'monitoring_stopped' => __('notifications.suppression.monitoring_stopped'),
                UptimeDependencies::SuppressionReason => __('notifications.suppression.uptime_dependency'),
                'destination_disabled' => __('Paused'),
                'recovered_within_alert_delay' => __('notifications.suppression.recovered_within_alert_delay'),
                'episode_already_alerted' => __('notifications.suppression.episode_already_alerted'),
                'episode_continued' => __('notifications.suppression.episode_continued'),
                'superseded_by_maintenance_catch_up' => __('notifications.suppression.superseded_by_maintenance_catch_up'),
                'reopened_before_recovery_confirmed' => __('notifications.suppression.reopened_before_recovery_confirmed'),
                default => $delivery->last_diagnosis,
            };
            if (! $delivery->suppressed && in_array($delivery->last_reason, ['deferred_by_maintenance', 'deferred_by_snooze'], true)) {
                $diagnosis = __('notifications.'.$delivery->last_reason, [
                    'time' => $delivery->next_attempt_at->setTimezone($user->timezone ?? app(InstanceSettings::class)->timezone)->format('Y-m-d H:i T'),
                ]);
            } elseif (! $delivery->suppressed && $delivery->last_reason === UptimeAlertEpisode::WaitingReason) {
                $diagnosis = __('notifications.recovery_checks_pending');
            } elseif (! $delivery->suppressed && $delivery->state === DeliveryState::Pending
                && UptimeAlertEpisode::isOpeningEvent($event)
                && $event->incident !== null && UptimeAlertEpisode::appliesTo($event->incident) && $delivery->next_attempt_at->isFuture()) {
                $delay = UptimeAlertEpisode::timing($event->incident);
                $diagnosis = __('notifications.alert_delay_due', [
                    'time' => $delivery->next_attempt_at->setTimezone($user->timezone ?? app(InstanceSettings::class)->timezone)->format('Y-m-d H:i T'),
                    'preset' => $delay->preset instanceof AlertSensitivity ? __('alert_sensitivity.preset.'.$delay->preset->value.'.label') : __('alert_sensitivity.custom'),
                    'minutes' => $delay->alertAfterMinutes,
                ]);
            }
            if ($diagnosis !== null && ! is_string($diagnosis)) {
                throw new \LogicException('Delivery diagnosis must be text.');
            }

            $outcome = match (true) {
                $delivery->state === DeliveryState::Sent => DeliveryOutcome::Accepted,
                $delivery->suppression_reason === 'smtp_recipient_rejected' => DeliveryOutcome::Rejected,
                $delivery->suppressed => DeliveryOutcome::Suppressed,
                default => match ($delivery->state) {
                    DeliveryState::Failed => DeliveryOutcome::Failed,
                    DeliveryState::Logged => DeliveryOutcome::Logged,
                    DeliveryState::Pending => DeliveryOutcome::Pending,
                    DeliveryState::Sending => DeliveryOutcome::Sending,
                },
            };

            return new IncidentDeliveryData($address, $event->type, $outcome,
                $diagnosis, $delivery->sent_at?->toIso8601String(), $delivery->next_attempt_at->toIso8601String(),
                $event->id, $delivery->id, $delivery->digest,
                $previewId !== null && (int) $previewId === $delivery->id, $this->deliveryEventLabel($event),
                ($delivery->sent_at ?? $event->occurred_at)->format('Y-m-d\TH:i:s.uP'),
                $delivery->sent_at === null ? null : (int) $event->occurred_at->diffInSeconds($delivery->sent_at, false),
                max(0, $delivery->attempts - 1));
        })->filter()->values()->all();

        return array_values($deliveries);
    }

    /**
     * Every channel type counts in the delivery history (design §6). Mail shows its address to Owners, Admins and the
     * recipient; other channels are named by their label; Members see other people's addresses hidden.
     */
    private function recipientName(User $user, NotificationDestination $destination): string
    {
        if (! $destination->identityVisibleTo($user)) {
            $hidden = __('notifications.delivery_hidden_address');

            return is_string($hidden) ? $hidden : throw new \LogicException('Translations are text.');
        }

        return $destination->channel === 'mail' ? $destination->destinationConfig()->string('email') : $destination->label;
    }

    /** A translation key for timeline events; any other event (e.g. a missed scheduled task) is named by its presented message. */
    private function deliveryEventLabel(Event $event): string
    {
        if (! in_array($event->type, self::TimelineEventTypes, true)) {
            // A label inside "label · state", not a sentence.
            return rtrim($this->eventPresenter->present($event)->message, '.');
        }

        return $event->type === 'incident.still_down'
            ? ($event->cause === IncidentCause::Errored ? 'incident.still_not_checkable'
                : ($this->notificationTypes->key($event) === 'check.failing' ? 'incident.still_failing' : $event->type))
            : $event->type;
    }
}
