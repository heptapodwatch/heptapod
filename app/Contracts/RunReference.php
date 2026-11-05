<?php

namespace Heptapod\Contracts;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Attributes\WithTransformer;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Transformers\DateTimeInterfaceTransformer;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Physical identity of a retained run. A sequence is not a unique constraint on id alone. */
#[TypeScript]
final class RunReference extends Data
{
    public function __construct(
        public readonly int $id,
        #[WithCast(DateTimeInterfaceCast::class, format: 'Y-m-d\\TH:i:s.uP')]
        #[WithTransformer(DateTimeInterfaceTransformer::class, format: 'Y-m-d\\TH:i:s.uP', setTimeZone: 'UTC')]
        public readonly CarbonImmutable $endedAt,
    ) {}
}
