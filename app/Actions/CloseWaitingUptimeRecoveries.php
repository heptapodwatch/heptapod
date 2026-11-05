<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Models\Delivery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class CloseWaitingUptimeRecoveries
{
    /** @return Builder<Delivery> */
    public static function pending(int $checkId): Builder
    {
        $query = Delivery::query()->where('suppressed', false);
        $query->getQuery()->whereIn('state', [DeliveryState::Pending->value, DeliveryState::Failed->value])->whereIn('event_id', DB::table('events')
            ->join('incidents', 'incidents.id', '=', 'events.incident_id')->select('events.id')
            ->where('events.check_id', $checkId)->where('events.type', 'incident.resolved')->whereNotNull('incidents.alert_episode_started_at'));

        return $query;
    }

    public function __invoke(int $checkId, CarbonImmutable $at): void
    {
        // Include future-due and retrying recoveries too: they must not become orphaned waits after monitoring stops.
        self::pending($checkId)->chunkById(100, static function (Collection $rows) use ($checkId, $at): void {
            $changed = self::pending($checkId)->whereKey($rows->modelKeys())->update([
                'suppressed' => true, 'suppression_reason' => 'monitoring_stopped',
                'suppression_evaluated_at' => $at->format('Y-m-d H:i:s.uP'), 'last_reason' => null, 'claim_token' => null, 'lease_until' => null,
            ]);
            if ($changed > 0) {
                /** @var list<int> $events */
                $events = array_values($rows->pluck('event_id')->all());
                Delivery::announceEvents($events);
            }
        });
    }
}
