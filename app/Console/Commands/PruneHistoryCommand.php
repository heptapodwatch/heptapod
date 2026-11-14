<?php

declare(strict_types=1);

namespace Heptapod\Console\Commands;

use Carbon\CarbonImmutable;
use Heptapod\Checks\History\CheckDataMaintenance;
use Heptapod\Checks\History\HistoryRetention;
use Heptapod\Checks\History\RetentionPolicy;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('heptapod:prune-history {--dry-run : Report eligible history without deleting anything}')]
#[Description('Preview bounded history retention; history deletion is not implemented in this slice')]
final class PruneHistoryCommand extends Command
{
    public function handle(HistoryRetention $retention, CheckDataMaintenance $maintenance): int
    {
        $policy = RetentionPolicy::fromConfig();
        if ($this->option('dry-run') !== true) {
            $this->error('History deletion is not available yet. Use --dry-run. Only hourly rollup maintenance supports deletion.');

            return self::FAILURE;
        }
        $at = CarbonImmutable::now('UTC');
        DB::transaction(function () use ($retention, $maintenance, $policy, $at): void {
            DB::select('SELECT pg_advisory_xact_lock(73410202)');
            $this->info('DRY RUN at '.$at->toIso8601String().'; deletes nothing. Hourly deletion enabled: '.($policy->hourlyDeletionEnabled ? 'yes' : 'no'));
            $this->table(['Table', 'Eligible', 'Oldest eligible', 'At most/run', 'Cap/table/run', 'Exemptions (may overlap)', 'Deletion enabled'], array_map(
                static fn (array $row): array => array_values([...$row, 'deletion_enabled' => $row['deletion_enabled'] ? 'yes' : 'no']), $retention->report($at, $policy),
            ));
            $this->line('Caps are upper bounds, not batch predictions. Non-hourly deletion remains unavailable (S2/S3).');
            $this->line('Raw partitions: existing pruning unchanged; this preview does not create, recompute or drop partitions.');
            foreach ($maintenance->previewExpiredPartitions($at, $policy) as $partition) {
                $this->line($partition['partition'].': missing buckets='.$partition['missing_buckets'].'; '.($partition['missing_buckets'] > 0 ? 'existing maintenance would skip' : 'existing maintenance coverage complete'));
            }
            $this->line('Addon/raw rows: covered by existing pruning; no row prediction.');
            $this->line('Maintenance/unmonitored periods and system issues: S5, not yet reported.');
            $this->line('Job batches: completed batches only; unfinished/cancelled retention belongs to S2.');
        });

        return self::SUCCESS;
    }
}
