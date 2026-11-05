<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Findings;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class FindingsPage extends Data
{
    /** @param list<FindingsRow> $rows */
    public function __construct(
        public readonly AvailableFindingsSnapshot $snapshot,
        public readonly ?AvailableFindingsSnapshot $baseline,
        public readonly string $baseline_status,
        public readonly array $rows,
        public readonly ?FindingsPosition $next_position,
        public readonly bool $has_more,
    ) {}
}
