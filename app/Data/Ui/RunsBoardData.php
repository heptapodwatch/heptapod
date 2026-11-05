<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** The /runs board: Queued and Running cards, the queued remainder, and the newest finished runs. */
#[TypeScript]
final class RunsBoardData extends Data
{
    /**
     * @param  list<CurrentRunData>  $current  the shown queued attempts (in queue order) and every running one
     * @param  list<FinishedRunData>  $finished  the newest finished runs in the filter, for the Finished column
     */
    public function __construct(
        public readonly array $current,
        public readonly int $queued_more,
        public readonly array $finished,
    ) {}
}
