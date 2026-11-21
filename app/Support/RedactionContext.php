<?php

declare(strict_types=1);

namespace Heptapod\Support;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\RedactedQueryTarget;
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
        $config = $check?->executionConfig() ?? [];
        $legacyTargets = [];
        if ($check instanceof Check && $check->type === CheckKey::Sitemap && is_string($config['path'] ?? null) && str_contains($config['path'], '?')) {
            $resolved = UriResolver::resolve(new Uri($site->url), new Uri($config['path']))->withUserInfo('')->withFragment('');
            $legacyTargets[] = new RedactedQueryTarget((string) $resolved, (string) $resolved->withQuery(''));
            $legacyTargets[] = new RedactedQueryTarget($config['path'], (string) strstr($config['path'], '?', true));
        }

        return new self(
            [...$checkSecrets, array_column($site->crawler_headers ?? [], 'value')],
            [...$legacyTargets, $site->url, $config, $evidence],
        );
    }
}
