<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Pages a list that shares its page with other lists (check tabs, incident evidence, settings sections): the current request's
 * `<list>_page` and `<list>_per_page` (10, 20, 50 or 100; else the size remembered for `<list>`, else 50). The query keeps its
 * own order and scope; report the result with PaginationData::fromPaginator.
 */
interface ListPaging
{
    /**
     * $page overrides the requested page: a list that shows its last page for a page beyond the end asks again with it.
     *
     * @param  QueryBuilder|EloquentBuilder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return LengthAwarePaginator<int, mixed>
     */
    public function paginate(QueryBuilder|EloquentBuilder $query, string $list, ?int $page = null): LengthAwarePaginator;

    /**
     * Pages an already-ordered in-memory list (e.g. backup archives read from a filesystem disk) with the same size and cookie.
     *
     * @template TItem
     *
     * @param  list<TItem>  $items
     * @return LengthAwarePaginator<int, TItem>
     */
    public function paginateList(array $items, string $list, ?string $pageName = null): LengthAwarePaginator;
}
