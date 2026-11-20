<?php

declare(strict_types=1);

namespace Heptapod\Http;

use Heptapod\Contracts\ListPaging;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;

final readonly class RequestListPaging implements ListPaging
{
    public function paginate(QueryBuilder|EloquentBuilder $query, string $list): LengthAwarePaginator
    {
        // Read per call, never held: Octane reuses resolved instances across requests.
        $perPage = ListPageSize::resolve(request(), $list, $list.'_per_page');

        /** @var LengthAwarePaginator<int, mixed> */
        return $query->paginate($perPage, ['*'], $list.'_page')->withQueryString();
    }
}
