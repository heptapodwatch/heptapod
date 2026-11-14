<?php

declare(strict_types=1);

namespace Heptapod\Models;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Enums\NotificationCategory;
use Heptapod\Events\LiveSignal;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * @property string|null $site_name
 * @property string|null $site_url
 * @property string|null $client_name
 * @property int|null $client_id
 * @property string|null $check_type
 * @property int $id
 * @property int $event_id
 * @property int $destination_id
 * @property DeliveryState $state
 * @property int $attempts
 * @property int $probe_round 0 except for re-armed transport probes after a rejection (SystemTransportProbe)
 * @property string|null $suppression_reason
 * @property CarbonImmutable $suppression_evaluated_at
 * @property string|null $claim_token
 * @property CarbonImmutable|null $lease_until
 * @property CarbonImmutable $next_attempt_at
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $digest_recurrence_ended_at
 * @property bool $suppressed
 * @property bool $digest
 * @property string $origin
 * @property int|null $actor_id
 * @property string|null $actor_name
 * @property NotificationCategory|null $category
 * @property array{address: string, name: string, locale: string}|null $recipient_snapshot
 * @property string|null $digest_local_date
 * @property int|null $blocking_maintenance_period_id
 * @property int|null $deferred_maintenance_period_id
 * @property int|null $digest_event_id
 */
#[DateFormat('Y-m-d H:i:s.uP')]
#[Guarded(['id'])]
#[WithoutTimestamps]
final class Delivery extends Model
{
    protected static function booted(): void
    {
        self::creating(function (self $delivery): void {
            $event = $delivery->event ?? throw new \LogicException('A delivery must have an event.');
            $delivery->site_name = $event->site_name;
            $delivery->site_url = $event->site_url;
            $delivery->client_name = $event->client_name;
            $delivery->client_id = $event->client_id;
            $delivery->check_type = $event->check_type?->value;
            $delivery->category ??= NotificationCategory::of($event);
        });
    }

