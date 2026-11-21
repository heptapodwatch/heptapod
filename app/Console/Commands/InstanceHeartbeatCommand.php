<?php

declare(strict_types=1);

namespace Heptapod\Console\Commands;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\Http\DeliveryHttp;
use Heptapod\Contracts\Http\DeliveryPrivateHosts;
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
    public function handle(FinishedCheckAge $finishedChecks, DeliveryHttp $deliveryHttp): int
    {
        $url = config('instance.heartbeat_url');

        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new RuntimeException('INSTANCE_HEARTBEAT_URL must be an HTTP(S) URL.');
        }
        // The URL is a capability: never send it in plain text over a network the Owner did not declare private.
        if (! DeliveryPrivateHosts::allowsScheme($url)) {
            throw new RuntimeException('INSTANCE_HEARTBEAT_URL must use HTTPS; plain HTTP works only for a host listed in HEPTAPOD_DELIVERY_PRIVATE_HOSTS.');
        }

        $age = $finishedChecks->seconds(CarbonImmutable::now('UTC'));
        // Explicit null distinguishes an inactive instance from an old heartbeat producer without this signal.
        // Guarded like a notification delivery: public targets only (or the listed host), and a redirect is a failed ping.
        $response = Http::setHandler($deliveryHttp->handler($url, 10.0))->withoutRedirecting()->connectTimeout(5)->timeout(10)
            ->get((string) Uri::of($url)->withQuery(['check_age_seconds' => $age ?? 'null']));
        if (! $response->successful()) {
            // Never log the configured URL: its path/query can contain the heartbeat secret.
            Log::error('Instance heartbeat rejected.', ['status' => $response->status()]);
            $response->throwUnlessStatus(fn (int $status): bool => $status >= 200 && $status < 300);
        }

        Cache::store('redis')->forever(config()->string('heptapod.health.external_ping_cache_key'), now()->toIso8601String());

        return self::SUCCESS;
    }
}
