<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Models\Client;
use Heptapod\Models\Site;
use Illuminate\Support\Facades\DB;

final readonly class RestoreClient
{
    public function __construct(private ResumeSite $resume) {}

    public function __invoke(Client $client, bool $resumeSites = true): void
    {
        NotificationLocks::transaction(function () use ($client, $resumeSites): void {
            NotificationLocks::clients([$client->id]);
            $locked = Client::query()->findOrFail($client->id);
            if ($locked->archived_at === null) {
                return;
            }
            $siteIds = DB::table('sites')->where('client_id', $locked->id)->orderBy('id')->pluck('id')->all();
            NotificationLocks::checks([], exclusive: DB::table('checks')->whereIn('site_id', $siteIds)->orderBy('id')->pluck('id'));
            NotificationLocks::sites($siteIds, exclusive: true);
            $locked->update(['archived_at' => null]);
            $sites = Site::query()->whereKey($siteIds);
            $sites->getQuery()->whereNotNull('paused_by_archive_at')->orderBy('id');
            foreach ($sites->get() as $site) {
                if ($resumeSites) {
                    ($this->resume)($site);
                }
                $site->update(['paused_by_archive_at' => null]);
            }
        });
    }
}
