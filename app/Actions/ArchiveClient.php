<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Models\Client;
use Heptapod\Models\Site;
use Illuminate\Support\Facades\DB;

final readonly class ArchiveClient
{
    public function __construct(private PauseSite $pause) {}

    public function __invoke(Client $client): void
    {
        NotificationLocks::transaction(function () use ($client): void {
            $query = Client::query();
            NotificationLocks::forUpdate($query->getQuery()->where('id', $client->id));
            $locked = $query->firstOrFail();
            if ($locked->is_internal) {
                throw new \LogicException('Internal client cannot be archived.');
            }
            if ($locked->archived_at !== null) {
                return;
            }
            // NotificationLocks: the work of every site at once, level by level (all checks, all sites, then the incidents and
            // deliveries the pauses write), before the first pause. Pausing site after site would hold one site's incidents
            // and deliveries while it takes the next site's lower ids. The pauses write no channel.
            /** @var list<int> $siteIds */
            $siteIds = DB::table('sites')->where('client_id', $locked->id)->orderBy('id')->pluck('id')->all();
            /** @var list<int> $checkIds */
            $checkIds = DB::table('checks')->whereIn('site_id', $siteIds)->orderBy('id')->pluck('id')->all();
            NotificationLocks::checks([], exclusive: $checkIds);
            NotificationLocks::sites($siteIds, exclusive: true);
            ResolveInactiveCheckIncidents::lockWork($checkIds);
            $query = Site::query();
            $query->getQuery()->whereIn('id', $siteIds)->orderBy('id');
            $sites = $query->get();
            foreach ($sites as $site) {
                ($this->pause)($site);
            }
            $locked->update(['archived_at' => now()]);
        });
    }
}
