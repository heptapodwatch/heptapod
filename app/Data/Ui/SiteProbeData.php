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
        public readonly ?string $normalised_url = null,
        public ?string $duplicate_message = null,
        public ?int $duplicate_site_id = null,
        public ?string $warning_site_name = null,
        public ?int $warning_site_id = null,
        public readonly ?string $failure_reason = null,
    ) {}
}
