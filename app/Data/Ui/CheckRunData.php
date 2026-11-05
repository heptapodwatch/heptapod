<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\CheckRunPresentationData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class CheckRunData extends Data
{
    /**
     * @param  array<string, string|int|float|bool|null>  $summary_params
     */
    public function __construct(
        public readonly int $id,
        public readonly CheckResult $result,
        public readonly ?string $started_at,
        public readonly string $ended_at,
        public readonly ?string $summary,
        public readonly array $summary_params,
        public readonly ?CheckRunPresentationData $presentation = null,
    ) {}
}
