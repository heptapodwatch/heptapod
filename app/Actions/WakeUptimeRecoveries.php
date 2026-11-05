<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Events\Notifications\UptimeAlertEpisode;
use Heptapod\Jobs\SendNotification;
use Heptapod\Models\Delivery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class WakeUptimeRecoveries
{
    /** @return Builder<Delivery> */
    public static function pending(int $checkId): Builder
    {
        $query = Delivery::query()->where('state', DeliveryState::Pending->value)->where('suppressed', false);
        $query->getQuery()->whereIn('last_reason', [UptimeAlertEpisode::WaitingReason, UptimeAlertEpisode::ReadyReason])
            ->whereIn('event_id', DB::table('events')->select('id')->where('check_id', $checkId)->where('type', 'incident.resolved'));

        return $query;
    }

    /** Called after a recorded uptime observation commits, never by the periodic dispatcher. */
    public function __invoke(int $checkId): void
    {
        $query = self::pending($checkId)->where('last_reason', UptimeAlertEpisode::WaitingReason);
        $query->getQuery()->orderBy('id')->limit(100);
        $rows = $query->get(['id', 'event_id']);
        if ($rows->count() === 100) {
            Log::warning('Uptime recovery wake reached its cap.', ['check_id' => $checkId, 'limit' => 100]);
        }
        foreach ($rows as $delivery) {
            $changed = self::pending($checkId)->where('last_reason', UptimeAlertEpisode::WaitingReason)->whereKey($delivery->id)->update(['last_reason' => UptimeAlertEpisode::ReadyReason, 'next_attempt_at' => CarbonImmutable::now('UTC')]);
            if ($changed === 1) {
                Delivery::announceEvents([$delivery->event_id]);
                SendNotification::dispatch($delivery->id);
            }
        }
    }
}
