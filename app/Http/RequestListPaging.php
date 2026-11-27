<?php

declare(strict_types=1);

namespace Heptapod\Http;

use Heptapod\Contracts\ListPaging;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;

final readonly class RequestListPaging implements ListPaging
{
    public function paginate(QueryBuilder|EloquentBuilder $query, string $list, ?int $page = null): LengthAwarePaginator
    {
        // Read per call, never held: Octane reuses resolved instances across requests.
        $perPage = ListPageSize::resolve(request(), $list, $list.'_per_page');

        /** @var LengthAwarePaginator<int, mixed> */
        return $query->paginate($perPage, ['*'], $list.'_page', $page)->withQueryString();
    }

    public function paginateList(array $items, string $list, ?string $pageName = null): LengthAwarePaginator
    {
        $request = request();
        $resolvedPageName = $pageName ?? $list.'_page';
        $sizeParam = $resolvedPageName === 'page' ? 'per_page' : $list.'_per_page';
        $perPage = ListPageSize::resolve($request, $list, $sizeParam);
        $page = LengthAwarePaginator::resolveCurrentPage($resolvedPageName);
        $paginator = new LengthAwarePaginator(
            array_slice($items, ($page - 1) * $perPage, $perPage),
            count($items),
            $perPage,
            $page,
            ['path' => $request->url(), 'pageName' => $resolvedPageName],
        );

        return $paginator->withQueryString();
    }
}
