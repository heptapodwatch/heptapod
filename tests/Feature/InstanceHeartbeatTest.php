<?php

declare(strict_types=1);

use Heptapod\SystemHealth\ReadSystemHealth;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

it('pings the configured instance heartbeat endpoint', function (): void {
    config(['instance.heartbeat_url' => 'https://heartbeat.example.com/instance-token']);
    Http::fake(['https://heartbeat.example.com/instance-token*' => Http::sequence()->push('', 204)->push('', 503)]);

    $this->freezeTime();
    $key = 'heartbeat-test:'.Str::uuid();
    config(['heptapod.health.external_ping_cache_key' => $key]);
    try {
        $this->artisan('instance:heartbeat')->assertSuccessful();
        expect(app(ReadSystemHealth::class)()->lastExternalPing)->toBe(now()->toIso8601String());
        $this->travel(1)->minutes();
        expect(fn () => $this->artisan('instance:heartbeat')->run())->toThrow(RequestException::class);
        expect(app(ReadSystemHealth::class)()->lastExternalPing)->toBe(now()->subMinute()->toIso8601String());
    } finally {
        Cache::store('redis')->forget($key);
    }

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://heartbeat.example.com/instance-token?check_age_seconds=null');
});

it('rejects a final non-2xx heartbeat response', function (int $status): void {
    config(['instance.heartbeat_url' => 'https://heartbeat.example.com/instance-token']);
    Http::fake(['https://heartbeat.example.com/instance-token*' => Http::response('', $status)]);

    $this->artisan('instance:heartbeat');
})->with([302, 304, 503])->throws(RequestException::class);

it('rejects a missing or invalid heartbeat endpoint', function (?string $url): void {
    config(['instance.heartbeat_url' => $url]);
    Http::preventStrayRequests();

    $this->artisan('instance:heartbeat');
})->with([null, '', 'file:///etc/passwd'])->throws(RuntimeException::class);

it('sends the heartbeat over plain HTTP only to a listed private host', function (): void {
    config(['instance.heartbeat_url' => 'http://watchdog.lan/instance-token']);
    Http::preventStrayRequests();
    Http::fake(['http://watchdog.lan/instance-token*' => Http::response('', 204)]);
    $key = 'heartbeat-test:'.Str::uuid();
    config(['heptapod.health.external_ping_cache_key' => $key]);
    try {
        expect(fn () => $this->artisan('instance:heartbeat')->run())
            ->toThrow(RuntimeException::class, 'INSTANCE_HEARTBEAT_URL must use HTTPS; plain HTTP works only for a host listed in HEPTAPOD_DELIVERY_PRIVATE_HOSTS.');
        Http::assertNothingSent();
        config(['heptapod.outbound.delivery_private_hosts' => ['watchdog.lan']]);
        $this->artisan('instance:heartbeat')->assertSuccessful();
        Http::assertSentCount(1);
    } finally {
        Cache::store('redis')->forget($key);
    }
});

it('does not follow a heartbeat redirect or count it as a ping', function (): void {
    config(['heptapod.outbound.delivery_private_hosts' => ['fixtures.test'], 'instance.heartbeat_url' => 'http://fixtures.test/redirect/start']);
    $key = 'heartbeat-test:'.Str::uuid();
    config(['heptapod.health.external_ping_cache_key' => $key]);
    try {
        expect(fn () => $this->artisan('instance:heartbeat')->run())->toThrow(RequestException::class, 'HTTP request returned status code 301');
        expect(app(ReadSystemHealth::class)()->lastExternalPing)->toBeNull();
    } finally {
        Cache::store('redis')->forget($key);
    }
});
