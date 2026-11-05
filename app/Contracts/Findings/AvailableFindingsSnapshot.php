<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Findings;

use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\RunReference;
use Spatie\LaravelData\Attributes\Hidden;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\Hidden as TypeScriptHidden;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class AvailableFindingsSnapshot extends Data
{
    /** @param list<FindingStatus> $supported_filters */
    public function __construct(
        public readonly int $check_id,
        public readonly CheckKey $type,
        public readonly string $snapshot_ref,
        public readonly RunReference $run,
        public readonly string $observed_at,
        public readonly FindingsCoverage $coverage,
        public readonly array $supported_filters,
        public readonly bool $supported_baseline_comparison,
        public readonly int $dataset_version,
        #[Hidden, TypeScriptHidden] public readonly ?string $comparison_key,
    ) {}

    public function comparableWith(self $baseline): bool
    {
        return $this->check_id === $baseline->check_id && $this->type === $baseline->type
            && $this->comparison_key !== null && $this->comparison_key === $baseline->comparison_key
            && $this->supported_baseline_comparison && $baseline->supported_baseline_comparison;
    }
}
