<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Models\Delivery;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The only archive path (design §1.1): removing a channel, deleting its user, a client message's "do not keep".
 * History stays; queued work is suppressed and digest recurrence ends. Claim and the digest emitter suppress
 * anything that slips in after this commits.
 */
final readonly class ArchiveNotificationChannel
{
    public const string Archived = 'archived';

    public const string RecipientDeleted = 'recipient_deleted';

    /** @param self::Archived|self::RecipientDeleted $reason */
    public function __invoke(NotificationDestination $channel, string $reason, ?User $actor): int
    {
        return NotificationLocks::transaction(function () use ($channel, $reason, $actor): int {
            $query = NotificationDestination::query()->with('user')->whereKey($channel->id);
            NotificationLocks::forUpdate($query->getQuery());
            $locked = $query->firstOrFail();
            if ($locked->removed_at !== null) {
                return 0;
            }
            $at = CarbonImmutable::now('UTC');
            DB::table('notification_destinations')->where('id', $locked->id)->update(['removed_at' => $at, 'removed_reason' => $reason]);
            if ($locked->account_mail && $locked->user !== null) {
                // History keeps the address this channel used once the account is gone.
                DB::update("UPDATE notification_destinations SET config = jsonb_set(config, '{email}', to_jsonb(?::text)) WHERE id = ?", [$locked->user->email, $locked->id]);
            }
            $suppression = $reason === self::RecipientDeleted ? 'recipient_deleted' : 'destination_archived';
            $pending = Delivery::query()->where('destination_id', $locked->id)->where('suppressed', false);
            $pending->getQuery()->where(static function (Builder $rows) use ($at): void {
                $rows->whereIn('state', [DeliveryState::Pending->value, DeliveryState::Failed->value])->orWhere(static function (Builder $expired) use ($at): void {
                    $expired->where('state', DeliveryState::Sending->value)->where(static function (Builder $lease) use ($at): void {
                        $lease->whereNull('lease_until')->orWhere('lease_until', '<=', $at->format('Y-m-d H:i:s.uP'));
                    });
                });
            });
            $recurring = Delivery::recurringDigest()->where('destination_id', $locked->id);
            // NotificationLocks: channel (above), then every delivery both bulk updates write, as one ascending set before the first.
            NotificationLocks::deliveries([...(clone $pending)->pluck('id'), ...(clone $recurring)->pluck('id')]);
            /** @var list<int> $eventIds */
            $eventIds = (clone $pending)->pluck('event_id')->all();
            $suppressed = $pending->getQuery()->update(['suppressed' => true, 'suppression_reason' => $suppression,
                'suppression_evaluated_at' => $at->format('Y-m-d H:i:s.uP'), 'claim_token' => null, 'lease_until' => null]);
            $recurring->getQuery()->update(['digest_recurrence_ended_at' => $at->format('Y-m-d H:i:s.uP')]);
            Delivery::announceEvents($eventIds);
            $audit = activity('audit')->performedOn($locked)->event('notification.channel_archived')
                ->withProperties(['reason' => $reason, 'suppressed_delivery_count' => $suppressed]);
            ($actor instanceof User ? $audit->causedBy($actor) : $audit->causedByAnonymous())->log('notification.channel_archived');

            return $suppressed;
        });
    }
}
