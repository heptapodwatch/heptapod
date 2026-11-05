<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Contracts\CheckKey;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ActivityFilterData extends Data
{
    public function __construct(
        public readonly string $q,
        public readonly ?int $client,
        public readonly ?CheckKey $check,
        public readonly string $period,
        public readonly ?int $before,
        public readonly ?int $after,
    ) {}
}
