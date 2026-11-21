<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Events\LiveSignal;
use Heptapod\Exceptions\ExpectedRefusal;
use Heptapod\Jobs\RunBackupNow;
use Heptapod\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class RequestBackup
{
    public function __construct(private ReconcileQueuedWork $reconcile) {}

    public function __invoke(User $actor): int
    {
        abort_unless($actor->hasRole('Owner'), 403);

        $id = DB::transaction(function () use ($actor): int {
            DB::table('roles')->where('name', 'Owner')->where('guard_name', 'web')->lockForUpdate()->first()
                ?? throw new \LogicException('Owner role must exist.');
            $this->reconcile->failStaleBackups();
            if (DB::table('backup_runs')->whereIn('state', ['queued', 'running'])->exists()) {
                throw new ExpectedRefusal(
                    status: 409,
                    messageKey: 'errors.backup.already_running',
                    errorCode: 'backup_already_running',
                );
            }

            return DB::table('backup_runs')->insertGetId(['requested_by' => $actor->id,
                'state' => 'queued', 'requested_at' => now('UTC')]);
        });

        $this->reconcile->dispatchOrFail(
            static fn () => RunBackupNow::dispatch($id),
            fn (): bool => $this->reconcile->failBackup($id, onlyQueued: true),
        );
        LiveSignal::forBackup();

        return $id;
    }
}
