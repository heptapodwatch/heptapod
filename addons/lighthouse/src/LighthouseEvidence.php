<?php

declare(strict_types=1);

namespace Heptapod\Addons\Lighthouse;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class LighthouseEvidence extends Data
{
    /**
     * @param  list<array{category: string, actualScore: int, notificationThreshold: int}>  $issues
     * @param  list<array{category: string, previousScore: int, actualScore: int}>  $score_drops
     */
    public function __construct(
        public readonly array $issues,
        public readonly array $score_drops,
        public readonly string $performed_on_checker_server = 'local',
        public readonly ?string $device_emulation = null,
        public readonly bool $load_timed_out = false,
    ) {}
}
