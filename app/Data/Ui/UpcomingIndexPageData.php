<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class UpcomingIndexPageData extends Data
{
    /**
     * @param  list<UpcomingItemData>  $items
     * @param  list<ClientData>  $clients
     */
    public function __construct(
        public readonly array $items,
        public readonly string $days,
        public readonly string $type,
        public readonly ?int $client_id,
        public readonly string $calendar_now,
        public readonly array $clients,
    ) {}
}
