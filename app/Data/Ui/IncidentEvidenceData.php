<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class IncidentEvidenceData extends Data
{
    /**
     * @param  array<string, mixed>  $facts
     * @param  array<string, string|int|float|bool|null>  $summary_params
     * @param  array<string, string|int|float|bool|null>  $recovery_summary_params
     */
    public function __construct(
        public readonly array $facts,
        public readonly ?string $summary,
        public readonly array $summary_params,
        public readonly ?string $opened_at,
        public readonly ?string $recovered_at,
        public readonly ?string $recovery_summary,
        public readonly array $recovery_summary_params,
        public readonly ?int $retained_good_results_since_recovery,
        public readonly bool $good_results_capped,
    ) {}
}
