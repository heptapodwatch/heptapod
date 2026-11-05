<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Contracts\CheckKey;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class SiteProbeData extends Data
{
    /** @param list<CheckKey> $recommended_checks */
    public function __construct(
        public readonly ?string $final_url,
        public readonly ?int $status,
        public readonly ?string $server,
        public readonly ?string $certificate_issuer,
        public readonly ?string $certificate_expires_at,
        public readonly ?string $certificate_unavailable_reason,
        public readonly ?string $sitemap_url,
        public readonly ?string $failure,
        public readonly bool $public_html = false,
        public readonly array $recommended_checks = [],
    ) {}
}
