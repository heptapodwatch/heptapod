<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Alert thresholds: the instance defaults from config, or a server's own (its settings over the defaults). Disk and RAM
 * used: at or above the percent Failed, within 10 points below Warning. Load: the 5-minute load against load_per_core x
 * vCPU, Warning from 75 % of that. Each holds for its minutes before it alerts.
 */
#[TypeScript]
final class ServerThresholdsData extends Data
{
    /** @param list<array{mount: string, percent: int, minutes: int}> $mounts the mounts whose disk threshold differs from disk_percent and disk_minutes */
    public function __construct(
        public readonly int $disk_percent,
        public readonly int $disk_minutes,
        public readonly array $mounts,
        public readonly int $memory_percent,
        public readonly int $memory_minutes,
        public readonly float $load_per_core,
        public readonly int $load_minutes,
        public readonly int $no_report_minutes,
    ) {}

    public static function defaults(): self
    {
        return new self(config()->integer('servers.thresholds.disk.percent'), config()->integer('servers.thresholds.disk.minutes'), [],
            config()->integer('servers.thresholds.memory.percent'), config()->integer('servers.thresholds.memory.minutes'),
            config()->float('servers.thresholds.load.per_core'), config()->integer('servers.thresholds.load.minutes'),
            config()->integer('servers.thresholds.no_report_minutes'));
    }

    /**
     * The server's thresholds: what its settings store (only what differs from the defaults) over the defaults.
     *
     * @param  array{mounts?: list<array{mount: string, percent: int, minutes: int}>, memory_percent?: int, memory_minutes?: int, load_per_core?: int|float, load_minutes?: int, no_report_minutes?: int}  $settings
     */
    public static function of(array $settings): self
    {
        $defaults = self::defaults();

        return new self($defaults->disk_percent, $defaults->disk_minutes, $settings['mounts'] ?? [],
            $settings['memory_percent'] ?? $defaults->memory_percent, $settings['memory_minutes'] ?? $defaults->memory_minutes,
            (float) ($settings['load_per_core'] ?? $defaults->load_per_core), $settings['load_minutes'] ?? $defaults->load_minutes,
            $settings['no_report_minutes'] ?? $defaults->no_report_minutes);
    }
}
