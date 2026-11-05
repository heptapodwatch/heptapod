<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class CheckFreshnessData extends Data
{
    public function __construct(
        /** @var 'fresh'|'not_fresh'|'not_expected' */
        #[LiteralTypeScriptType("'fresh' | 'not_fresh' | 'not_expected'")]
        public readonly string $state,
        public readonly ?string $awaiting_result_since,
        public readonly ?string $not_fresh_since,
        public readonly int $threshold_seconds,
        public readonly int $lost_attempts,
    ) {}
}
