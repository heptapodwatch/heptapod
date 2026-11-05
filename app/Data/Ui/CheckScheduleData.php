<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class CheckScheduleData extends Data
{
    public function __construct(
        public readonly int $interval_seconds,
        public readonly ?string $next_due_at,
        public readonly ?string $last_started_at,
        public readonly ?string $last_finished_at,
        public readonly ?int $last_duration_ms,
        public readonly CheckRunStateData $run,
        public readonly CheckFreshnessData $freshness,
        /** @var array{cause: 'lease_lost'|'did_not_start'|'worker_out_of_memory'|'timed_out'}|null */
        #[LiteralTypeScriptType("{ cause: 'lease_lost' | 'did_not_start' | 'worker_out_of_memory' | 'timed_out' } | null")]
        public readonly ?array $last_loss,
    ) {}
}
