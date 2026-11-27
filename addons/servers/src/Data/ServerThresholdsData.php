<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** The instance-wide default thresholds from config; used % at or above them is Failed, within 10 points below is Warning. */
#[TypeScript]
final class ServerThresholdsData extends Data
{
    public function __construct(
        public readonly int $disk_used_percent,
        public readonly int $memory_used_percent,
    ) {}

    public static function defaults(): self
    {
        return new self(config()->integer('servers.thresholds.disk_used_percent'), config()->integer('servers.thresholds.memory_used_percent'));
    }
}
