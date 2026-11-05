<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Immutable snapshot; also used by the bounded history reader. */
#[TypeScript]
final class PreviousResult extends Data
{
    /** @param array<string, mixed>|null $metrics
     * @param  array<string, int|float|string|bool|null>  $summaryParams
     */
    public function __construct(
        public readonly RunReference $run,
        public readonly RunResult $result,
        public readonly ?CarbonImmutable $startedAt,
        public readonly string $summary,
        public readonly array $summaryParams,
        public readonly ?array $metrics,
    ) {}
}
