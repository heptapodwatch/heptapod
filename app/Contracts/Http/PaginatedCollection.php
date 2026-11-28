<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

final class PaginatedCollection extends ResourceCollection
{
    /** @param class-string<JsonResource> $collects */
    public function __construct(mixed $resource, string $collects)
    {
        $this->collects = $collects;
        parent::__construct($resource);
    }

    /**
     * A page whose items are already the response bodies (arrays), with the same `links` and `meta` as the other lists.
     *
     * @param  LengthAwarePaginator<int, array<string, mixed>>  $page
     */
    public static function ofBodies(LengthAwarePaginator $page): JsonResponse
    {
        // Built here rather than through a resource class, which would add an empty item component to the OpenAPI document.
        $paged = $page->toArray();

        return response()->json(['data' => $paged['data'],
            'links' => ['first' => $paged['first_page_url'], 'last' => $paged['last_page_url'], 'prev' => $paged['prev_page_url'], 'next' => $paged['next_page_url']],
            'meta' => ['current_page' => $paged['current_page'], 'from' => $paged['from'], 'last_page' => $paged['last_page'], 'path' => $paged['path'],
                'per_page' => $paged['per_page'], 'to' => $paged['to'], 'total' => $paged['total']]]);
    }

    /**
     * @param  array<string, mixed>  $paginated
     * @param  array{links: array<string, mixed>, meta: array<string, mixed>}  $default
     * @return array<string, mixed>
     */
    public function paginationInformation(Request $request, array $paginated, array $default): array
    {
        unset($default['meta']['links']);

        return $default;
    }
}
