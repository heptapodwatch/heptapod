<?php

declare(strict_types=1);

namespace Heptapod\Console\Commands;

use Heptapod\Checks\CheckSchedule;
use Heptapod\Checks\DatabaseClock;
use Heptapod\Models\Check;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('heptapod:checks:rebalance-schedule {--dry-run : Preview schedule changes without saving them}')]
final class RebalanceCheckScheduleCommand extends Command
{
    public function getDescription(): string
    {
        return __('checks.schedule.rebalance_description');
    }

    public function handle(DatabaseClock $clock): int
    {
        $dryRun = $this->option('dry-run');
        $lastId = 0;
        /** @var int|null $maxId */
        $maxId = DB::table('checks')->max('id');
        $maxId ??= 0;
        $changed = 0;
        /** @var array<int, array{before: array<int, int>, after: array<int, int>}> $distribution */
        $distribution = [];
        do {
            $count = DB::transaction(function () use ($clock, $dryRun, $maxId, &$lastId, &$changed, &$distribution): int {
                $query = Check::query();
                $query->getQuery()->select(['id', 'interval_seconds', 'next_run_at'])->where('enabled', true)
                    ->where('id', '>', $lastId)->where('id', '<=', $maxId)->orderBy('id')->limit(500);
                // Only apply serializes with claims/config edits; previews must not block writers.
                if (! $dryRun) {
                    $query->getQuery()->lockForUpdate();
                }
                $checks = $query->get();
                $at = $clock->now();
                foreach ($checks as $check) {
                    $next = CheckSchedule::nextSlot($check->id, $check->interval_seconds, $at);
                    $distribution[$check->interval_seconds] ??= ['before' => array_fill(0, 24, 0), 'after' => array_fill(0, 24, 0)];
                    $distribution[$check->interval_seconds]['before'][(int) $check->next_run_at->utc()->format('G')]++;
                    $distribution[$check->interval_seconds]['after'][(int) $next->format('G')]++;
                    if (! $check->next_run_at->equalTo($next)) {
                        $changed++;
                        if (! $dryRun) {
                            DB::table('checks')->where('id', $check->id)->update(['next_run_at' => $next->format('Y-m-d H:i:s.uP')]);
                        }
                    }
                    $lastId = $check->id;
                }

                return $checks->count();
            });
        } while ($count === 500);

        ksort($distribution);
        foreach ($distribution as $interval => $hours) {
            $this->line(__('checks.schedule.distribution', ['interval' => $interval]));
            $rows = [];
            for ($hour = 0; $hour < 24; $hour++) {
                $rows[] = [sprintf('%02d:00', $hour), $hours['before'][$hour], $hours['after'][$hour]];
            }
            $this->table([__('checks.schedule.hour'), __('checks.schedule.before'), __('checks.schedule.after')], $rows);
        }
        $this->info(trans_choice($dryRun ? 'checks.schedule.would_rebalance' : 'checks.schedule.rebalanced', $changed, ['count' => $changed]));

        return self::SUCCESS;
    }
}
