<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Api;

use Carbon\CarbonImmutable;
use Heptapod\Checks\CheckRunPresentations;
use Heptapod\Concerns\ResolvesApiPrincipal;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\CheckRunPresentationData;
use Heptapod\Contracts\CheckRunPresentationPartData;
use Heptapod\Contracts\EngineMessage;
use Heptapod\Contracts\SummaryKey;
use Heptapod\Enums\IncidentCause;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Models\Client;
use Heptapod\Models\Event;
use Heptapod\Queries\EventsReader;
use Heptapod\Support\Api\ApiHeadline;
use Heptapod\Support\Api\CursorCodec;
use Heptapod\Support\Api\TrustedSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class ActivityController extends Controller
{
    use ResolvesApiPrincipal;

    public function __invoke(Request $request, EventsReader $events, CursorCodec $cursors, CheckRunPresentations $presentations): JsonResponse
    {
        $user = $this->principal($request);
        $token = $this->token($user);
        /** @var array{days?: int|string, client_id?: int|string, limit?: int|string, cursor?: string, locale?: string} $input */
        $input = Validator::make($request->query(), ['locale' => ApiHeadline::LocaleRule, 'days' => ['sometimes', 'integer', 'min:1', 'max:90'], 'client_id' => ['sometimes', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'], 'cursor' => ['sometimes', 'string']])->validate();
        ApiHeadline::applyLocale($input['locale'] ?? null);
        $days = (int) ($input['days'] ?? 7);
        $limit = (int) ($input['limit'] ?? 50);
        $clientId = isset($input['client_id']) ? (int) $input['client_id'] : null;
        $binding = $cursors->binding($user, $token, [$days, $clientId, $limit]);
        $state = isset($input['cursor']) ? $cursors->decode($input['cursor'], 'activity', $binding) : null;
        if ($state !== null && (! is_string($state['since'] ?? null) || ! is_int($state['latest_id'] ?? null))) {
            throw new \LogicException('Activity cursor state is invalid.');
        }
        $since = $state === null ? CarbonImmutable::now('UTC')->subDays($days) : CarbonImmutable::parse($state['since']);
        $cursor = $state === null ? null : $events->activityCursor($user, $state['latest_id'], $since, null, $clientId, null);
        /** @var list<\stdClass&object{id: int, latest_id: int, check_id: int|null, ended_at: string, current_state: string|null, event_count: int, started_at: string, first_state: string|null, second_state: string|null}> $rows */
        $rows = $events->activityGroups($user, $since, null, $clientId, null, cursor: $cursor, limit: $limit + 1)->get()->all();
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $latest = $events->activityHeadEvents(array_map(static fn (object $row): int => $row->latest_id, $rows));
        $clientNames = Client::query()->whereKey($latest->pluck('client_id')->filter()->unique()->values()->all())->pluck('name', 'id');
        // The app's activity feed renders each group head through the same event presenter.
        $messages = [];
        foreach ($events->present($latest->values()) as $presented) {
            $messages[$presented->id] = $presented->message;
        }
        $data = [];
        foreach ($rows as $row) {
            $event = $latest->get($row->latest_id) ?? throw new \LogicException('Activity head event missing.');
            $payload = $event->payload;
            $data[] = ['group_ref' => 'activity:'.$row->id, 'latest_event_ref' => 'event:'.$event->id, 'event_type' => $event->type,
                'check_ref' => $row->check_id === null ? null : 'check:'.$row->check_id, 'site_ref' => $event->site_id === null ? null : 'site:'.$event->site_id,
                'client_ref' => $event->client_id === null ? null : 'client:'.$event->client_id,
                'client_name' => $event->client_id === null ? null : $clientNames[$event->client_id] ?? throw new \LogicException('Activity client missing.'), 'incident_ref' => $event->incident_id === null ? null : 'incident:'.$event->incident_id,
                'event_count' => $row->event_count, 'started_at' => CarbonImmutable::parse($row->started_at)->toIso8601ZuluString('microsecond'),
                'ended_at' => CarbonImmutable::parse($row->ended_at)->toIso8601ZuluString('microsecond'),
                'first_state' => $row->first_state, 'second_state' => $row->second_state, 'current_state' => $row->current_state,
                ...$this->headline($event, $messages[$event->id] ?? throw new \LogicException('Activity head event was not presented.'), $presentations->forEvent($event)),
                'summary' => TrustedSummary::project(is_string($payload['summary'] ?? null) ? $payload['summary'] : null, $payload['summary_params'] ?? [])];
        }
        $last = $rows === [] ? null : array_last($rows);
        $next = $hasMore && $last !== null ? $cursors->encode('activity', $binding, ['since' => $since->format('Y-m-d\TH:i:s.uP'), 'latest_id' => $last->latest_id]) : null;

        return response()->json(['data' => $data, 'range' => ['since' => $since->toIso8601ZuluString('microsecond')],
            'order' => 'ended_at_desc', 'consistency' => 'live_keyset', 'has_more' => $hasMore, 'next_cursor' => $next], 200, ['Cache-Control' => 'private, no-store']);
    }

    /**
     * `display_headline` is the app's activity message. `headline` is the presenter's controlled text when a presenter
     * describes the event, the message itself when it is built from catalog text only, and otherwise the event type.
     *
     * @return array<string, mixed>
     */
    private function headline(Event $event, string $message, ?CheckRunPresentationData $presentation): array
    {
        $summaryParams = $event->payload['summary_params'] ?? [];
        $summaryKey = $event->payload['summary'] ?? null;
        // Component names and string summary params (except engine instants) are website or user text inside the message.
        // Retained engine text is mapped to the catalog by the presenter, so it is not untrusted text in the message.
        $engine = is_string($summaryKey) && is_array($summaryParams) && EngineMessage::fromSummary($summaryKey, $summaryParams) !== null;
        $messageUntrusted = $event->component_type !== null || ! $engine && ((is_array($summaryParams) && array_any($summaryParams,
            static fn (mixed $value, int|string $name): bool => is_string($value) && ! in_array($name, CheckRunPresentationPartData::SummaryTrusted, true)))
            || (is_string($summaryKey) && SummaryKey::isFreeText($summaryKey)));
        $display = ['display_headline' => $message];
        if ($presentation instanceof CheckRunPresentationData && ! ApiHeadline::isLegacy($presentation)) {
            $check = $event->check ?? throw new \LogicException('A presented activity event needs its check.');
            // Classify the observation this event presents: its own result, never the incident's cause (a recovery keeps it).
            $checkError = $event->to_result instanceof CheckResult ? $event->to_result === CheckResult::Errored
                : $event->type !== 'incident.resolved' && $event->cause === IncidentCause::Errored;
            $projected = ApiHeadline::project($presentation, $event->type, $check->type, $checkError);

            return [...$projected, ...$display,
                'display_headline_untrusted' => $messageUntrusted || $projected['display_headline_untrusted']];
        }

        return ['headline' => $messageUntrusted ? $event->type : $message, 'headline_status' => $messageUntrusted ? 'legacy_generic' : 'event_message',
            'headline_parts' => null, 'headline_data' => (object) [], ...$display, 'display_headline_untrusted' => $messageUntrusted];
    }
}
