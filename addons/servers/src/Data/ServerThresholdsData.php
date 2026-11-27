<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The instance-wide default thresholds from config. Disk and RAM used: at or above them Failed, within 10 points below
 * Warning. Load: the 5-minute load against load_per_core x vCPU, Warning from 75 % of that.
 */
#[TypeScript]
final class ServerThresholdsData extends Data
{
    public function __construct(
        public readonly int $disk_used_percent,
        public readonly int $memory_used_percent,
        public readonly float $load_per_core,
    ) {}

    public static function defaults(): self
    {
        return new self(config()->integer('servers.thresholds.disk_used_percent'), config()->integer('servers.thresholds.memory_used_percent'),
            config()->float('servers.thresholds.load_per_core'));
    }
}
