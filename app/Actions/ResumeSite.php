<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Models\Client;
use Heptapod\Models\Site;
use Illuminate\Support\Facades\DB;

final class ResumeSite
{
    public function __invoke(Site $site, bool $onlyIfDue = false): void
    {
        NotificationLocks::transaction(function () use ($site, $onlyIfDue): void {
            $clientQuery = Client::query()->whereKey($site->client_id);
            NotificationLocks::forShare($clientQuery->getQuery());
            $client = $clientQuery->firstOrFail();
            if ($client->archived_at !== null) {
                return;
            }
            NotificationLocks::forUpdate(DB::table('checks')->where('site_id', $site->id)->orderBy('id'))->get(['id']);
            $query = Site::query()->whereKey($site->id);
            NotificationLocks::forUpdate($query->getQuery());
            $locked = $query->firstOrFail();
            if ($locked->paused_at === null || ($onlyIfDue && ($locked->resume_at === null || $locked->resume_at->isFuture()))) {
                return;
            }
            $locked->update(['paused_at' => null, 'resume_at' => null]);
            DB::table('checks')->where('site_id', $site->id)->update(['awaiting_result_since' => null, 'lost_attempts' => 0, 'last_loss_started_at' => null]);
            DB::table('unmonitored_periods')->where('site_id', $site->id)->whereNull('check_id')->whereNull('ended_at')->update(['ended_at' => now()]);
        });
    }
}
