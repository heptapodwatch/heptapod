<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/** Core's sites for an addon that links its own records to them (servers: "runs on"). Problem sites first, then by name. */
interface SiteDirectory
{
    /**
     * The sites among $siteIds the user can see.
     *
     * @param  list<int>  $siteIds
     * @return list<DirectorySiteData>
     */
    public function visible(Authenticatable $user, array $siteIds): array;

    /**
     * At most $limit sites the user can see whose name or URL contains $search (every site for an empty one), except
     * $exceptSiteIds; for a picker that searches on the server.
     *
     * @param  list<int>  $exceptSiteIds
     * @return list<DirectorySiteData>
     */
    public function search(Authenticatable $user, string $search, array $exceptSiteIds, int $limit): array;

    /**
     * One page of the visible sites among $siteIds, paged as `<list>_page` / `<list>_per_page` (ListPaging); a page beyond
     * the end is the last page.
     *
     * @param  list<int>  $siteIds
     * @return array{sites: list<DirectorySiteData>, pagination: PaginationData}
     */
    public function page(Authenticatable $user, array $siteIds, string $list): array;
}
