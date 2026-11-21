<?php

declare(strict_types=1);

namespace Heptapod\Support;

use Heptapod\Models\Check;
use Heptapod\Models\Site;

final readonly class RedactionContext
{
    /** @param array<array-key, mixed> $secrets
     * @param  array<array-key, mixed>  $urls
     */
    private function __construct(public array $secrets, public array $urls) {}

    /** @param array<array-key, mixed> $evidence */
    public static function forSite(Site $site, ?Check $check, array $evidence = []): self
    {
        $checkSecrets = $check instanceof Check ? [$check->config_secrets ?? [], $check->run_headers ?? []] : [[], []];

        return new self(
            [...$checkSecrets, array_column($site->crawler_headers ?? [], 'value')],
            [$site->url, $check?->executionConfig() ?? [], $evidence],
        );
    }
}
