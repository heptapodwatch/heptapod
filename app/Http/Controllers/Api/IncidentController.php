<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Api;

use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Group;
use Heptapod\Checks\CheckRunPresentations;
use Heptapod\Concerns\ResolvesApiPrincipal;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\FindingsQueryProvider;
use Heptapod\Contracts\RunReference;
use Heptapod\Contracts\RunResult;
use Heptapod\Data\Ui\IncidentData;
use Heptapod\Enums\IncidentCause;
use Heptapod\Enums\SiteStatus;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Models\CheckRun;
use Heptapod\Models\Incident;
use Heptapod\Queries\IncidentsReader;
use Heptapod\Support\Api\ApiHeadline;
use Heptapod\Support\Api\ApiProblem;
use Heptapod\Support\Api\CursorCodec;
use Heptapod\Support\Api\TrustedSummary;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

#[Group('Incidents')]
final class IncidentController extends Controller
{
    use ResolvesApiPrincipal;

    private const int DefaultWindowDays = 30;

    // Public so Scramble can evaluate the timeline_limit rule for docs/openapi.json.
    public const int TimelineLimit = 200;

    public function index(Request $request, IncidentsReader $incidents, CursorCodec $cursors): JsonResponse
    {
        $user = $this->principal($request);
        $token = $this->token($user);
        /** @var array{state?: 'open'|'resolved', site_id?: int|string, client_id?: int|string, check_id?: int|string, since?: string, limit?: int|string, cursor?: string, locale?: string} $input */
        $input = Validator::make($request->query(), ['locale' => ApiHeadline::LocaleRule, 'state' => ['sometimes', 'in:open,resolved'], 'site_id' => ['sometimes', 'integer', 'min:1'],
            'client_id' => ['sometimes', 'integer', 'min:1'], 'check_id' => ['sometimes', 'integer', 'min:1'],
            'since' => ['sometimes', 'date', 'regex:/(Z|[+-]\d{2}:\d{2})$/'], 'limit' => ['sometimes', 'integer', 'min:1', 'max:100'], 'cursor' => ['sometimes', 'string']])->validate();
        ApiHeadline::applyLocale($input['locale'] ?? null);
        $limit = (int) ($input['limit'] ?? 50);
        $filters = ['state' => $input['state'] ?? null, 'site_id' => isset($input['site_id']) ? (int) $input['site_id'] : null,
            'client_id' => isset($input['client_id']) ? (int) $input['client_id'] : null, 'check_id' => isset($input['check_id']) ? (int) $input['check_id'] : null,
            'since' => isset($input['since']) ? CarbonImmutable::parse($input['since'])->utc()->format('Y-m-d\TH:i:s.u\Z') : null];
        $binding = $cursors->binding($user, $token, [$filters, $limit]);
        $state = isset($input['cursor']) ? $cursors->decode($input['cursor'], 'incidents', $binding) : null;
        // The default window is fixed by the first page so later pages read the same range.
        $windowStart = is_string($state['window_start'] ?? null) ? $state['window_start']
            : ($filters['since'] ?? CarbonImmutable::now('UTC')->subDays(self::DefaultWindowDays)->format('Y-m-d\TH:i:s.u\Z'));

        $query = $incidents->scoped($user, includeArchived: true);
        $base = $query->getQuery();
        match ($filters['state']) {
            'open' => $base->whereNull('incidents.resolved_at'),
            'resolved' => $base->whereNotNull('incidents.resolved_at'),
            null => null,
        };
        if ($filters['since'] !== null || $filters['state'] === 'resolved') {
            $base->where('incidents.first_failure_at', '>=', $windowStart);
        } else {
            $base->where(fn (QueryBuilder $open): QueryBuilder => $open->whereNull('incidents.resolved_at')->orWhere('incidents.first_failure_at', '>=', $windowStart));
        }
        if ($filters['site_id'] !== null) {
            $base->where('incidents.site_id', $filters['site_id']);
        }
        if ($filters['check_id'] !== null) {
            $base->where('incidents.check_id', $filters['check_id']);
        }
        if ($filters['client_id'] !== null) {
            $base->whereIn('incidents.site_id', fn (QueryBuilder $sites): QueryBuilder => $sites->select('id')->from('sites')->where('client_id', $filters['client_id']));
        }
        if ($state !== null) {
            $after = $state['after'] ?? null;
            if (! is_array($after) || ! is_string($after['opened_at'] ?? null) || ! is_int($after['id'] ?? null)) {
                throw new \LogicException('Incident cursor position is invalid.');
            }
            $base->whereRaw('(incidents.opened_at, incidents.id) < (?::timestamptz, ?)', [$after['opened_at'], $after['id']]);
        }
        $base->orderByDesc('incidents.opened_at')->orderByDesc('incidents.id')->limit($limit + 1);
        $rows = $query->with('site.client')->get();
        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit)->values();
        $last = $page->last();
        $next = $hasMore && $last instanceof Incident ? $cursors->encode('incidents', $binding, ['window_start' => $windowStart,
            'after' => ['opened_at' => $last->opened_at->format('Y-m-d\TH:i:s.uP'), 'id' => $last->id]]) : null;

