<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Lazy\ClosureLazy;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class RunsIndexPageData extends Data
{
    /**
     * @param  ClosureLazy|list<CurrentRunData>  $current
     * @param  ClosureLazy|list<FinishedRunData>  $finished
     * @param  ClosureLazy|list<RunRowData>  $runs
     * @param  ClosureLazy|list<ClientData>  $clients
     */
    public function __construct(
        public readonly ClosureLazy|array $current,
        public readonly ClosureLazy|int $queued_more,
        public readonly ClosureLazy|array $finished,
        public readonly ClosureLazy|array $runs,
        public readonly ClosureLazy|string|null $next_cursor,
        public readonly ClosureLazy|array $clients,
        public readonly RunsFilterData $filters,
    ) {}
}
