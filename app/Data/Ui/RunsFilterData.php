<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\RunPhase;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class RunsFilterData extends Data
{
    public function __construct(
        public readonly ?int $client,
        public readonly ?int $check,
        public readonly ?CheckKey $type,
        public readonly ?RunPhase $state,
        public readonly ?int $before,
        public readonly bool $show_same_result,
        #[LiteralTypeScriptType("'24h' | '7d'")]
        public readonly string $period,
    ) {}
}
