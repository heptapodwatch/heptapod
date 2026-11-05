<?php

declare(strict_types=1);

namespace Heptapod\Addons\Uptime;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class UptimeMetrics extends Data
{
    /** @param array<string, int|float|null> $curl */
    public function __construct(
        public readonly ?float $dns_time_in_seconds,
        public readonly ?float $tcp_time_in_seconds,
        public readonly ?float $ssl_handshake_time_in_seconds,
        public readonly ?float $remote_server_processing_time_in_seconds,
        public readonly ?float $download_time_in_seconds,
        public readonly ?float $total_time_in_seconds,
        public readonly array $curl,
        public readonly string $date,
    ) {}
}
