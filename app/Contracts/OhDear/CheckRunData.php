<?php

declare(strict_types=1);

namespace Heptapod\Contracts\OhDear;

use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\RunResult;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class CheckRunData extends Data
{
    public readonly string $result;

    public function __construct(
        public readonly int $id,
        public readonly int $check_id,
        public readonly CheckKey $check_type,
        RunResult $result,
        public readonly ?string $started_at,
        public readonly string $ended_at,
        public readonly string $ohdear_url,
    ) {
        $this->result = $result->ohDearValue();
    }
}
