<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Contracts\ListFilterData;
use Heptapod\Contracts\PaginationData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class MaintenanceIndexPageData extends Data
{
    /**
     * @param  array<string, ListFilterData>  $filters
     * @param  list<MaintenanceWindowData>  $windows
     * @param  list<MaintenanceTargetOptionData>  $tags
     * @param  list<MaintenanceTargetOptionData>  $window_clients  the clients the listed windows target, for their marks
     * @param  list<string>  $timezones
     */
    public function __construct(
        public readonly array $windows,
        public readonly PaginationData $windows_pagination,
        public readonly bool $can_manage,
        public readonly array $tags,
        public readonly array $timezones,
        public readonly array $window_clients,
        public readonly array $filters,
    ) {}
}
