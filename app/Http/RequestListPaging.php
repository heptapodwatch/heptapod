<?php

declare(strict_types=1);

namespace Heptapod\Http;

use Heptapod\Contracts\ListPaging;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;

final readonly class RequestListPaging implements ListPaging
{
    public function paginate(QueryBuilder|EloquentBuilder $query, string $list, ?string $pageName = null): LengthAwarePaginator
    {
        $pageName ??= $list.'_page';
        // Read per call, never held: Octane reuses resolved instances across requests.
        $perPage = ListPageSize::resolve(request(), $list, $this->sizeParameter($list, $pageName));

        /** @var LengthAwarePaginator<int, mixed> $page */
        $page = $query->paginate($perPage, ['*'], $pageName);
        if ($page->currentPage() > $page->lastPage()) {
            /** @var LengthAwarePaginator<int, mixed> $page */
            $page = $query->paginate($perPage, ['*'], $pageName, $page->lastPage());
        }

        return $page->withQueryString();
    }

    public function paginateList(array $items, string $list, ?string $pageName = null): LengthAwarePaginator
    {
        $request = request();
        $pageName ??= $list.'_page';
        $perPage = ListPageSize::resolve($request, $list, $this->sizeParameter($list, $pageName));
        $page = min(LengthAwarePaginator::resolveCurrentPage($pageName), max(1, (int) ceil(count($items) / $perPage)));
        $paginator = new LengthAwarePaginator(
            array_slice($items, ($page - 1) * $perPage, $perPage),
            count($items),
            $perPage,
            $page,
            ['path' => $request->url(), 'pageName' => $pageName],
        );

        return $paginator->withQueryString();
    }

    public function withinRange(LengthAwarePaginator $page): LengthAwarePaginator
    {
        if ($page->currentPage() <= $page->lastPage()) {
            return $page;
        }

        return new LengthAwarePaginator($page->getCollection(), $page->total(), $page->perPage(), $page->lastPage(),
            ['path' => $page->path(), 'pageName' => $page->getPageName()])->withQueryString();
    }

    /** `page` sizes with `per_page`; a list's own `<list>_page` with `<list>_per_page`. */
    private function sizeParameter(string $list, string $pageName): string
    {
        return $pageName === 'page' ? 'per_page' : $list.'_per_page';
    }
}
