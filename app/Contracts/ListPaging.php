<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Pages a list by the current request: its page parameter (`<list>_page` for a list that shares its page with others, such as
 * check tabs, incident evidence and settings sections; `page` for a page's own list) and size (`<list>_per_page` or `per_page`:
 * 10, 20, 50 or 100; else the size remembered for `<list>`, else 50). A page beyond the end (rows removed meanwhile, an old
 * link) shows the last page and reports it as the current page; the address may keep the stale number. The query keeps its
 * own order and scope; report the result with PaginationData::fromPaginator.
 */
interface ListPaging
{
    /**
     * @param  QueryBuilder|EloquentBuilder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return LengthAwarePaginator<int, mixed>
     */
    public function paginate(QueryBuilder|EloquentBuilder $query, string $list, ?string $pageName = null): LengthAwarePaginator;

    /**
     * Pages an already-ordered in-memory list (e.g. backup archives read from a filesystem disk) with the same size and cookie.
     *
     * @template TItem
     *
     * @param  list<TItem>  $items
     * @return LengthAwarePaginator<int, TItem>
     */
    public function paginateList(array $items, string $list, ?string $pageName = null): LengthAwarePaginator;

    /**
     * The same last-page rule for a paginator built from a counted total without its rows (the Sites list's first render).
     *
     * @template TItem
     *
     * @param  LengthAwarePaginator<int, TItem>  $page
     * @return LengthAwarePaginator<int, TItem>
     */
    public function withinRange(LengthAwarePaginator $page): LengthAwarePaginator;
}
