<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Checks\Execution\ExecutionBudget;
use Heptapod\Events\LiveSignal;
use Heptapod\Exceptions\ExpectedRefusal;
use Heptapod\Jobs\RunBackupNow;
use Heptapod\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class RequestBackup
{
    public function __construct(private FailRequestedBackup $fail) {}

    public function __invoke(User $actor): int
    {
        abort_unless($actor->hasRole('Owner'), 403);

        return DB::transaction(function () use ($actor): int {
            DB::table('roles')->where('name', 'Owner')->where('guard_name', 'web')->lockForUpdate()->first()
                ?? throw new \LogicException('Owner role must exist.');
            $stale = DB::table('backup_runs')->where('state', 'running')
                ->where('started_at', '<=', now('UTC')->subSeconds(ExecutionBudget::RetryAfter))->pluck('id');
            foreach ($stale as $backupId) {
                if (! is_int($backupId)) {
                    throw new \UnexpectedValueException('Backup ID must be an integer.');
                }
                ($this->fail)($backupId);
            }
            if (DB::table('backup_runs')->whereIn('state', ['queued', 'running'])->exists()) {
                throw new ExpectedRefusal(
                    status: 409,
                    messageKey: 'errors.backup.already_running',
                    errorCode: 'backup_already_running',
                );
            }
            $id = DB::table('backup_runs')->insertGetId(['requested_by' => $actor->id,
                'state' => 'queued', 'requested_at' => now('UTC')]);
            RunBackupNow::dispatch($id)->afterCommit();
            LiveSignal::forBackup();

            return $id;
        });
    }
}
