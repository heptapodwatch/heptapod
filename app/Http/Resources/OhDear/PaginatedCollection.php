<?php

declare(strict_types=1);

namespace Heptapod\Http\Resources\OhDear;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

final class PaginatedCollection extends ResourceCollection
{
    /** @param class-string<JsonResource> $collects */
    public function __construct(mixed $resource, string $collects)
    {
        $this->collects = $collects;
        parent::__construct($resource);
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
