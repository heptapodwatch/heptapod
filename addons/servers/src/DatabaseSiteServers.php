<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers;

use Carbon\CarbonImmutable;
use Heptapod\Addons\Servers\Actions\LinkSites;
use Heptapod\Addons\Servers\Actions\UnlinkSite;
use Heptapod\Addons\Servers\Data\ServerRowData;
use Heptapod\Addons\Servers\Data\SiteServerChoicesData;
use Heptapod\Contracts\ServersAccess;
use Heptapod\Contracts\SiteServers;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** One read each; nothing is read or written while servers are off. */
final readonly class DatabaseSiteServers implements SiteServers
{
    public function __construct(private ServersAccess $access, private ServerReader $reader, private LinkSites $linkSites, private UnlinkSite $unlinkSite) {}

    public function ofSite(int $siteId): ?ServerRowData
    {
        return $this->access->enabled() ? $this->reader->ofSite(CarbonImmutable::now(), $siteId) : null;
    }

    public function serversOfSites(array $siteIds): array
    {
        if (! $this->access->enabled() || $siteIds === []) {
            return [];
        }
        /** @var list<object{site_id: int, id: int, name: string}> $rows */
        $rows = DB::select('SELECT server_sites.site_id, servers.id, servers.name FROM server_sites JOIN servers ON servers.id = server_sites.server_id
            WHERE server_sites.site_id = ANY(?::bigint[])', ['{'.implode(',', $siteIds).'}']);
        $servers = [];
        foreach ($rows as $row) {
            $servers[$row->site_id] = ['id' => $row->id, 'name' => $row->name];
        }

        return $servers;
    }

    public function choices(int $siteId): ?SiteServerChoicesData
    {
        if (! $this->access->enabled()) {
            return null;
        }
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

    public function holdServer(?int $serverId): void
    {
        abort_unless($this->access->enabled(), 404);
        // FOR SHARE: a removal waits until the caller's transaction ends, so the link written after the site never dangles.
        if ($serverId !== null && DB::table('servers')->where('id', $serverId)->sharedLock()->doesntExist()) {
            throw ValidationException::withMessages(['server_id' => __('validation.exists', ['attribute' => __('servers.site.field')])]);
        }
    }

    public function link(int $siteId, ?int $serverId): void
    {
        abort_unless($this->access->enabled(), 404);
        if ($serverId === null) {
            ($this->unlinkSite)($siteId);

            return;
        }
        ($this->linkSites)($serverId, [$siteId]);
    }
}
