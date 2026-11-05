<?php

declare(strict_types=1);

namespace Heptapod\Addons\ApplicationHealth;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ApplicationHealthComponent extends Data
{
    /** @param array<int|string, mixed> $meta */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $label,
        public readonly string $status,
        public readonly string $notificationMessage,
        public readonly string $shortSummary,
        public readonly array $meta,
        public readonly string $latest_result,
    ) {}
}
