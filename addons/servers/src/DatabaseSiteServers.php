<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers;

use Carbon\CarbonImmutable;
use Heptapod\Addons\Servers\Actions\LinkSites;
use Heptapod\Addons\Servers\Actions\UnlinkSite;
use Heptapod\Addons\Servers\Data\ServerRowData;
use Heptapod\Addons\Servers\Data\SiteServerChoicesData;
use Heptapod\Contracts\SiteServers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Core asks only after ServersAccess allowed the user, so every method trusts its caller. One read each. */
final readonly class DatabaseSiteServers implements SiteServers
{
    public function __construct(private ServerReader $reader, private LinkSites $linkSites, private UnlinkSite $unlinkSite) {}

    public function ofSite(int $siteId): ?ServerRowData
    {
        return $this->reader->ofSite(CarbonImmutable::now(), $siteId);
    }

    public function choices(int $siteId): SiteServerChoicesData
    {
        /** @var list<object{id: int, name: string, ips: string|null, linked: bool}> $rows */
        $rows = DB::select('SELECT servers.id, servers.name, servers.ips, server_sites.site_id IS NOT NULL AS linked
            FROM servers LEFT JOIN server_sites ON server_sites.server_id = servers.id AND server_sites.site_id = ?
            ORDER BY lower(servers.name), servers.id', [$siteId]);
        $linked = array_find($rows, static fn (object $row): bool => $row->linked);

        return new SiteServerChoicesData($linked?->id, array_map(static function (object $row): array {
            /** @var list<string> $ips */
            $ips = $row->ips === null ? [] : json_decode($row->ips, true, flags: JSON_THROW_ON_ERROR);

            return ['id' => $row->id, 'name' => $row->name, 'ips' => $ips];
        }, $rows));
    }

    public function link(int $siteId, ?int $serverId): void
    {
        if ($serverId === null) {
            ($this->unlinkSite)($siteId);

            return;
        }
        Validator::make(['server_id' => $serverId], ['server_id' => ['exists:servers,id']])->validate();
        ($this->linkSites)($serverId, [$siteId]);
    }
}
