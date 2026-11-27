<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Data;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The server a site runs on, from the servers addon: shown on the site's Overview and set in its Settings. Fails closed:
 * while servers are off (ServersAccess::enabled) the reads return null and the writes refuse with 404. Callers still
 * decide who may see servers (ServersAccess::allows).
 */
interface SiteServers
{
    /** The server the site runs on, for its Overview card; null while the site is not linked. */
    public function ofSite(int $siteId): ?Data;

    /**
     * The server each of the sites runs on, by site id, for the monitors of the API; unlinked sites are left out.
     *
     * @param  list<int>  $siteIds
     * @return array<int, array{id: int, name: string}>
     */
    public function serversOfSites(array $siteIds): array;

    /** The site's server and every server to pick from, for the Site settings field. */
    public function choices(int $siteId): ?Data;

    /**
     * Refuses a server that does not exist and holds the one that does until the caller's transaction ends, so link()
     * finds it. Call it first in the transaction that also writes the site, before anything is written.
     *
     * @throws ValidationException under server_id for a server that does not exist
     * @throws NotFoundHttpException while servers are off
     */
    public function holdServer(?int $serverId): void;

    /**
     * Links the site to the server, moving it from any other server (one site runs on one server), or unlinks it with null.
     *
     * @throws NotFoundHttpException while servers are off
     */
    public function link(int $siteId, ?int $serverId): void;
}
