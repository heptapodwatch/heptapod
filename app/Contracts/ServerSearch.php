<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

/**
 * The part of the command palette from the servers addon. Fails closed: empty while servers are off
 * (ServersAccess::enabled); callers still decide who may see servers (ServersAccess::allows).
 */
interface ServerSearch
{
    /**
     * At most $limit servers whose name, host name or an IP address contains $search, what needs attention first.
     *
     * @return list<ServerSearchData>
     */
    public function matching(string $search, int $limit): array;
}
