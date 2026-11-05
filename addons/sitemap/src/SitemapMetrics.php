<?php

declare(strict_types=1);

namespace Heptapod\Addons\Sitemap;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class SitemapMetrics extends Data
{
    public function __construct(
        public readonly int $totalIssuesCount,
        public readonly int $totalUrlCount,
        public readonly bool $hasIssues,
    ) {}
}
