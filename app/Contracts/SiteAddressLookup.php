<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

/** What a site's DNS points at, from the DNS check's newest answer. */
interface SiteAddressLookup
{
    /**
     * The sites whose newest DNS answer has an A or AAAA record on one of the addresses, with the matching address. A
     * site behind a CDN answers with the CDN's addresses, which no server of its own reports.
     *
     * @param  list<string>  $addresses
     * @return array<int, string> site id => the matching address, as given
     */
    public function sitesPointingAt(array $addresses): array;
}
