<?php

declare(strict_types=1);

namespace Heptapod\Jobs;

use Heptapod\Actions\DeleteSite;
use Heptapod\Checks\Execution\ExecutionBudget;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\RunPersistence;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Models\Site;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a site whose deletion was requested (RequestSiteDeletion): it shrinks the paused site's check history in
 * batches of at most PurgeBatch rows, each its own transaction under the check's lock, for one time slice at a time, and
 * once nothing is left runs DeleteSite, whose cascade then removes only the small rest. The scheduler queues one for every
 * pending deletion every five minutes, so a lost or failed slice resumes; a lost queued slice frees its uniqueness after
 * UniqueFor, shorter than that sweep.
 */
#[Timeout(ExecutionBudget::HorizonTimeout)]
#[Tries(1)]
#[UniqueFor(240)]
final class PurgeDeletedSite implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /** Rows one batch deletes at most. */
    public const int PurgeBatch = 1000;

    /** Seconds one slice works before it hands over, so backups and imports on the same queue take turns. */
    public const int SliceSeconds = 60;

    /**
     * One batch of a core growth table of a check, both sides through its check index; every ? binds the check id. A run
     * repeats an ended_at only within one instant, which a batch takes together. The attempt of a claim still out stays,
     * so its late worker finds it and skips the paused check.
     */
    private const array CoreHistory = [
        'DELETE FROM check_run_attempts WHERE check_id = ? AND id = ANY(ARRAY(SELECT id FROM check_run_attempts WHERE check_id = ?
            AND claim_token IS DISTINCT FROM (SELECT claim_token::text FROM checks WHERE id = ?) ORDER BY id LIMIT '.self::PurgeBatch.'))',
        'DELETE FROM check_runs WHERE check_id = ? AND ended_at = ANY(ARRAY(SELECT ended_at FROM check_runs WHERE check_id = ?
            ORDER BY ended_at LIMIT '.self::PurgeBatch.'))',
        'DELETE FROM check_run_rollups_hourly WHERE check_id = ? AND bucket_start = ANY(ARRAY(SELECT bucket_start FROM check_run_rollups_hourly
            WHERE check_id = ? ORDER BY bucket_start LIMIT '.self::PurgeBatch.'))',
        'DELETE FROM check_run_rollups_daily WHERE check_id = ? AND bucket_start = ANY(ARRAY(SELECT bucket_start FROM check_run_rollups_daily
            WHERE check_id = ? ORDER BY bucket_start LIMIT '.self::PurgeBatch.'))',
        'DELETE FROM list_response_buckets WHERE check_id = ? AND bucket_start = ANY(ARRAY(SELECT bucket_start FROM list_response_buckets
            WHERE check_id = ? ORDER BY bucket_start LIMIT '.self::PurgeBatch.'))',
    ];

    public function __construct(public readonly int $siteId, public readonly int $sliceSeconds = self::SliceSeconds)
    {
        $this->onQueue('reports');
    }

    public function uniqueId(): string
    {
        return (string) $this->siteId;
    }

    public function handle(CheckRegistry $registry, DeleteSite $delete): void
    {
        $site = Site::query()->find($this->siteId);
        if (! $site instanceof Site || $site->marked_for_deletion_at === null) {
            return;
        }
        if (! $this->shrink($registry, hrtime(true) + $this->sliceSeconds * 1_000_000_000)) {
            self::dispatch($this->siteId, $this->sliceSeconds);

            return;
        }
        $delete($site);
    }

    /**
     * Whether the growth tables are empty; false when the slice ran out of time first. Empty batches count against the
     * time too, and a slice always deletes its first rows, so every slice makes progress.
     */
    private function shrink(CheckRegistry $registry, int $until): bool
    {
        /** @var array<int, string> $types */
        $types = DB::table('checks')->where('site_id', $this->siteId)->orderBy('id')->pluck('type', 'id')->all();
        $deleted = 0;
        foreach ($types as $checkId => $type) {
            $batches = array_map(static fn (string $sql): \Closure => static fn (): int => DB::delete($sql, array_fill(0, substr_count($sql, '?'), $checkId)),
                self::CoreHistory);
            $key = CheckKey::tryFrom($type);
            $persistence = $key instanceof CheckKey && in_array($key, $registry->keys(), true) ? $registry->get($key)->persistence() : null;
            if ($persistence instanceof RunPersistence) {
                $batches[] = static fn (): int => $persistence->purgeCheck($checkId, self::PurgeBatch);
            }
            foreach ($batches as $batch) {
                do {
                    $rows = DB::transaction(static function () use ($checkId, $batch): int {
                        // NotificationLocks: the check before its history rows, as every writer of that history holds it.
                        NotificationLocks::checks([], exclusive: [$checkId]);

                        return $batch();
                    });
                    $deleted += $rows;
                    if ($deleted > 0 && hrtime(true) >= $until) {
                        return false;
                    }
                } while ($rows > 0);
            }
        }

        return true;
    }
}
