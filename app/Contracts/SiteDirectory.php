<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/** Core's sites for an addon that links its own records to them (servers: "runs on"). Problem sites first, then by name. */
interface SiteDirectory
{
    /**
     * Every site the user can see, or only those among $siteIds; for a picker.
     *
     * @param  list<int>|null  $siteIds
     * @return list<DirectorySiteData>
     */
    public function visible(Authenticatable $user, ?array $siteIds = null): array;

    /**
     * One page of the visible sites among $siteIds, paged as `<list>_page` / `<list>_per_page` (ListPaging).
     *
     * @param  list<int>  $siteIds
     * @return array{sites: list<DirectorySiteData>, pagination: PaginationData}
     */
    public function page(Authenticatable $user, array $siteIds, string $list): array;
}
