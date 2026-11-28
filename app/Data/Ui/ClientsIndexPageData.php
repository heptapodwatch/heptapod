<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Contracts\ListFilterData;
use Heptapod\Contracts\PaginationData;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Lazy\ClosureLazy;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ClientsIndexPageData extends Data
{
    /**
     * @param  array<string, ListFilterData>  $filters
     * @param  ClosureLazy|list<ClientRowData>  $clients
     */
    public function __construct(
        public readonly ClosureLazy|array $clients,
        public readonly ClosureLazy|PaginationData $clients_pagination,
        public readonly array $filters,
        public readonly bool $can_manage,
    ) {}
}
