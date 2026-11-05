<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Illuminate\Support\Facades\DB;

final class MarkBackupRunning
{
    public function __invoke(int $id): void
    {
        $changed = DB::table('backup_runs')->where('id', $id)->where('state', 'queued')
            ->update(['state' => 'running', 'started_at' => now('UTC')]);
        if ($changed !== 1) {
            throw new \LogicException('Only queued backups may start.');
        }
    }
}
