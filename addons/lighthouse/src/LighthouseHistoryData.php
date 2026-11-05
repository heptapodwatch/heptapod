<?php

declare(strict_types=1);

namespace Heptapod\Addons\Lighthouse;

use Heptapod\Contracts\CheckHistoryData;
use Heptapod\Contracts\CheckKey;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class LighthouseHistoryData extends CheckHistoryData
{
    /** @param list<array{day: string, performance: float|null, accessibility: float|null, best_practices: float|null, seo: float|null}> $scores */
    public function __construct(
        public readonly ?int $last_successful_run_id,
        public readonly ?string $last_successful_at,
        public readonly ?LighthouseMetrics $last_successful_metrics,
        public readonly ?string $last_successful_device,
        public readonly array $scores,
    ) {
        parent::__construct(CheckKey::Lighthouse);
    }
}
