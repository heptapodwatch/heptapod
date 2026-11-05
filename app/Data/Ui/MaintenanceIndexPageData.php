<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class MaintenanceIndexPageData extends Data
{
    /**
     * @param  list<MaintenanceWindowData>  $windows
     * @param  list<MaintenanceTargetOptionData>  $sites
     * @param  list<MaintenanceTargetOptionData>  $clients
     * @param  list<MaintenanceTargetOptionData>  $tags
     * @param  list<MaintenanceTargetOptionData>  $filter_clients
     * @param  list<string>  $timezones
     */
    public function __construct(
        public readonly array $windows,
        public readonly ?int $next_page,
        public readonly bool $can_manage,
        public readonly string $timezone,
        public readonly array $sites,
        public readonly array $clients,
        public readonly array $tags,
        public readonly string $instance_timezone,
        public readonly array $timezones,
        public readonly array $filter_clients = [],
        public readonly ?int $client_id = null,
        public readonly ?int $site_id = null,
        public readonly ?string $site_name = null,
        public readonly string $state = 'all',
        public readonly ?int $previous_page = null,
        public readonly int $total = 0,
    ) {}
}
