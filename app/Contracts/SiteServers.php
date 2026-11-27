<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Data;

/** The server a site runs on, from the servers addon: shown on the site's Overview and set in its Settings. */
interface SiteServers
{
    /** The server the site runs on, for its Overview card; null while the site is not linked. */
    public function ofSite(int $siteId): ?Data;

    /** The site's server and every server to pick from, for the Site settings field. */
    public function choices(int $siteId): Data;

    /**
     * Links the site to the server, moving it from any other server (one site runs on one server), or unlinks it with null.
     *
     * @throws ValidationException under server_id for a server that does not exist
     */
    public function link(int $siteId, ?int $serverId): void;
}
