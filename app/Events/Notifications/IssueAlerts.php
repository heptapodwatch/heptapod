<?php

declare(strict_types=1);

namespace Heptapod\Events\Notifications;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Enums\NotificationCategory;
use Heptapod\Models\Event;
use Heptapod\Models\NotificationDestination;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The ledger flow both issue alert sources share (SystemIssueMailer, ServerIssueMailer): their recipient channels by
 * their own preference cell, one event per issue and phase, and one pending delivery per recipient. Who receives, the
 * send-time rule and the message stay with each source.
 */
final class IssueAlerts
{
    /**
     * Channels that are not archived, with their $category cell as the attribute $cell, their users and those users'
     * roles, in id order: three queries, bounded by the channel count. $where narrows them in SQL.
     *
     * @param  \Closure(Builder): mixed  $where
     * @return Collection<int, NotificationDestination>
     */
    public static function channels(NotificationCategory $category, string $cell, \Closure $where): Collection
    {
        $query = NotificationDestination::query()->with('user.roles');
        $query->getQuery()->select('notification_destinations.*')
            ->selectSub(DB::table('notification_preferences')->select('mode')->whereColumn('destination_id', 'notification_destinations.id')
                ->where('category', $category->value), $cell)
            ->whereNull('removed_at')->where($where)->orderBy('id');

        return $query->get()->toBase();
    }

    /** Narrows a channels() filter to channels whose $category cell is one of $modes. */
    public static function cellIn(Builder $query, NotificationCategory $category, string ...$modes): Builder
    {
        return $query->whereExists(static fn (Builder $cell): Builder => $cell->from('notification_preferences')->whereColumn('destination_id', 'notification_destinations.id')
            ->where('category', $category->value)->whereIn('mode', $modes));
    }

    /**
     * One event per issue and phase, routed here and never by the event planner; a concurrent second insert is a no-op
     * (the unique (issue, type) index of both issue columns).
     *
     * @param  'system_issue_id'|'server_issue_id'  $issueColumn
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $columns  further event columns (the server name)
     */
    public static function event(string $issueColumn, int $issueId, string $type, CarbonImmutable $at, array $payload, array $columns = []): Event
    {
        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.uP');
        DB::table('events')->insertOrIgnore([...$columns, 'type' => $type, $issueColumn => $issueId, 'occurred_at' => $at->utc()->format('Y-m-d H:i:s.uP'),
            'payload' => json_encode((object) $payload, JSON_THROW_ON_ERROR), 'suppressed' => false, 'suppression_evaluated_at' => $now,
            'notification_routed_at' => $now]);

        return Event::query()->where($issueColumn, $issueId)->where('type', $type)->firstOrFail();
    }

    /**
     * One pending delivery per row; a second insert for the same event, channel and probe round is a no-op.
     *
     * @param  iterable<array{destination_id: int, digest?: bool, last_reason?: string|null, next_attempt_at?: CarbonImmutable, probe_round?: int}>  $rows
     */
    public static function insertDeliveries(int $eventId, NotificationCategory $category, iterable $rows): void
    {
        $now = CarbonImmutable::now('UTC');
        $inserts = [];
        foreach ($rows as $row) {
            $inserts[] = ['event_id' => $eventId, 'destination_id' => $row['destination_id'], 'digest' => $row['digest'] ?? false,
                'probe_round' => $row['probe_round'] ?? 0, 'last_reason' => $row['last_reason'] ?? null, 'state' => DeliveryState::Pending->value,
                'next_attempt_at' => ($row['next_attempt_at'] ?? $now)->utc()->format('Y-m-d H:i:s.uP'), 'suppression_evaluated_at' => $now->format('Y-m-d H:i:s.uP'),
                'origin' => 'system', 'category' => $category->value];
        }
        DB::table('deliveries')->insertOrIgnore($inserts);
    }
}
