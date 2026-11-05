<?php

declare(strict_types=1);

namespace Heptapod\Jobs;

use Heptapod\Actions\FailRequestedBackup;
use Heptapod\Actions\MarkBackupRunning;
use Heptapod\Actions\RecordBackupOutcome;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Spatie\Backup\Events\BackupHasFailed;
use Throwable;

#[Tries(1)]
final class RunBackupNow implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $backupId)
    {
        $this->onConnection('redis');
        $this->onQueue('reports');
    }

    public function failed(Throwable $exception): void
    {
        app(FailRequestedBackup::class)($this->backupId);
    }

    public function handle(MarkBackupRunning $markRunning, RecordBackupOutcome $record): void
    {
        $markRunning($this->backupId);
        Context::scope(function () use ($record): void {
            try {
                $status = Artisan::call('backup:run', ['--isolated' => 1, '--no-interaction' => true]);
                $recorded = DB::table('backup_runs')->where('id', $this->backupId)->value('state');
                if ($status !== 0 || $recorded !== 'complete') {
                    throw new \RuntimeException('The backup command did not complete.');
                }
            } catch (Throwable $exception) {
                if (DB::table('backup_runs')->where('id', $this->backupId)->value('state') === 'running') {
                    $record(new BackupHasFailed(new \RuntimeException('Backup command failed.')));
                }

                throw $exception;
            }
        }, ['backup-run-id' => $this->backupId]);
    }
}
