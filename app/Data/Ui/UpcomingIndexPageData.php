<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Contracts\ListFilterData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class UpcomingIndexPageData extends Data
{
    /**
     * @param  array<string, ListFilterData>  $filters
     * @param  list<UpcomingItemData>  $items
     * @param  list<ClientData>  $clients
     */
    public function __construct(
        public readonly array $items,
        public readonly ?string $next_page_url,
        public readonly ?string $previous_page_url,
        public readonly int $per_page,
        public readonly array $filters,
        public readonly string $calendar_now,
        public readonly array $clients,
    ) {}
}
