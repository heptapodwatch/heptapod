<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ErrorPageData extends Data
{
    public function __construct(
        public readonly int $status,
        public readonly string $title,
        public readonly string $description,
        public readonly string $recovery_url,
        public readonly string $recovery_label,
        public readonly ?string $secondary_url = null,
        public readonly ?string $secondary_label = null,
        public readonly ?string $code = null,
        public readonly ?int $retry_after = null,
    ) {}
}
