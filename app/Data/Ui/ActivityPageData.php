<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Contracts\ListFilterData;
use Heptapod\Contracts\PaginationData;
use Inertia\OptionalProp;
use Inertia\ScrollProp;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ActivityPageData extends Data
{
    /**
     * @param  array<string, ListFilterData>  $filters
     * @param  ScrollProp<mixed>|ActivityBatchData  $events  Infinite scroll of ActivityBatchData, newest first; cursor `before`.
     * @param  OptionalProp|ActivityBatchData|null  $head  The newest batch, sent only on live reloads.
     * @param  list<EventData>  $expanded_events
     */
    public function __construct(
        #[LiteralTypeScriptType('Heptapod.Data.Ui.ActivityBatchData')]
        public readonly ScrollProp|ActivityBatchData $events,
        #[LiteralTypeScriptType('Heptapod.Data.Ui.ActivityBatchData | undefined')]
        public readonly OptionalProp|ActivityBatchData|null $head,
        public readonly int $retention_days,
        public readonly array $filters,
        public readonly ?int $expanded_id,
        public readonly array $expanded_events,
        public readonly ?PaginationData $expanded_pagination,
    ) {}
}
