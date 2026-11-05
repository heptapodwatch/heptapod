<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Carbon\CarbonImmutable;
use Heptapod\Events\LiveSignal;
use Heptapod\Maintenance\MaintenanceTargets;
use Heptapod\Models\MaintenancePeriod;
use Illuminate\Support\Facades\DB;

/**
 * Announces maintenance windows whose start or end boundary has passed since the last tick.
 *
 * Each tick claims the oldest unannounced boundaries first, so a backlog drains across ticks rather than
 * being skipped. The marker commits in the database before the signal is published: a crash between the
 * two drops that invalidation hint, and clients recover on their next reload. Delivery is best-effort.
 */
final readonly class AnnounceMaintenanceBoundaries
{
    /** Boundaries claimed per kind (start, end) per tick, bounding each tick's work. */
    public const int BATCH_SIZE = 100;

    public function __construct(private MaintenanceTargets $targets) {}

    public function __invoke(): int
    {
        $now = CarbonImmutable::now('UTC');
        $timestamp = $now->format('Y-m-d H:i:s.uP');

        /** @var array<int, true> $clientIds */
        $clientIds = [];
        $announced = 0;
        DB::transaction(function () use ($timestamp, $now, &$clientIds, &$announced): void {
            foreach (['start', 'end'] as $kind) {
                $column = $kind === 'start' ? 'starts_at' : 'ends_at';
                $marker = $kind.'_signalled_at';
                $claim = MaintenancePeriod::query();
                $claim->getQuery()->where($column, '<=', $timestamp)->whereNull($marker)
                    ->orderBy($column)->orderBy('id')->limit(self::BATCH_SIZE)->lock('FOR UPDATE SKIP LOCKED');
                foreach ($claim->get() as $window) {
                    $window->forceFill([$marker => $now])->saveQuietly();
                    foreach ($this->targets->clientIdsForWindow($window) as $clientId) {
                        $clientIds[$clientId] = true;
                    }
                    $announced++;
                }
            }
        });

        if ($announced > 0) {
            LiveSignal::forCoalescedClients(array_keys($clientIds));
        }

        return $announced;
    }
}
