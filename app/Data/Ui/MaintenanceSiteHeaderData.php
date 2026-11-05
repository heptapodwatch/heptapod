<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class MaintenanceSiteHeaderData extends Data
{
    public function __construct(
        public readonly ?int $id,
        public readonly string $state,
        public readonly ?string $startsAt,
        public readonly ?string $endsAt,
        public readonly ?string $timezone,
        public readonly ?string $reason,
        public readonly bool $muteAlerts,
    ) {}
}
