<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Backup\Events\BackupHasFailed;

/**
 * Records a failed nightly backup that exited non-zero without recording its own outcome since it became due:
 * a process killed by the kernel for memory (137), a PHP fatal error (255), or backup:run's failure path (1) whose
 * outcome write itself failed. backup_failed then opens at the next system issue evaluation instead of backup_stale
 * a day later. Exit 1 while a requested backup is running is the isolation refusal; that backup records its own outcome.
 */
final readonly class RecordScheduledBackupExit
{
    public function __construct(private RecordBackupOutcome $record) {}

    public function __invoke(int $exitCode, CarbonImmutable $dueAt): void
    {
        // RecordBackupOutcome writes scheduled outcomes without requester and start time.
        $recorded = DB::table('backup_runs')->whereNull('requested_by')->whereNull('started_at')
            ->where('completed_at', '>=', $dueAt)->exists();
        if ($recorded) {
            return;
        }
        if ($exitCode === Command::FAILURE && DB::table('backup_runs')->where('state', 'running')->exists()) {
            Log::warning('Scheduled backup skipped while a requested backup runs.');

            return;
        }
        Log::error('Scheduled backup failed without recording its outcome.', ['exit_code' => $exitCode]);
        ($this->record)(new BackupHasFailed(new \RuntimeException('Scheduled backup exited with code '.$exitCode.'.')));
    }
}