    protected function casts(): array
    {
        return ['deferred_maintenance_period_id' => 'integer', 'blocking_maintenance_period_id' => 'integer', 'attempts' => 'integer', 'probe_round' => 'integer', 'state' => DeliveryState::class, 'suppressed' => 'boolean', 'digest' => 'boolean', 'category' => NotificationCategory::class, 'recipient_snapshot' => 'array',
            'next_attempt_at' => 'immutable_datetime', 'lease_until' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime', 'digest_recurrence_ended_at' => 'immutable_datetime', 'suppression_evaluated_at' => 'immutable_datetime'];
    }

    /**
     * Unfinished delivery work, including scheduled retries and digest source items.
     * Rejected recipients are terminal through suppression; logged/sent deliveries never retry.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function awaitingDelivery(Builder $query): void
    {
        $query->getQuery()->where('suppressed', false)->whereIn('state', [DeliveryState::Pending->value, DeliveryState::Failed->value, DeliveryState::Sending->value]);
    }

    /** @param Builder<self> $query */
    #[Scope]
    protected function recurringDigest(Builder $query): void
    {
        $query->getQuery()->where('digest', true)->where('suppressed', false)->whereNull('digest_recurrence_ended_at');
    }

    /** Announce only changes projected by the incident delivery reader, not lease or attempt bookkeeping. */
    public function announceChanges(): void
    {
        if ($this->wasChanged(['state', 'suppressed', 'suppression_reason', 'last_reason', 'last_diagnosis', 'sent_at', 'next_attempt_at', 'digest'])) {
            self::announceEvents([$this->event_id]);
        }
    }

    /**
     * Bulk writers capture event identifiers before changing rows. Digest outcomes affect the source incidents,
     * not the site-less digest event itself. The existing publisher defers emission until commit.
     *
     * @param  list<int>  $eventIds
     */
    public static function announceEvents(array $eventIds): void
    {
        if ($eventIds === []) {
            return;
        }
        $sourceEventIds = DB::table('deliveries')->whereIn('digest_event_id', $eventIds)->pluck('event_id')->all();
        /** @var list<int> $clientIds */
        $clientIds = DB::table('events')->join('sites', 'sites.id', '=', 'events.site_id')
            ->whereIn('events.id', [...$eventIds, ...$sourceEventIds])->distinct()->pluck('sites.client_id')->all();
        if ($clientIds !== []) {
            LiveSignal::forCoalescedClients($clientIds);
        }
    }

    /** The channel's accepted alert for this incident: its opening, a cause change (an unconfirmed opening is never sent) or a reminder. */
    public static function alertedIncident(int $incidentId, int $destinationId): ?self
    {
        $deliveries = self::query();
        $openings = DB::table('events')->select('id')->where('incident_id', $incidentId)->whereIn('type', ['incident.opened', 'incident.cause_changed', 'incident.still_down']);
        $catchUps = DB::table('maintenance_catch_up_incidents')->select('event_id')->where('incident_id', $incidentId);
        $deliveries->getQuery()->where('destination_id', $destinationId)->where('state', DeliveryState::Sent->value)
            ->whereIn('event_id', $openings->union($catchUps))->orderByDesc('sent_at');

        return $deliveries->first();
    }

    /**
     * alertedIncident() and latestIncidentOpening() for many channels in one query each: which channels had an incident
     * opener accepted, and which have one pending.
     *
     * @param  list<int>  $destinationIds
     * @return array{alerted: array<int, true>, pending: array<int, true>}
     */
    public static function incidentOpenerChannels(Incident $incident, array $destinationIds): array
    {
        $openings = DB::table('events')->select('id')->where('incident_id', $incident->id)->whereIn('type', ['incident.opened', 'incident.cause_changed', 'incident.still_down']);
        $catchUps = DB::table('maintenance_catch_up_incidents')->select('event_id')->where('incident_id', $incident->id);
        /** @var list<int> $alerted */
        $alerted = DB::table('deliveries')->whereIntegerInRaw('destination_id', $destinationIds)->where('state', DeliveryState::Sent->value)
            ->whereIn('event_id', $openings->union($catchUps))->distinct()->pluck('destination_id')->all();
        /** @var list<int> $pending */
        $pending = DB::table('deliveries')->whereIntegerInRaw('destination_id', $destinationIds)->where('suppressed', false)
            ->whereIn('event_id', DB::table('events')->select('id')->where('incident_id', $incident->id)->where('type', 'incident.opened')
                ->where('occurred_at', '>=', $incident->opened_at->format('Y-m-d H:i:s.uP')))
            ->distinct()->pluck('destination_id')->all();

        return ['alerted' => array_fill_keys($alerted, true), 'pending' => array_fill_keys($pending, true)];
    }

    public static function latestIncidentOpening(Incident $incident, int $destinationId): ?self
    {
        $query = self::query()->where('destination_id', $destinationId)->where('suppressed', false)
            ->whereHas('event', static function (Builder $events) use ($incident): void {
                $events->getQuery()->where('incident_id', $incident->id)->where('type', 'incident.opened')
                    ->where('occurred_at', '>=', $incident->opened_at->format('Y-m-d H:i:s.uP'));
            });
        $query->getQuery()->orderByDesc('id');

        return $query->first();
    }

    /**
     * An opener that was never attempted and only became due after its episode recovered is
     * retired together with that recovery, so neither an orphan opener nor an orphan recovery is sent.
     */
    public function openerRetiredBy(CarbonImmutable $recoveredAt): bool
    {
        return $this->state !== DeliveryState::Sent && ($this->suppressed || $this->digest
            || ($this->attempts === 0 && $this->next_attempt_at->greaterThan($recoveredAt)));
    }

    /**
     * Opening deliveries of the episode a component recovery closes: every non-success event of the same
     * check component since its previous success, so repeated failures share one recovery.
     *
     * One aggregate row: `kept` when any opener is not retired by the recovery (see openerRetiredBy),
     * `sent` when any opener was accepted.
     *
     * @return array{kept: bool, sent: bool}
     */
    public static function recoveryEpisodeOpenings(Event $recovery, int $destinationId): array
    {
        $from = $recovery->payload['from_result'] ?? $recovery->from_result?->value;
        if (! is_string($from) || ! in_array(CheckResult::tryFrom($from), [CheckResult::Warning, CheckResult::Failed, CheckResult::Errored], true)) {
            return ['kept' => false, 'sent' => false];
        }
        $previous = self::componentEvents($recovery)->where(self::succeeded(...));
        $previous->getQuery()->orderByDesc('occurred_at')->orderByDesc('id');
        $boundary = $previous->first();
        $episode = self::componentEvents($recovery)->whereNot(self::succeeded(...));
        if ($boundary instanceof Event) {
            $episode->getQuery()->where('occurred_at', '>=', $boundary->occurred_at->format('Y-m-d H:i:s.uP'))->where('id', '>', $boundary->id);
        }
        $sent = DeliveryState::Sent->sql();
        $logged = DeliveryState::Logged->sql();
        $state = DB::table('deliveries')->where('destination_id', $destinationId)
            ->whereIn('event_id', $episode->getQuery()->select('id'))
            ->selectRaw("coalesce(bool_or(state = {$sent} or (state <> {$logged} and not suppressed and not digest and (attempts > 0 or next_attempt_at <= ?))), false) as kept,
                coalesce(bool_or(state = {$sent}), false) as sent", [$recovery->occurred_at->format('Y-m-d H:i:s.uP')])
            ->first() ?? throw new \LogicException('An aggregate returns one row.');

        return ['kept' => (bool) $state->kept, 'sent' => (bool) $state->sent];
    }

    /**
     * recoveryEpisodeOpenings() for many channels: one boundary read and one grouped aggregate.
     *
     * @param  list<int>  $destinationIds
     * @return array<int, array{kept: bool, sent: bool}> channels without any opener are absent
     */
    public static function recoveryEpisodeOpeningsFor(Event $recovery, array $destinationIds): array
    {
        $from = $recovery->payload['from_result'] ?? $recovery->from_result?->value;
        if ($destinationIds === [] || ! is_string($from) || ! in_array(CheckResult::tryFrom($from), [CheckResult::Warning, CheckResult::Failed, CheckResult::Errored], true)) {
            return [];
        }
        $previous = self::componentEvents($recovery)->where(self::succeeded(...));
        $previous->getQuery()->orderByDesc('occurred_at')->orderByDesc('id');
        $boundary = $previous->first();
        $episode = self::componentEvents($recovery)->whereNot(self::succeeded(...));
        if ($boundary instanceof Event) {
            $episode->getQuery()->where('occurred_at', '>=', $boundary->occurred_at->format('Y-m-d H:i:s.uP'))->where('id', '>', $boundary->id);
        }
        // Read the episode's event ids first: with a long channel list next to a subquery PostgreSQL scans all deliveries
        // (EXPLAIN at 740k rows: 50 ms seq scan); literal event ids drive the (event_id, destination_id) index instead.
        /** @var list<int> $eventIds */
        $eventIds = $episode->getQuery()->pluck('id')->all();
        if ($eventIds === []) {
            return [];
        }
        $states = [];
        $sent = DeliveryState::Sent->sql();
        $logged = DeliveryState::Logged->sql();
        foreach (DB::table('deliveries')->whereIntegerInRaw('event_id', $eventIds)->whereIntegerInRaw('destination_id', $destinationIds)->groupBy('destination_id')
            ->selectRaw("destination_id, bool_or(state = {$sent} or (state <> {$logged} and not suppressed and not digest and (attempts > 0 or next_attempt_at <= ?))) as kept,
                bool_or(state = {$sent}) as sent", [$recovery->occurred_at->format('Y-m-d H:i:s.uP')])->get() as $row) {
            $id = is_int($row->destination_id) ? $row->destination_id : throw new \LogicException('Channel ids are integers.');
            $states[$id] = ['kept' => (bool) $row->kept, 'sent' => (bool) $row->sent];
        }

        return $states;
    }

    /** @return Builder<Event> */
    private static function componentEvents(Event $recovery): Builder
    {
        $events = Event::query()->where('check_id', $recovery->check_id)->where('type', $recovery->type)
            ->where('component_type', $recovery->component_type)->where('component_id', $recovery->component_id);
        $events->getQuery()->where('occurred_at', '<=', $recovery->occurred_at->format('Y-m-d H:i:s.uP'))->where('id', '<', $recovery->id);

        return $events;
    }

    /** @param Builder<Event> $query */
    public static function succeeded(Builder $query): void
    {
        // coalesce keeps the negation (episode members) two-valued for events without a payload result.
        $query->getQuery()->whereRaw("(coalesce(to_result, '') = ? or coalesce(payload->>'result', '') = ?)", [CheckResult::Succeeded->value, CheckResult::Succeeded->value]);
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<NotificationDestination, $this> */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(NotificationDestination::class, 'destination_id');
    }
}
