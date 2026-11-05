<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Lazy\ClosureLazy;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ClientsIndexPageData extends Data
{
    /**
     * @param  ClosureLazy|list<ClientRowData>  $clients
     */
    public function __construct(
        public readonly ClosureLazy|array $clients,
        public readonly ClosureLazy|PaginationData $pagination,
        public readonly ClosureLazy|string|null $search,
        public readonly ClosureLazy|string $state = 'all',
        public readonly ClosureLazy|bool $can_manage = false,
    ) {}
}
