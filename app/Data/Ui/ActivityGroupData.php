<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Contracts\CheckResult;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ActivityGroupData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly EventData $latest,
        public readonly int $event_count,
        public readonly string $started_at,
        public readonly string $ended_at,
        public readonly ?CheckResult $first_state,
        public readonly ?CheckResult $second_state,
        public readonly ?CheckResult $current_state,
        /** The failure this recovery ends, shown in the same row ("Failed → Recovered after 3m"); null when unpaired. */
        public readonly ?EventData $earlier = null,
    ) {}
}
