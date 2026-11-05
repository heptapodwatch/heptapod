<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class RunsHistoryPageData extends Data
{
    /** @param list<RunRowData> $runs */
    public function __construct(public readonly array $runs, public readonly ?string $next_cursor) {}
}
