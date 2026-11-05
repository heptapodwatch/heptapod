<?php

declare(strict_types=1);

namespace Heptapod\Addons\CertificateHealth;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class CertificateMetrics extends Data
{
    public function __construct(
        public readonly ?string $issuer,
        public readonly ?string $fingerprint,
        public readonly bool $is_valid,
    ) {}
}
