<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Checks\DatabaseClock;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Enums\NotificationCategory;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Models\Delivery;
use Heptapod\Models\NotificationDestination;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\ValidationException;

final readonly class SetNotificationDestinationEnabled
{
    public function __construct(private DatabaseClock $clock, private RequestRoutingReconciliation $reconcile) {}

    public function __invoke(NotificationDestination $destination, bool $enabled): int
    {
        return NotificationLocks::transaction(function () use ($destination, $enabled): int {
            // NotificationLocks: the open episodes a resume marks (incidents), then the channel (exclusive: no delivery is
            // inserted for it while it pauses or resumes).
            if ($enabled) {
                $this->reconcile->lockEpisodes($destination);
            }
            NotificationLocks::channels([$destination->id], exclusive: true);
            $locked = NotificationDestination::query()->findOrFail($destination->id);
            if ($locked->removed_at !== null) {
                throw ValidationException::withMessages(['enabled' => __('notifications.channel_removed')]);
            }
            if ($enabled && $locked->needs_attention) {
                throw ValidationException::withMessages(['enabled' => __('notifications.resume_repair_required')]);
            }
            if ($locked->enabled === $enabled) {
                $destination->refresh();

                return 0;
            }
            $discarded = 0;
            if (! $enabled) {
                // TODO: S4 moves this to the app clock (C10–C13).
                $at = $this->clock->now();
                $pending = Delivery::query()->where('destination_id', $locked->id)->where('suppressed', false);
                // Pause only stops automatic alerts. Approved manual sends and account System mail are kept, as claim sends them anyway.
                $pending->getQuery()->where('origin', '<>', 'manual');
                if ($locked->account_mail) {
                    $pending->getQuery()->whereRaw('category IS DISTINCT FROM ?', [NotificationCategory::System->value]);
                }
                $pending->getQuery()->where(static function (Builder $rows) use ($at): void {
                    $rows->whereIn('state', [DeliveryState::Pending->value, DeliveryState::Failed->value])->orWhere(static function (Builder $expired) use ($at): void {
                        $expired->where('state', DeliveryState::Sending->value)->where(static function (Builder $lease) use ($at): void {
                            $lease->whereNull('lease_until')->orWhere('lease_until', '<=', $at->format('Y-m-d H:i:s.uP'));
                        });
                    });
                });
                // NotificationLocks: channel (above), then its deliveries in ascending id.
                NotificationLocks::deliveries((clone $pending)->pluck('id'));
                foreach ($pending->lazyById(100) as $delivery) {
                    $rows = clone $pending;
                    $rows->whereKey($delivery->id);
                    $discarded += $rows->update(['suppressed' => true, 'suppression_reason' => 'destination_disabled', 'suppression_evaluated_at' => $at->format('Y-m-d H:i:s.uP')]);
                }
                $locked->pauseDiscardedCount = $discarded;
            }
            $locked->update(['enabled' => $enabled]);
            if ($enabled) {
                $this->reconcile->forChannel($locked);
            }
            $destination->refresh();

            return $discarded;
        });
    }
}
