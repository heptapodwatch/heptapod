<?php

declare(strict_types=1);

namespace Heptapod\Console\Commands;

use Carbon\CarbonImmutable;
use Heptapod\SystemHealth\FinishedCheckAge;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Uri;
use RuntimeException;

#[Signature('instance:heartbeat')]
#[Description('Ping the configured external instance heartbeat endpoint')]
final class InstanceHeartbeatCommand extends Command
{
    public function handle(FinishedCheckAge $finishedChecks): int
    {
        $url = config('instance.heartbeat_url');

        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new RuntimeException('INSTANCE_HEARTBEAT_URL must be an HTTP(S) URL.');
        }

        $age = $finishedChecks->seconds(CarbonImmutable::now('UTC'));
        // Explicit null distinguishes an inactive instance from an old heartbeat producer without this signal.
        $response = Http::connectTimeout(5)->timeout(10)->get((string) Uri::of($url)->withQuery(['check_age_seconds' => $age ?? 'null']));
        if (! $response->successful()) {
            // Never log the configured URL: its path/query can contain the heartbeat secret.
            Log::error('Instance heartbeat rejected.', ['status' => $response->status()]);
            $response->throwUnlessStatus(fn (int $status): bool => $status >= 200 && $status < 300);
        }

        Cache::store('redis')->forever(config()->string('heptapod.health.external_ping_cache_key'), now()->toIso8601String());

        return self::SUCCESS;
    }
}
