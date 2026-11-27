<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class LinkSites
{
    /**
     * Links the sites to the server. One site runs on one server, so a site linked to another server moves: its one row
     * (keyed by site_id) changes server. Linking a site to the server it is on changes nothing, so its place stays.
     *
     * @param  list<int>  $siteIds
     */
    public function __invoke(int $serverId, array $siteIds): void
    {
        $now = CarbonImmutable::now()->utc()->format('Y-m-d H:i:s.uP');
        DB::table('server_sites')->upsert(array_map(static fn (int $siteId): array => ['site_id' => $siteId, 'server_id' => $serverId, 'created_at' => $now],
            $siteIds), ['site_id'], ['server_id', 'created_at' => DB::raw(
                'CASE WHEN server_sites.server_id = excluded.server_id THEN server_sites.created_at ELSE excluded.created_at END')]);
    }
}
