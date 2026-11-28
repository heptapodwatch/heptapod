<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Crawl snapshots go with their check and the crawl cache with its site (their findings already go with the snapshot), so
 * a deleted site leaves no crawl rows, with or without the crawl addon loaded. The keys are added NOT VALID first, which
 * stops new orphans at once; the orphans of earlier deletions then go in batches, and VALIDATE scans without blocking
 * writes. The indexes the cascades use exist: crawl_snapshots (check_id), the cache's primary key and the findings'
 * (snapshot_id, id).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /** Table => [column, referenced table]. */
    private const array Keys = ['crawl_snapshots' => ['check_id', 'checks'], 'crawl_collections' => ['site_id', 'sites']];

    /** One batch of an orphaned check's crawl rows, children first; ? binds the check id. */
    private const array OrphanBatches = [
        'DELETE FROM crawl_findings WHERE ctid = ANY(ARRAY(SELECT ctid FROM crawl_findings WHERE snapshot_id IN (SELECT id FROM crawl_snapshots WHERE check_id = ?) LIMIT 1000))',
        'DELETE FROM mixed_content_findings WHERE ctid = ANY(ARRAY(SELECT ctid FROM mixed_content_findings WHERE snapshot_id IN (SELECT id FROM crawl_snapshots WHERE check_id = ?) LIMIT 1000))',
        'DELETE FROM crawl_snapshots WHERE id = ANY(ARRAY(SELECT id FROM crawl_snapshots WHERE check_id = ? LIMIT 1000))',
    ];

    public function up(): void
    {
        // Adding a key locks both tables against writes: fail fast instead of queueing every write behind a long transaction.
        DB::statement("SET lock_timeout = '5s'");
        try {
            foreach (self::Keys as $table => [$column, $referenced]) {
                // A rerun after a failed cleanup or VALIDATE keeps the key it already added and resumes from there.
                if (! DB::table('pg_constraint')->where('conname', "{$table}_{$column}_foreign")->whereRaw('conrelid = ?::regclass', [$table])->exists()) {
                    DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$column}_foreign FOREIGN KEY ({$column}) REFERENCES {$referenced} (id) ON DELETE CASCADE NOT VALID");
                }
            }
        } finally {
            DB::statement('RESET lock_timeout');
        }
        $orphanedChecks = DB::table('crawl_snapshots')->distinct()->orderBy('check_id')
            ->whereNotExists(static fn (Builder $checks): Builder => $checks->selectRaw('1')->from('checks')->whereColumn('checks.id', 'crawl_snapshots.check_id'))
            ->pluck('check_id');
        foreach ($orphanedChecks as $checkId) {
            foreach (self::OrphanBatches as $batch) {
                while (DB::delete($batch, [$checkId]) > 0);
            }
        }
        while (DB::delete('DELETE FROM crawl_collections WHERE site_id = ANY(ARRAY(SELECT site_id FROM crawl_collections c
            WHERE NOT EXISTS (SELECT 1 FROM sites WHERE sites.id = c.site_id) LIMIT 1000))') > 0);
        foreach (self::Keys as $table => [$column]) {
            DB::statement("ALTER TABLE {$table} VALIDATE CONSTRAINT {$table}_{$column}_foreign");
        }
    }

    public function down(): void
    {
        foreach (self::Keys as $table => [$column]) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_{$column}_foreign");
        }
    }
};
