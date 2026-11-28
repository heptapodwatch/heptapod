<?php

declare(strict_types=1);

namespace Heptapod\Checks\History;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\HistoryPrune;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Deletes addon history ended before a dropped run partition's end. Addon rows hold no foreign key to check_runs, so
 * this runs before, not inside, the partition-drop transaction, and each batch commits on its own.
 */
final readonly class AddonHistoryRetention
{
    /** Cascades multiply a batch (a crawl snapshot carries hundreds of findings), so it stays far below the statement timeout. */
    private const int BatchRows = 100;

    public function __construct(private CheckRegistry $registry) {}

    public function prune(CarbonImmutable $before): void
    {
        foreach ($this->registry->keys() as $key) {
            foreach ($this->registry->get($key)->persistence()?->historyPrunes() ?? [] as $prune) {
                $this->pruneTable($prune, $before->format('Y-m-d H:i:s.uP'));
            }
        }
    }

    private function pruneTable(HistoryPrune $prune, string $before): void
    {
        // A row older than the bound never becomes the newest later, so the baselines read once stay valid for every batch.
        $baselines = [];
        if ($prune->baselinePer !== null) {
            foreach ([static fn (Builder $accepted): Builder => $accepted, ...$prune->baselineFilters] as $filter) {
                $newest = DB::table($prune->table)->selectRaw("DISTINCT ON ({$prune->baselinePer}) {$prune->idColumn}")
                    ->whereNotNull('run_ended_at')->orderBy($prune->baselinePer)->orderByDesc('run_ended_at')->orderByDesc('run_id');
                /** @var list<int> $ids */
                $ids = $filter($newest)->pluck($prune->idColumn)->all();
                $baselines = [...$baselines, ...$ids];
            }
        }
        $expired = fn (): Builder => DB::table($prune->table)->where($prune->endedColumn, '<', $before)
            ->whereRaw("{$prune->idColumn} <> ALL(?::bigint[])", ['{'.implode(',', $baselines).'}']);
        do {
            // Rows sharing an ID value (one cron run's tasks) go in the same batch, so a full batch can delete more rows.
            $deleted = $expired()->whereIn($prune->idColumn, $expired()->select($prune->idColumn)->orderBy($prune->endedColumn)->limit(self::BatchRows))->delete();
        } while ($deleted >= self::BatchRows);
    }
}