        $presented = $incidents->presentedRows($page);

        return response()->json(['data' => $page->map(fn (Incident $incident, int $index): array => $this->row($incident, $presented[$index]))->all(),
            'effective_filters' => [...$filters, 'window_start' => $windowStart,
                'window' => $filters['since'] !== null || $filters['state'] === 'resolved' ? 'first_failure_since' : 'open_or_first_failure_since'],
            'order' => 'opened_at_desc', 'has_more' => $hasMore, 'next_cursor' => $next], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function show(Request $request, int $incident, IncidentsReader $incidents, CheckRegistry $registry, CursorCodec $cursors, CheckRunPresentations $presentations): JsonResponse
    {
        $user = $this->principal($request);
        $token = $this->token($user);
        /** @var array{include_note?: string, timeline_limit?: int|string, timeline_cursor?: string, locale?: string} $input */
        $input = Validator::make($request->query(), ['locale' => ApiHeadline::LocaleRule, 'include_note' => ['sometimes', 'in:true,false,1,0'],
            'timeline_limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::TimelineLimit], 'timeline_cursor' => ['sometimes', 'string']])->validate();
        ApiHeadline::applyLocale($input['locale'] ?? null);
        $includeNote = in_array($input['include_note'] ?? 'false', ['true', '1'], true);
        $timelineLimit = (int) ($input['timeline_limit'] ?? self::TimelineLimit);
        $model = $incidents->scoped($user, includeArchived: true)->with(['noteAuthor', 'site.client'])->find($incident);
        abort_unless($model instanceof Incident && Gate::forUser($user)->allows('view', $model->check), 404);
        $check = $model->check ?? throw new \LogicException('Incident check missing.');
        $opening = $model->evidence;
        // (id, ended_at) is the physical run identity; the id alone is not unique across partitions.
        $current = $check->latest_run_id === null || $check->latest_run_ended_at === null ? null
            : CheckRun::forRun(new RunReference($check->latest_run_id, $check->latest_run_ended_at))->where('check_id', $check->id)
                ->first();
        $binding = $cursors->binding($user, $token, [$model->id, $includeNote, $timelineLimit]);
        $timelineState = isset($input['timeline_cursor']) ? $cursors->decode($input['timeline_cursor'], 'incident_timeline', $binding) : null;
        if ($timelineState !== null && (! is_string($timelineState['as_of'] ?? null) || ! is_string($timelineState['after_at'] ?? null) || ! is_string($timelineState['after_key'] ?? null)
            || ! is_string($timelineState['prefix_sha256'] ?? null))) {
            throw new \LogicException('Incident timeline cursor state is invalid.');
        }
        // The first page fixes as_of, so later pages page through the same collapsed timeline.
        $asOf = $timelineState === null ? CarbonImmutable::now('UTC') : CarbonImmutable::parse($timelineState['as_of']);
        $findings = $registry->get($check->type)->persistence() instanceof FindingsQueryProvider
            ? ['opening' => ['incident_id' => $model->id, 'snapshot' => 'opening'], 'current' => ['incident_id' => $model->id, 'snapshot' => 'current']] : null;
        /** @var list<array{key: string, time: string, item: array<string, mixed>}> $entries */
        $entries = [];
        // The reader cuts events and the note at as_of before collapsing, so later reminders cannot regroup the read prefix.
        foreach ($incidents->timeline($user, $model, $asOf) as $entry) {
            if ($entry->notification_group !== null) {
                $group = $entry->notification_group;
                $item = ['kind' => 'notification_group', 'at' => $entry->time, 'last_at' => $group->last_at, 'event_type' => $group->event_type,
                    'state' => $group->state->value, 'count' => $group->count, 'recipient_count' => $group->recipient_count, 'accepted_count' => $group->accepted_count, 'logged_count' => $group->logged_count,
                    'failed_count' => $group->failed_count, 'suppressed_count' => $group->suppressed_count, 'pending_count' => $group->pending_count,
                    'unrecorded_count' => $group->unrecorded_count, 'unrecorded_reasons' => $group->unrecorded_reasons];
            } elseif ($entry->event !== null) {
                $item = ['kind' => 'event', 'at' => $entry->time, 'event_ref' => 'event:'.$entry->event->id, 'event_type' => $entry->event->type];
            } else {
                $item = ['kind' => 'note', 'at' => $entry->time, 'note' => $includeNote ? $entry->note : null];
            }
            $entries[] = ['key' => $entry->id, 'time' => $entry->time, 'item' => $item];
        }
        // Fingerprint of the collapsed prefix through an entry: regrouping or removal before the cursor changes it.
        $prefixHash = static fn (int $through): string => hash('sha256', json_encode(array_slice($entries, 0, $through + 1), JSON_THROW_ON_ERROR));
        $start = 0;
        if ($timelineState !== null) {
            $anchor = null;
            foreach ($entries as $index => $candidate) {
                if ($candidate['key'] === $timelineState['after_key'] && $candidate['time'] === $timelineState['after_at']) {
                    $anchor = $index;
                    break;
                }
            }
            // Fail loud: never advance past a vanished anchor or a changed prefix; the client restarts from page 1.
            if ($anchor === null || ! hash_equals($timelineState['prefix_sha256'], $prefixHash($anchor))) {
                throw new ApiProblem('cursor_stale', 409, ['restart' => 'Request the incident again without timeline_cursor.']);
            }
            $start = $anchor + 1;
        }
        $page = array_slice($entries, $start, $timelineLimit);
        $timeline = array_column($page, 'item');
        $total = count($entries);
        $hasMore = $start + count($page) < $total;
        $lastIndex = $start + count($page) - 1;
        $last = $page === [] ? null : $entries[$lastIndex];

        $legacy = $this->legacyHeadline($model);

        return response()->json(['data' => [...$this->row($model, $incidents->presentedRows(collect([$model]))[0]),
            'opening_evidence' => ['run_ref' => is_int($opening['run_id'] ?? null) ? ['id' => $opening['run_id'], 'endedAt' => $opening['run_ended_at'] ?? null] : null,
                // The retained opening run is a check error exactly when it kept an error (as CheckRunPresentations::atOpening reads it).
                ...ApiHeadline::project($presentations->atOpening($model), $legacy, $check->type, ($opening['error'] ?? null) !== null),
                'summary' => TrustedSummary::project(is_string($opening['summary'] ?? null) ? $opening['summary'] : null, $opening['summary_params'] ?? [])],
            'current_evidence' => $current instanceof CheckRun ? ['run_ref' => $current->reference()->toArray(), 'result' => $current->result->value,
                ...ApiHeadline::project($presentations->forRun($check, $current), $legacy, $check->type, $current->result === RunResult::Errored),
                'summary' => TrustedSummary::project($current->summary, $current->summary_params ?? [])] : null,
            'findings_selectors' => $findings,
            'note' => $includeNote ? ['text' => $model->notes, 'author_name' => $model->noteAuthor?->name, 'updated_at' => $model->note_updated_at?->toIso8601ZuluString('microsecond')]
                : ['included' => false, 'present' => $model->notes !== null],
            'timeline' => $timeline, 'timeline_total' => $total, 'timeline_as_of' => $asOf->toIso8601ZuluString('microsecond'),
            'timeline_has_more' => $hasMore, 'timeline_next_cursor' => $hasMore && $last !== null ? $cursors->encode('incident_timeline', $binding,
                ['as_of' => $asOf->format('Y-m-d\TH:i:s.uP'), 'after_at' => $last['time'], 'after_key' => $last['key'], 'prefix_sha256' => $prefixHash($lastIndex)]) : null]],
            200, ['Cache-Control' => 'private, no-store']);
    }

    /** @return array<string, mixed> */
    private function row(Incident $incident, IncidentData $presented): array
    {
        if ($presented->id !== $incident->id) {
            throw new \LogicException('Presented incident row does not match.');
        }
        $site = $incident->site ?? throw new \LogicException('Incident site missing.');
        $check = $incident->check ?? throw new \LogicException('Incident check missing.');
        $end = $incident->resolved_at ?? CarbonImmutable::now('UTC');

        return ['incident_ref' => 'incident:'.$incident->id, 'check_ref' => 'check:'.$check->id, 'site_ref' => 'site:'.$site->id, 'client_ref' => 'client:'.$site->client_id,
            'client_name' => ($site->client ?? throw new \LogicException('Incident client missing.'))->name,
            'check_type' => $check->type->value, 'state' => $incident->resolved_at === null ? 'open' : 'resolved', 'cause' => $incident->cause->value,
            ...$this->headline($incident, $presented),
            'first_failure_at' => $incident->first_failure_at->toIso8601ZuluString('microsecond'), 'opened_at' => $incident->opened_at->toIso8601ZuluString('microsecond'),
            'resolved_at' => $incident->resolved_at?->toIso8601ZuluString('microsecond'), 'duration_seconds' => (int) $incident->first_failure_at->diffInSeconds($end, true),
            'snoozed_until' => $incident->snoozed_until?->toIso8601ZuluString('microsecond'), 'site_url' => $site->url];
    }

    /**
     * The app's incident headline: an open uptime-dependent incident names the outage instead of its own reason.
     *
     * @return array{headline: string, headline_status: 'presented'|'legacy_generic'|'uptime_dependency', headline_parts: array<string, mixed>|null, headline_data: object, display_headline: string|null, display_headline_untrusted: bool}
     */
    private function headline(Incident $incident, IncidentData $presented): array
    {
        if ($presented->dependency_headline_key !== null) {
            $text = __($presented->dependency_headline_key);
            if (! is_string($text)) {
                throw new \LogicException('Uptime dependency headline must be text.');
            }

            return ['headline' => $text, 'headline_status' => 'uptime_dependency', 'headline_parts' => null, 'headline_data' => (object) [],
                'display_headline' => $text, 'display_headline_untrusted' => false];
        }

        $check = $incident->check ?? throw new \LogicException('Incident check missing.');

        return ApiHeadline::project($presented->presentation, $this->legacyHeadline($incident), $check->type, $incident->cause === IncidentCause::Errored);
    }

    /** Generic label for retained incidents whose check type has no presenter. */
    private function legacyHeadline(Incident $incident): string
    {
        $check = $incident->check ?? throw new \LogicException('Incident check missing.');

        // Controlled, untranslated API text: the glossary key itself.
        return $check->type->value.': '.SiteStatus::forIncident($check->type, $incident->cause)->labelKey();
    }
}
