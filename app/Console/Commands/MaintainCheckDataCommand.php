<?php

declare(strict_types=1);

namespace Heptapod\Console\Commands;

use Carbon\CarbonImmutable;
use Heptapod\Checks\History\CheckDataMaintenance;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

#[Description('Create monthly run partitions, recompute rollups, then drop partitions entirely older than 30 days')]
#[Signature('heptapod:maintain-check-data {--recompute-range= : UTC from,to timestamps; recompute complete intersecting buckets for repair or backfill}')]
final class MaintainCheckDataCommand extends Command
{
    public function handle(CheckDataMaintenance $maintenance): int
    {
        $startedAt = CarbonImmutable::now('UTC');
        $from = null;
        $until = null;
        $range = $this->option('recompute-range');
        if (is_string($range)) {
            $bounds = explode(',', $range);
            if (count($bounds) !== 2 || trim($bounds[0]) === '' || trim($bounds[1]) === '') {
                throw new InvalidArgumentException('The recompute range must contain two UTC timestamps: from,to.');
            }
            $from = CarbonImmutable::parse($bounds[0], 'UTC');
            $until = CarbonImmutable::parse($bounds[1], 'UTC');
        }
        // maintain() records the outcome for System health inside its serialization lock; a failure is rethrown and reported.
        $skippedPartitions = $maintenance->maintain($startedAt, $from, $until);
        foreach ($skippedPartitions as $skipped) {
            $this->warn(__('checks.history.retention_skipped', $skipped));
            Log::warning('Check run retention skipped an unfinalised partition.', $skipped);
        }

        return self::SUCCESS;
    }
}
