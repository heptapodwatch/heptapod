<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Contracts\CheckKey;
use Heptapod\Enums\IncidentCause;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class IncidentFilterData extends Data
{
    public function __construct(
        /** open (the default), resolved or all. */
        public readonly string $status,
        public readonly ?IncidentCause $cause,
        public readonly ?CheckKey $check,
        public readonly ?int $client_id,
        public readonly ?string $started_after,
        public readonly ?string $started_before,
        public readonly string $period = '30d',
    ) {}
}
