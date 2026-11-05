<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Models\Check;
use Heptapod\Models\User;
use Illuminate\Support\Facades\DB;

final class UnsnoozeCheck
{
    public function __invoke(Check $check, User $actor): void
    {
        NotificationLocks::transaction(function () use ($check, $actor): void {
            // NotificationLocks: the actor's key (the unsnooze names it) and the check (SnoozeCheck holds both already), then the
            // woken deliveries in ascending id.
            NotificationLocks::users([$actor->id]);
            NotificationLocks::checks([], exclusive: [$check->id]);
            $check->snoozes()->getQuery()->getQuery()->whereNull('component_type')->whereNull('unsnoozed_at')->where('starts_at', '<=', now()->format('Y-m-d H:i:s.uP'))->where('ends_at', '>', now()->format('Y-m-d H:i:s.uP'))
                ->update(['unsnoozed_at' => now()->format('Y-m-d H:i:s.uP'), 'unsnoozed_by' => $actor->name, 'unsnoozed_by_user_id' => $actor->id]);
            $deferred = DB::table('deliveries')->whereIn('event_id', DB::table('events')->select('id')->where('check_id', $check->id))
                ->where('suppressed', false)->where('state', DeliveryState::Pending->value)->where('last_reason', 'deferred_by_snooze')
                ->where('next_attempt_at', '>', now()->format('Y-m-d H:i:s.uP'));
            $locked = NotificationLocks::deliveries((clone $deferred)->pluck('id'));
            if ($locked !== []) {
                $deferred->whereIn('id', $locked)->update(['next_attempt_at' => now()->format('Y-m-d H:i:s.uP')]);
            }
        });
    }
}
