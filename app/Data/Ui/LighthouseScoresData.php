<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** The category scores (0–100) of a site's latest Lighthouse run. */
#[TypeScript]
final class LighthouseScoresData extends Data
{
    public function __construct(
        public readonly int $performance,
        public readonly int $accessibility,
        public readonly int $best_practices,
        public readonly int $seo,
    ) {}
}
