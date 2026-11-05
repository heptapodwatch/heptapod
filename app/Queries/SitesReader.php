<?php

declare(strict_types=1);

namespace Heptapod\Queries;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Heptapod\Checks\CauseSummary;
use Heptapod\Checks\CheckFreshness;
use Heptapod\Checks\CheckRunPresentations;
use Heptapod\Checks\FreshnessSnapshot;
use Heptapod\Checks\History\ListResponseReader;
use Heptapod\Checks\NoResultFreshness;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\CheckRunPresentationData;
use Heptapod\Contracts\CheckRunPresentationInput;
use Heptapod\Contracts\RunPhase;
use Heptapod\Data\Ui\CheckRunStateData;
use Heptapod\Data\Ui\CheckStripData;
use Heptapod\Data\Ui\ClientData;
use Heptapod\Data\Ui\ResponsePointData;
use Heptapod\Data\Ui\SiteIdentityData;
use Heptapod\Data\Ui\SiteListSummaryData;
use Heptapod\Data\Ui\SiteRowData;
use Heptapod\Data\Ui\SitesFilterData;
use Heptapod\Enums\BucketStatus;
use Heptapod\Enums\FreshnessSuppression;
use Heptapod\Enums\SiteIconBacking;
use Heptapod\Enums\SiteStatus;
use Heptapod\Incidents\SiteState;
use Heptapod\Incidents\UptimeDependencies;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\CheckRunAttempt;
use Heptapod\Models\Client;
use Heptapod\Models\Incident;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Policies\SitePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\Tags\Tag;

/**
 * @phpstan-type ResponsePointProjection array{time: string, value: float|null, failure_count: int, error_count: int, warning_count: int, failure_status: value-of<BucketStatus>|null}
 * @phpstan-type SiteIdentityProjection array{id: int, name: string, url: string, client_id: int, state: value-of<SiteStatus>, icon_url: string|null, icon_backing: value-of<SiteIconBacking>}
 * @phpstan-type CheckRunProjection array{check_id: int, phase: value-of<RunPhase>, attempt_id: int|null, queue: string|null, queue_position: int|null, progress_n: int|null, progress_total: int|null, phase_label: string|null, started_at: string|null, actor: string|null, due_at: string|null, can_cancel: bool, requested_at: string|null}
 * @phpstan-type CheckStripProjection array{pending_recheck: bool, freshness_suppressed_by: value-of<FreshnessSuppression>|null, freshness_suppression_label_key: string|null, dependency_headline_key: string|null, not_fresh: bool, state: value-of<SiteStatus>, id: int, key: value-of<CheckKey>, label_key: string, icon_id: string, enabled: bool, result: value-of<CheckResult>, open_incident_id: int|null, open_incident_started_at: string|null, summary: string|null, summary_params: array<string, string|int|float|bool|null>, checked_at: string|null, snoozed_until: string|null, meta_label: string|null, meta: string|null, unavailable_reason: string|null, run: CheckRunProjection|null, open_incident_requires_complete_recovery: bool, uptime_parent_incident_id: int|null, affected_checks: list<string>, uptime_recheck_after: string|null, held_after_recovery: bool, next_run_at: string|null, presentation?: array<array-key, mixed>|null}
 * @phpstan-type SiteRowProjection array{id: int, name: string, url: string, client_id: int, tags: list<string>, state: value-of<SiteStatus>, uptime: float|null, response: list<ResponsePointProjection>, checks: list<CheckStripProjection>, checked_at: string|null, identity: SiteIdentityProjection}
 */
