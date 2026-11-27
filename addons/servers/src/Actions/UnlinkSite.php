<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers\Actions;

use Illuminate\Support\Facades\DB;

final class UnlinkSite
{
    /** Removes the site's link; with a server, only while the site is linked to that one (a site moved meanwhile stays). */
    public function __invoke(int $siteId, ?int $serverId = null): void
    {
        DB::table('server_sites')->where('site_id', $siteId)->when($serverId !== null, static fn ($query) => $query->where('server_id', $serverId))->delete();
    }
}
