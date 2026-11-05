<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ActivityPageData extends Data
{
    /**
     * @param  list<ActivityGroupData>  $events
     * @param  list<EventData>  $expanded_events
     * @param  list<ClientData>  $clients
     */
    public function __construct(
        public readonly array $events,
        public readonly ?int $older_cursor,
        public readonly ?int $newer_cursor,
        public readonly int $retention_days,
        public readonly array $clients,
        public readonly ActivityFilterData $filters,
        public readonly ?int $expanded_id,
        public readonly array $expanded_events,
        public readonly int $event_page,
    ) {}
}
