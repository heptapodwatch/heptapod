<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Carbon\CarbonImmutable;
use Heptapod\Checks\Execution\ExecutionBudget;
use Heptapod\Enums\ImportRunStatus;
use Heptapod\Events\LiveSignal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Shared durability boundary for queued reports-queue work (Oh Dear imports and manual backups):
 * fails a queued row immediately if queue dispatch throws, handles job failures before claim, and
 * reconciles abandoned queued or running rows once they outlive the worker retry window.
 */
final readonly class ReconcileQueuedWork
{
    public const int StaleTimeout = ExecutionBudget::RetryAfter;

    public function __construct(private FailRequestedBackup $failRequestedBackup) {}

    public function __invoke(?CarbonImmutable $now = null): int
    {
        return $this->failStaleImports($now) + $this->failStaleBackups($now);
    }

    /**
     * @param  \Closure(): mixed  $dispatch
     * @param  \Closure(): mixed  $failQueued
     */
    public function dispatchOrFail(\Closure $dispatch, \Closure $failQueued): void
    {
        try {
            $dispatch();
        } catch (\Throwable $exception) {
            $failQueued();
            throw $exception;
        }
    }

    public function failImport(string $runId, bool $onlyQueued = false, string $error = 'unexpected'): bool
    {
        $states = $onlyQueued
            ? [ImportRunStatus::Queued->value]
            : [ImportRunStatus::Queued->value, ImportRunStatus::Running->value];
        $now = now('UTC');
        $updated = DB::table('import_runs')
            ->where('id', $runId)
            ->whereIn('status', $states)
            ->update([
                'status' => ImportRunStatus::Failed->value,
                'error' => $error,
                'finished_at' => $now,
                'updated_at' => $now,
            ]);
        if ($updated === 1) {
            LiveSignal::forImportChange();

            return true;
        }

        return false;
    }

    public function failBackup(int $backupId, bool $onlyQueued = false): bool
    {
        if (! $onlyQueued) {
            ($this->failRequestedBackup)($backupId);

            return true;
        }

        $updated = DB::table('backup_runs')
            ->where('id', $backupId)
            ->where('state', 'queued')
            ->update(['state' => 'failed', 'completed_at' => now('UTC')]);
        if ($updated === 1) {
            LiveSignal::forBackup();

            return true;
        }

        return false;
    }

    public function failStaleImports(?CarbonImmutable $now = null): int
    {
        $cutoff = ($now ?? now('UTC')->toImmutable())->subSeconds(self::StaleTimeout);
        // Bounded to the active partial index (import_runs_active: WHERE status IN ('queued', 'running')).
        $staleIds = DB::table('import_runs')
            ->whereIn('status', [ImportRunStatus::Queued->value, ImportRunStatus::Running->value])
            ->where('updated_at', '<=', $cutoff)
            ->pluck('id');
        $failed = 0;
        foreach ($staleIds as $runId) {
            if (! is_string($runId)) {
                throw new \UnexpectedValueException('Import run ID must be a string.');
            }
            if ($this->failImport($runId)) {
                $failed++;
            }
        }

        return $failed;
    }

    public function failStaleBackups(?CarbonImmutable $now = null): int
    {
        $cutoff = ($now ?? now('UTC')->toImmutable())->subSeconds(self::StaleTimeout);
        // Bounded to the active partial index (backup_runs_active: WHERE state IN ('queued', 'running')).
        $staleIds = DB::table('backup_runs')
            ->whereIn('state', ['queued', 'running'])
            ->where(static function (Builder $query) use ($cutoff): void {
                $query->where(static function (Builder $queued) use ($cutoff): void {
                    $queued->where('state', 'queued')->where('requested_at', '<=', $cutoff);
                })->orWhere(static function (Builder $running) use ($cutoff): void {
                    $running->where('state', 'running')->where('started_at', '<=', $cutoff);
                });
            })
            ->pluck('id');
        $failed = 0;
        foreach ($staleIds as $backupId) {
            if (! is_int($backupId)) {
                throw new \UnexpectedValueException('Backup ID must be an integer.');
            }
            ($this->failRequestedBackup)($backupId);
            $failed++;
        }

        return $failed;
    }
}
