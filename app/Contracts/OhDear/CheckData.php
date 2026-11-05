<?php

namespace Heptapod\Contracts\OhDear;

use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckResult;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class CheckData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly CheckKey $type,
        public readonly string $label,
        public readonly bool $enabled,
        public readonly ?string $latest_run_ended_at,
        public readonly CheckResult $latest_run_result,
        public readonly ?string $summary,
        public readonly object $settings,
        public readonly ?SnoozeData $active_snooze,
        public readonly float|int|null|Optional $average_response_time_in_ms = new Optional,
        public readonly ?string $unavailable_reason = null,
    ) {}
}
