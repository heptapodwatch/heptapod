<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Illuminate\Support\Facades\DB;

final class FailRequestedBackup
{
    public function __invoke(int $backupId): void
    {
        DB::table('backup_runs')->where('id', $backupId)->whereIn('state', ['queued', 'running'])
            ->update(['state' => 'failed', 'completed_at' => now('UTC')]);
    }
}
