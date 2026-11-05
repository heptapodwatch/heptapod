<?php

declare(strict_types=1);

namespace Heptapod\Addons\Lighthouse;

use Heptapod\Contracts\CheckDetailData;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\RunResult;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class LighthouseDetail extends CheckDetailData
{
    public function __construct(
        public readonly RunResult $result,
        public readonly ?LighthouseMetrics $metrics,
        public readonly ?LighthouseEvidence $evidence,
    ) {
        parent::__construct(CheckKey::Lighthouse);
    }
}
