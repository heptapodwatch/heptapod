<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

/** What a site's DNS points at, from the DNS check's newest answer. */
interface SiteAddressLookup
{
    /**
     * The sites whose newest DNS answer for their own host name points at one of the addresses: the A and AAAA answer of
     * the name in the site's URL (following its aliases), never the apex or extra names the same check also watches. A site
     * behind a CDN answers with addresses of the CDN, which no server of its own reports.
     *
     * Strongest first: an answer wholly on the addresses before a partial one (a name spread over several servers), then
     * more matching addresses, then the lower site id. Every match: the caller drops the sites it cannot offer before it
     * takes the first few.
     *
     * @param  list<string>  $addresses
     * @param  list<int>  $exceptSiteIds
     * @return array<int, list<string>> site id => the matching addresses, as given
     */
    public function sitesPointingAt(array $addresses, array $exceptSiteIds): array;
}
