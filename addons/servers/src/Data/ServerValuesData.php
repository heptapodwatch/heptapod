<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers\Data;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** The values of a server's newest sample, as the agent measured them; the filesystems without the ignored mounts. */
#[TypeScript]
final class ServerValuesData extends Data
{
    /** @param list<array{mount: string, total_bytes: int, free_bytes: int}> $filesystems */
    public function __construct(
        public readonly string $observed_at,
        public readonly int $cores,
        public readonly float $cpu_used_percent,
        public readonly float $load_5,
        public readonly int $memory_total_bytes,
        public readonly int $memory_available_bytes,
        public readonly array $filesystems,
        public readonly int $uptime_seconds,
        public readonly ?bool $reboot_required,
        public readonly ?int $updates_pending,
        public readonly ?int $updates_security,
    ) {}

    /**
     * The stored metrics were validated at ingest (ServerReportController), so their shape is trusted here.
     *
     * @param  list<string>  $ignoredMounts
     */
    public static function fromSample(string $observedAt, string $metricsJson, array $ignoredMounts): self
    {
        /** @var array{cpu: array{cores: int, used_percent: int|float}, load: array{5: int|float}, memory: array{total_bytes: int, available_bytes: int}, filesystems: list<array{mount: string, total_bytes: int, free_bytes: int}>, host: array{uptime_seconds: int, reboot_required: bool|null}, updates: array{pending: int|null, security: int|null}} $metrics */
        $metrics = json_decode($metricsJson, true, flags: JSON_THROW_ON_ERROR);

        return new self(CarbonImmutable::parse($observedAt)->toIso8601String(), $metrics['cpu']['cores'], (float) $metrics['cpu']['used_percent'],
            (float) $metrics['load'][5], $metrics['memory']['total_bytes'], $metrics['memory']['available_bytes'],
            array_values(array_filter($metrics['filesystems'], static fn (array $filesystem): bool => ! in_array($filesystem['mount'], $ignoredMounts, true))),
            $metrics['host']['uptime_seconds'], $metrics['host']['reboot_required'], $metrics['updates']['pending'], $metrics['updates']['security']);
    }
}
