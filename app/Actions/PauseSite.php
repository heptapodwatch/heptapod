<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Carbon\CarbonImmutable;
use Heptapod\Checks\CheckPending;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Models\Check;
use Heptapod\Models\Site;
use Illuminate\Support\Facades\DB;

final readonly class PauseSite
{
    public function __construct(private ResolveInactiveCheckIncidents $resolve) {}

    public function __invoke(Site $site, ?CarbonImmutable $resumeAt = null): void
    {
        NotificationLocks::transaction(function () use ($site, $resumeAt): void {
            // NotificationLocks: the checks, the site, then the incidents and deliveries the resolutions below write, for all
            // checks at once (ResolveInactiveCheckIncidents::lockWork).
            /** @var list<int> $checkIds */
            $checkIds = DB::table('checks')->where('site_id', $site->id)->orderBy('id')->pluck('id')->all();
            NotificationLocks::checks([], exclusive: $checkIds);
            NotificationLocks::sites([$site->id], exclusive: true);
            $locked = Site::query()->findOrFail($site->id);
            $checks = Check::query()->whereKey($checkIds)->get()->sortBy('id')->values();
            ResolveInactiveCheckIncidents::lockWork($checkIds);
            if ($locked->paused_at !== null) {
                $locked->update(['resume_at' => $resumeAt]);
            } else {
                $at = now();
                $locked->update(['paused_at' => $at, 'resume_at' => $resumeAt]);
                DB::table('unmonitored_periods')->insert(['site_id' => $site->id, 'started_at' => $at]);
            }
            foreach ($checks as $check) {
                // A paused site never runs its rechecks; a lost Redis job must not leave them pending.
                CheckPending::clear($check);
                ($this->resolve)($check);
            }
        });
    }
}
