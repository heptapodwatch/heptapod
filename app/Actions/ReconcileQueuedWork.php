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

    public function failImport(string $runId, bool $onlyQueued = false, string $error = 'unexpected', ?int $attempt = null): bool
    {
        $states = $onlyQueued
            ? [ImportRunStatus::Queued->value]
            : [ImportRunStatus::Queued->value, ImportRunStatus::Running->value];
        $now = now('UTC');
        $updated = DB::table('import_runs')
            ->where('id', $runId)
            ->whereIn('status', $states)
            ->when($attempt !== null, static fn (Builder $query): Builder => $query->where('attempts', $attempt))
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
        $at = $now ?? now('UTC')->toImmutable();
        $cutoff = $at->subSeconds(self::StaleTimeout);
        // Single conditional write bounded by import_runs_active: a row claimed concurrently refreshes updated_at and is skipped.
        $failed = DB::table('import_runs')
            ->whereIn('status', [ImportRunStatus::Queued->value, ImportRunStatus::Running->value])
            ->where('updated_at', '<=', $cutoff)
            ->update([
                'status' => ImportRunStatus::Failed->value,
                'error' => 'unexpected',
                'finished_at' => $at,
                'updated_at' => $at,
            ]);
        if ($failed > 0) {
            LiveSignal::forImportChange();
        }

        return $failed;
    }

    public function failStaleBackups(?CarbonImmutable $now = null): int
    {
        $at = $now ?? now('UTC')->toImmutable();
        $cutoff = $at->subSeconds(self::StaleTimeout);
        // Single conditional write bounded by backup_runs_active: a queued backup claimed concurrently switches to running with fresh started_at and is skipped.
        $failed = DB::table('backup_runs')
            ->whereIn('state', ['queued', 'running'])
            ->where(static function (Builder $query) use ($cutoff): void {
                $query->where(static function (Builder $queued) use ($cutoff): void {
                    $queued->where('state', 'queued')->where('requested_at', '<=', $cutoff);
                })->orWhere(static function (Builder $running) use ($cutoff): void {
                    $running->where('state', 'running')->where('started_at', '<=', $cutoff);
                });
            })
            ->update(['state' => 'failed', 'completed_at' => $at]);
        if ($failed > 0) {
            LiveSignal::forBackup();
        }

        return $failed;
    }
}
