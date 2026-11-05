<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Findings;

use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\RunReference;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class UnavailableFindingsSnapshot extends Data
{
    public function __construct(
        public readonly FindingsUnavailableStatus $status,
        public readonly int $check_id,
        public readonly CheckKey $type,
        public readonly RunReference $requested_run,
        public readonly FindingsUnavailableReason $reason,
        public readonly ?FindingsCoverage $coverage = null,
    ) {}
}
