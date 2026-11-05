<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\BackupWasSuccessful;

final class RecordBackupOutcome
{
    public function __invoke(BackupWasSuccessful|BackupHasFailed $event): void
    {
        $state = $event instanceof BackupWasSuccessful ? 'complete' : 'failed';
        $disk = $event->diskName;
        $code = $event instanceof BackupHasFailed ? $event->exception->getCode() : 0;
        $status = is_int($code) && $code >= 400 && $code <= 599 ? $code : null;
        $id = Context::get('backup-run-id');
        $data = ['state' => $state, 'disk' => $disk, 'http_status' => $status, 'completed_at' => now('UTC')];
        if (is_int($id)) {
            $changed = DB::table('backup_runs')->where('id', $id)->where('state', 'running')->update($data);
            if ($changed !== 1) {
                throw new \LogicException('The requested backup is not running.');
            }

            return;
        }
        DB::table('backup_runs')->insert([...$data, 'requested_by' => null, 'requested_at' => now('UTC'), 'started_at' => null]);
    }
}
