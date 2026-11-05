<?php

namespace Heptapod\Contracts\OhDear;

use Heptapod\Contracts\CheckResult;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class CheckSummaryData extends Data
{
    public readonly ?string $result;

    public function __construct(?CheckResult $result, public readonly string $summary)
    {
        $this->result = $result === CheckResult::Errored ? CheckResult::Failed->value : $result?->value;
    }
}