final readonly class SitesReader
{
    public function __construct(private SitePolicy $policy, private SiteState $states, private ListResponseReader $history, private UptimeReader $uptime, private CauseSummary $summaries, private CheckRegistry $registry, private CheckRunsReader $checkRuns, private CheckRunPresentations $presentations, private CheckFreshness $freshness, private NoResultFreshness $noResultFreshness) {}

    /** @return Builder<Site> */
    public function projected(User $user, bool $includeArchived = false): Builder
    {
        return $this->project($this->policy->visibleSites($user, $includeArchived));
    }

    /** @return list<int> */
    public function visibleSiteIds(User $user): array
    {
        return $this->policy->visibleSiteIds($user);
    }

    /** @return list<int> */
    public function activeVisibleSiteIds(User $user): array
    {
        return $this->policy->activeVisibleSiteIds($user);
    }

    /**
     * Materialize authorized site identifiers before projecting status to avoid a nested client-membership join per site with stale statistics.
     *
     * @return Builder<Site>
     */
    public function projectedForSearch(User $user): Builder
    {
        $sites = Site::query();
        $sites->getQuery()->whereIntegerInRaw('sites.id', $this->policy->visibleSiteIds($user));

        return $this->project($sites);
    }

    /** @param Builder<Site> $sites
     * @return Builder<Site>
     */
    private function project(Builder $sites): Builder
    {
        $projected = $this->states->annotate($sites);
        $projected->getQuery()->selectRaw(Site::displayNameSql().' AS name');
        $query = Site::query();
        $query->getQuery()->fromSub($projected, 'sites');

        return $query;
    }

    public function identity(Site $site): SiteIdentityData
    {
        $name = $site->getAttribute('name');
        $state = $site->getAttribute('state');
        if (! is_string($name) || ! is_string($state)) {
            throw new \LogicException('Site projection must contain its name and state.');
        }

        return new SiteIdentityData($site->id, $name, $site->url, $site->client_id, SiteStatus::from($state), $site->iconUrl(), $site->icon_backing);
    }

    /** @param Builder<Site> $query */
    public function orderByStatus(Builder $query, bool $descending = false): void
    {
        $rank = 'CASE state';
        $bindings = [];
        foreach (SiteStatus::cases() as $status) {
            $rank .= ' WHEN ? THEN ?';
            $bindings[] = $status->value;
            $bindings[] = $status->severity();
        }
        $query->getQuery()->orderByRaw($rank.' END'.($descending ? ' DESC' : ' ASC'), $bindings);
        $this->orderByName($query, false);
    }

    /** @return 'de-x-icu'|'en-x-icu' */
    public static function collation(): string
    {
        return app()->getLocale() === 'de' ? 'de-x-icu' : 'en-x-icu';
    }

    /** @param Builder<Site> $query */
    private function orderByName(Builder $query, bool $descending): void
    {
        $collation = self::collation();
        $query->getQuery()->orderByRaw('name COLLATE "'.$collation.'" '.($descending ? 'DESC' : 'ASC'));
    }

    /** @param Builder<Site> $query
     * @return Builder<Site>
     */
    private function attentionQuery(Builder $query): Builder
    {
        $query->getQuery()->whereIn('state', SiteStatus::attention());
        $this->orderByStatus($query);
        // The Sites band shows five rows; "Show all" opens the full filtered list.
        $query->getQuery()->orderBy('sites.id')->limit(5);

        return $query;
    }

    /** @return array{rows: list<SiteRowProjection>, total: int} */
    public function attention(User $user): array
    {
        $attention = $this->attentionQuery($this->projected($user));
        $total = (clone $attention)->getQuery()->count();

        return ['rows' => $this->rowPayloads($attention->get(), includeHistory: false, includePresentations: true), 'total' => $total];
    }

    /** @return LengthAwarePaginator<int, Site> */
    public function page(User $user, Request $request): LengthAwarePaginator
    {
        return $this->pageQuery($this->projected($user), $request)->paginate(50)->withQueryString();
    }

    /** @param Builder<Site> $query
     * @return QueryBuilder<Site>
     */
    private function pageQuery(Builder $query, Request $request): QueryBuilder
    {
        $sortName = $this->orderByName(...);
        $sortStatus = $this->orderByStatus(...);
        $builder = QueryBuilder::for($query, $request)
            ->allowedFilters(
                AllowedFilter::partial('search', 'name'),
                AllowedFilter::exact('client_id'),
                AllowedFilter::exact('state'),
                AllowedFilter::callback('attention', static function (Builder $query, mixed $value): void {
                    if (filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
                        $query->getQuery()->whereIn('state', SiteStatus::attention());
                    }
                }),
                AllowedFilter::callback('check', static function (Builder $query, mixed $value): void {
                    if (! is_string($value)) {
                        throw new \LogicException('Check filter must be validated as a string.');
                    }
                    $query->whereHas('checks', static function (Builder $checks) use ($value): void {
                        $checks->where('type', $value)->where('enabled', true);
                    });
                }),
                AllowedFilter::callback('tag', static function (Builder $query, mixed $value): void {
                    if (! is_string($value)) {
                        throw new \LogicException('Tag filter must be validated as a string.');
                    }
                    $query->whereHas('tags', static function (Builder $tags) use ($value): void {
                        $tags->getQuery()->where('name->en', $value);
                    });
                }),
            )
            ->allowedSorts(AllowedSort::callback('status', $sortStatus), AllowedSort::callback('name', $sortName), 'url')
            ->defaultSort(AllowedSort::callback('status', $sortStatus));
        $builder->getEloquentBuilder()->getQuery()->orderBy('sites.id');

        return $builder;
    }

    /**
     * The initial response needs pagination, summary, tiles and attention together. A request-local
     * materialized projection keeps their status reads consistent without evaluating each site's state five times.
     * Pagination needs only the total here; the deferred list reads its page in a separate partial request.
     *
     * @return array{page: LengthAwarePaginator<int, Site>, attention: EloquentCollection<int, Site>, summary: SiteListSummaryData, states: array<string, int>}
     */
    public function index(User $user, Request $request): array
    {
        $siteColumns = ['sites.id', 'sites.client_id', 'sites.url', 'sites.friendly_name', 'sites.icon_hash', 'sites.icon_backing', 'sites.crawler_headers', 'sites.name', 'sites.state'];
        $visible = $this->projected($user)->getQuery()->select($siteColumns);
        $visibleSites = Site::query();
        $visibleSites->getQuery()->from('visible as sites');
        $count = $this->pageQuery(clone $visibleSites, $request)->getEloquentBuilder()->getQuery()->reorder();
        $currentPage = LengthAwarePaginator::resolveCurrentPage();
        $attention = $this->attentionQuery(clone $visibleSites)->getQuery()->select($siteColumns);
        $states = [];
        foreach (SiteStatus::cases() as $status) {
            $states[] = "count(*) FILTER (WHERE state = '".$status->value."')::int AS \"".$status->value.'"';
        }
        $attentionRank = SiteStatus::rankSql('attention.state');
        $collation = self::collation();
        $stateSql = implode(', ', $states);
        /** @var object{total: int, filtered_total: int, attention: string} $row */
        $row = DB::selectOne(<<<SQL
            WITH visible AS MATERIALIZED ({$visible->toSql()})
            SELECT count(*)::int AS total, {$stateSql},
                (SELECT count(*)::int FROM ({$count->toSql()}) matched) AS filtered_total,
                (SELECT coalesce(json_agg(attention ORDER BY {$attentionRank}, attention.name COLLATE "{$collation}", attention.id), '[]'::json)
                    FROM ({$attention->toSql()}) attention) AS attention
            FROM visible
            SQL, [...$visible->getBindings(), ...$count->getBindings(), ...$attention->getBindings()])
            ?? throw new \LogicException('Sites index projection must return one row.');
        /** @var list<array<string, mixed>> $attentionRows */
        $attentionRows = json_decode($row->attention, true, flags: JSON_THROW_ON_ERROR);
        /** @var array<string, int> $counts */
        $counts = array_diff_key((array) $row, array_flip(['filtered_total', 'attention']));
        $attentionTotal = array_sum(array_map(static fn (SiteStatus $status): int => $counts[$status->value], SiteStatus::attention()));
        // These are partial Site projections; encrypted crawler headers retain their database representation.
        $attentionSites = Site::hydrate($attentionRows);
        $paginator = new LengthAwarePaginator(new EloquentCollection, $row->filtered_total, 50, $currentPage, [
            'path' => LengthAwarePaginator::resolveCurrentPath(),
        ]);

        return ['page' => $paginator->withQueryString(), 'attention' => $attentionSites,
            'summary' => new SiteListSummaryData($row->total, $attentionTotal, $counts['paused']), 'states' => $counts];
    }

    public function summary(User $user): SiteListSummaryData
    {
        /** @var \stdClass&object{total: int, attention: int, paused: int} $summary */
        $summary = DB::query()->fromSub($this->projected($user), 'visible_sites')
            ->selectRaw('count(*)::int AS total, count(*) FILTER (WHERE '.SiteStatus::attentionSql('state').")::int AS attention, count(*) FILTER (WHERE state = 'paused')::int AS paused")
            ->first() ?? throw new \LogicException('Site summary query must return one row.');

        return new SiteListSummaryData($summary->total, $summary->attention, $summary->paused);
    }

    public function filters(Request $request): SitesFilterData
    {
        return new SitesFilterData($request->filled('filter.search') ? $request->string('filter.search')->toString() : null,
            $request->filled('filter.client_id') ? $request->integer('filter.client_id') : null,
            $request->filled('filter.state') ? SiteStatus::from($request->string('filter.state')->toString()) : null,
            $request->filled('filter.tag') ? $request->string('filter.tag')->toString() : null,
            $request->string('sort', 'status')->toString(),
            $request->filled('filter.check') ? CheckKey::from($request->string('filter.check')->toString()) : null,
            $request->boolean('filter.attention'));
    }

    /**
     * @param  Collection<int, Site>  $sites  Sites from projected(), never an unscoped model collection.
     * @return list<SiteRowData>
     */
    public function rows(Collection $sites, bool $includeHistory = true, bool $includePresentations = false): array
    {
        return array_map(static function (array $row): SiteRowData {
            $row['response'] = array_map(static function (array $point): ResponsePointData {
                $point['failure_status'] = $point['failure_status'] === null ? null : BucketStatus::from($point['failure_status']);

                return new ResponsePointData(...$point);
            }, $row['response']);
            $row['checks'] = array_map(static function (array $check): CheckStripData {
                unset($check['state'], $check['freshness_suppression_label_key'], $check['dependency_headline_key']);
                $check['freshness_suppressed_by'] = $check['freshness_suppressed_by'] === null ? null : FreshnessSuppression::from($check['freshness_suppressed_by']);
                $check['key'] = CheckKey::from($check['key']);
                $check['result'] = CheckResult::from($check['result']);
                if ($check['run'] !== null) {
                    $run = $check['run'];
                    $run['phase'] = RunPhase::from($run['phase']);
                    $check['run'] = new CheckRunStateData(...$run);
                }

                $presentation = $check['presentation'] ?? null;
                $check['presentation'] = $presentation === null ? null : CheckRunPresentationData::from($presentation);

                return new CheckStripData(...$check);
            }, $row['checks']);
            $row['state'] = SiteStatus::from($row['state']);
            $identity = $row['identity'];
            $identity['state'] = SiteStatus::from($identity['state']);
            $identity['icon_backing'] = SiteIconBacking::from($identity['icon_backing']);
            $row['identity'] = new SiteIdentityData(...$identity);

            return new SiteRowData(...$row);
        }, $this->rowPayloads($sites, $includeHistory, $includePresentations));
    }

    /**
     * The list emits native arrays to avoid transforming thousands of point data objects. Other
     * readers hydrate their data objects from this same projection so field semantics cannot split.
     *
     * @param  Collection<int, Site>  $sites  Sites from projected(), never an unscoped model collection.
     * @return list<SiteRowProjection>
     */
    public function rowPayloads(Collection $sites, bool $includeHistory = true, bool $includePresentations = false): array
    {
        $sites = new EloquentCollection($sites->all());
        $sites->load(['tags', 'checks.snoozes' => function (Relation $relation): void {
            $relation->getQuery()->getQuery()->whereNull('component_type')->whereNull('unsnoozed_at')->where('starts_at', '<=', now())->where('ends_at', '>', now());
        }, 'checks.incidents' => function (Relation $relation): void {
            $relation->getQuery()->getQuery()->whereNull('resolved_at');
        }]);
        $at = CarbonImmutable::now('UTC');
        $notFresh = new EloquentCollection;
        foreach ($sites as $site) {
            foreach ($site->checks as $check) {
                $check->setRelation('site', $site);
                if ($this->freshness->state($check, $at)->state === 'not_fresh') {
                    $notFresh->push($check);
                }
            }
        }

        return $this->projectRows($sites, $includeHistory, $includePresentations, $this->noResultFreshness->snapshot($notFresh, $at));
    }

    /**
     * @param  EloquentCollection<int, Site>  $sites
     * @return list<SiteRowProjection>
     */
    private function projectRows(EloquentCollection $sites, bool $includeHistory, bool $includePresentations, FreshnessSnapshot $freshness): array
    {
        $at = $freshness->at;
        $incidentIds = [];
        foreach ($sites as $site) {
            foreach ($site->checks as $check) {
                foreach ($check->incidents as $incident) {
                    $incidentIds[] = $incident->id;
                }
            }
        }
        $held = UptimeDependencies::heldAfterRecovery($incidentIds);
        // Only current claim tokens from this already-scoped page can hydrate active attempts.
        $claimTokens = $sites->flatMap(static fn (Site $site) => $site->checks)->pluck('claim_token')->filter()->all();
        $attempts = CheckRunAttempt::query()->with('actor');
        $attempts->getQuery()->whereIn('claim_token', $claimTokens)->whereIn('phase', ['queued', 'running']);
        $active = $attempts->get()->keyBy('check_id');
        $queuedIds = $active->where('phase', RunPhase::Queued)->modelKeys();
        /** @var array<int, int> $positions */
        $positions = $queuedIds === [] ? [] : DB::query()->fromSub(
            DB::table('check_run_attempts')->where('phase', 'queued')
                ->selectRaw('id, row_number() over (partition by queue order by id)::int as position'),
            'queued',
        )->whereIn('id', $queuedIds)->pluck('position', 'id')->all();
        $uptimeIds = [];
        foreach ($sites as $site) {
            $check = $site->checks->firstWhere('type', CheckKey::Uptime);
            if ($check instanceof Check) {
                $uptimeIds[$site->id] = $check->id;
            }
        }
        $until = CarbonImmutable::now('UTC');
        // The attention band only renders identities and check summaries, not charts or uptime.
        $samples = $includeHistory ? $this->history->read(array_values($uptimeIds), $until->subDay(), $until) : [];
        $uptimePercentages = $includeHistory ? $this->uptime->percentages(array_values($uptimeIds), $until->subDays(30), $until) : [];
        $runQuery = CheckRun::query();
        $runQuery->getQuery()->join('checks', function (JoinClause $join): void {
            $join->on('checks.latest_run_id', '=', 'check_runs.id')->on('checks.latest_run_ended_at', '=', 'check_runs.ended_at')->on('checks.id', '=', 'check_runs.check_id');
        })->whereIn('checks.site_id', $sites->modelKeys())->select('check_runs.*');
        $runs = $runQuery->get()->keyBy('check_id');
        $rows = [];
        $registeredKeys = $this->registry->keys();
        $checkMetadata = [];
        foreach ($sites as $site) {
            $series = $includeHistory && isset($uptimeIds[$site->id]) ? $samples[$uptimeIds[$site->id]] : [];
            $response = [];
            foreach ($series as $bucket) {
                $response[] = ['time' => $bucket['bucket_start'], 'value' => $bucket['maximum_ms'],
                    'failure_count' => $bucket['failed_count'], 'error_count' => $bucket['errored_count'], 'warning_count' => $bucket['warning_count'],
                    'failure_status' => $bucket['failure_status']?->value];
            }
            $strip = [];
            $checkedAt = null;
            $checksById = $site->checks->keyBy('id');
            $openIncidents = $site->checks->flatMap(static fn (Check $check) => $check->incidents);
            foreach ($openIncidents as $openIncident) {
                $openIncident->setRelation('check', $checksById->get($openIncident->check_id));
            }
            foreach ($site->checks as $check) {
                $key = $check->type;
                $checkId = $check->id;
                $check->setRelation('site', $site);
                // Native dates avoid Carbon's repeated cast/UTC conversion work on every strip.
                /** @var string|null $rawEndedAt */
                $rawEndedAt = $check->getRawOriginal('latest_run_ended_at');
                $endedAt = $rawEndedAt === null ? null : new DateTimeImmutable($rawEndedAt);
                if ($endedAt instanceof DateTimeImmutable && (! $checkedAt instanceof DateTimeImmutable || $endedAt > $checkedAt)) {
                    $checkedAt = $endedAt;
                }
                $incident = $check->incidents->first();
                $incident?->setRelation('site', $site);
                $grouped = UptimeDependencies::isGrouped($incident);
                $run = $runs->get($checkId);
                $summary = $this->summaries->project($check, $run);
                $presentation = null;
                if ($includePresentations) {
                    $presentation = $incident instanceof Incident
                        ? $this->presentations->forIncident($incident, $run, isset($held[$incident->id]))
                        : ($run instanceof CheckRun ? $this->presentations->forInput($check, new CheckRunPresentationInput(
                            $run->result, $run->evidence, $run->metrics,
                            $summary['key'] ?? throw new \LogicException('A retained run needs a summary.'), $summary['params'],
                        )) : null);
                }
                if (! isset($checkMetadata[$key->value])) {
                    $definition = in_array($key, $registeredKeys, true) ? $this->registry->get($key)->definition() : null;
                    $checkMetadata[$key->value] = [
                        'label' => $definition->labelKey ?? 'checks.'.$key->value.'.label',
                        'icon' => $definition->icon ?? 'circle-help',
                        'unavailable_reason' => $this->registry->availability($key)->reason,
                    ];
                }
                $metadata = $checkMetadata[$key->value];
                $notFresh = $this->freshness->state($check, $at)->state === 'not_fresh';
                /** @var string $nextRunAt */
                $nextRunAt = $check->getRawOriginal('next_run_at');
                // CheckStripData remains the authority for the derived check state.
                $data = new CheckStripData($checkId, $key, $metadata['label'], $metadata['icon'], $check->enabled, $check->latest_run_result,
                    $incident?->id, $incident?->first_failure_at->toIso8601String(), $summary['key'], $summary['params'],
                    $this->timestamp($endedAt), $this->timestamp($check->snoozes->first()?->ends_at),
                    unavailable_reason: $metadata['unavailable_reason'],
                    run: $active->has($checkId) ? $this->checkRuns->state($check, $active->get($checkId), $positions) : null,
                    open_incident_requires_complete_recovery: $incident?->requiresCompleteRecovery() ?? false,
                    uptime_parent_incident_id: $grouped ? UptimeDependencies::parentId($incident) : null,
                    affected_checks: $key === CheckKey::Uptime && $incident !== null
                        ? array_values($openIncidents->filter(static fn (Incident $dependent): bool => UptimeDependencies::isGrouped($dependent)
                            && UptimeDependencies::parentId($dependent) === $incident->id)
                            ->map(static fn (Incident $dependent): string => ($dependent->check ?? throw new \LogicException('Dependent check missing.'))->type->value)->all()) : [],
                    uptime_recheck_after: $grouped ? UptimeDependencies::recheckAfter($incident) : null,
                    held_after_recovery: isset($held[$incident->id ?? 0]),
                    next_run_at: $this->timestamp(new DateTimeImmutable($nextRunAt)),
                    presentation: $presentation,
                    not_fresh: $notFresh,
                    freshness_suppressed_by: $notFresh
                        ? $this->noResultFreshness->suppression($check, $at, $freshness)?->reason : null,
                    pending_recheck: $check->pending_reason !== null);
                $run = $data->run;
                $strip[] = [
                    'state' => $data->state->value,
                    'pending_recheck' => $data->pending_recheck,
                    'id' => $data->id, 'key' => $data->key->value, 'label_key' => $data->label_key, 'icon_id' => $data->icon_id,
                    'freshness_suppressed_by' => $data->freshness_suppressed_by?->value,
                    'freshness_suppression_label_key' => $data->freshness_suppression_label_key,
                    'dependency_headline_key' => $data->dependency_headline_key,
                    'not_fresh' => $data->not_fresh, 'enabled' => $data->enabled, 'result' => $data->result->value,
                    'open_incident_id' => $data->open_incident_id, 'open_incident_started_at' => $data->open_incident_started_at,
                    'summary' => $data->summary, 'summary_params' => $data->summary_params,
                    'checked_at' => $data->checked_at, 'snoozed_until' => $data->snoozed_until,
                    'meta_label' => $data->meta_label, 'meta' => $data->meta, 'unavailable_reason' => $data->unavailable_reason,
                    'run' => $run instanceof CheckRunStateData ? [
                        'check_id' => $run->check_id, 'phase' => $run->phase->value, 'attempt_id' => $run->attempt_id,
                        'queue' => $run->queue, 'queue_position' => $run->queue_position, 'progress_n' => $run->progress_n,
                        'progress_total' => $run->progress_total, 'phase_label' => $run->phase_label, 'started_at' => $run->started_at,
                        'actor' => $run->actor, 'due_at' => $run->due_at, 'can_cancel' => $run->can_cancel, 'requested_at' => $run->requested_at,
                    ] : null,
                    'open_incident_requires_complete_recovery' => $data->open_incident_requires_complete_recovery,
                    'uptime_parent_incident_id' => $data->uptime_parent_incident_id, 'affected_checks' => $data->affected_checks,
                    'uptime_recheck_after' => $data->uptime_recheck_after, 'held_after_recovery' => $data->held_after_recovery,
                    'next_run_at' => $data->next_run_at,
                    ...($includePresentations ? ['presentation' => $data->presentation?->toArray()] : []),
                ];
            }
            $tags = $site->tags->map(static function (Tag $tag): string {
                $name = $tag->getTranslation('name', 'en');
                if (! is_string($name)) {
                    throw new \LogicException('Canonical tag name must be a string.');
                }

                return $name;
            })->values()->all();
            $identity = $this->identity($site);
            $rows[] = [
                'id' => $identity->id, 'name' => $identity->name, 'url' => $identity->url, 'client_id' => $identity->client_id,
                'tags' => array_values($tags), 'state' => $identity->state->value,
                'uptime' => isset($uptimeIds[$site->id]) ? ($uptimePercentages[$uptimeIds[$site->id]] ?? null) : null,
                'response' => $response, 'checks' => $strip, 'checked_at' => $this->timestamp($checkedAt),
                'identity' => ['id' => $identity->id, 'name' => $identity->name, 'url' => $identity->url, 'client_id' => $identity->client_id,
                    'state' => $identity->state->value, 'icon_url' => $identity->icon_url, 'icon_backing' => $identity->icon_backing->value],
            ];
        }

        return $rows;
    }

    private function timestamp(?DateTimeImmutable $at): ?string
    {
        if (! $at instanceof DateTimeImmutable) {
            return null;
        }
        $year = (int) $at->format('Y');
        // Carbon uses signed, six-digit ISO years outside the native four-digit format.
        if ($year < 0 || $year > 9999) {
            return CarbonImmutable::instance($at)->toISOString();
        }

        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    /** @return Builder<Client> */
    public function scopedClients(User $user, bool $includeArchived = false): Builder
    {
        return $this->policy->visibleClients($user, $includeArchived);
    }

    /** @return list<int> */
    public function clientIds(User $user): array
    {
        $clients = Client::forBrowsing($this->scopedClients($user));
        /** @var list<int> $ids */
        $ids = $clients->getQuery()->pluck('clients.id')->all();

        return $ids;
    }

    /** @return list<ClientData> */
    public function clients(User $user): array
    {
        return $this->clientOptions(Client::forBrowsing($this->scopedClients($user)));
    }

    /** @return list<ClientData> */
    public function clientChoices(User $user): array
    {
        return $this->clientOptions($this->scopedClients($user));
    }

    /** @param Builder<Client> $clients
     * @return list<ClientData>
     */
    private function clientOptions(Builder $clients): array
    {
        $collation = self::collation();
        $clients->with(Client::AvatarRelations);
        $clients->getQuery()->orderByRaw('name COLLATE "'.$collation.'"')->orderBy('id');
        $options = $clients->get()->map(fn (Client $client): ClientData => ClientData::fromClient($client))->all();

        return array_values($options);
    }
}
