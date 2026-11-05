<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Checks\CheckPending;
use Heptapod\Contracts\CheckKey;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Models\Check;
use Illuminate\Support\Facades\DB;

final readonly class DisableCheck
{
    public function __construct(private ResolveInactiveCheckIncidents $resolve) {}

    public function __invoke(Check $check): void
    {
        DB::transaction(function () use ($check): void {
            $query = Check::query()->whereKey($check->id);
            NotificationLocks::forUpdate($query->getQuery());
            $locked = $query->firstOrFail();
            if ($locked->enabled) {
                $locked->update(['enabled' => false]);
                if ($locked->type === CheckKey::Uptime) {
                    DB::table('unmonitored_periods')->insert(['site_id' => $locked->site_id, 'check_id' => $locked->id, 'started_at' => now()]);
                }
            }
            // A disabled check never runs its recheck; a lost Redis job must not leave it pending.
            CheckPending::clear($locked);
            ($this->resolve)($locked);
            $check->refresh();
        });
    }
}
