<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

it('allows shared NAT passkey login bursts up to 120/min per IP and blocks beyond it without creating Redis sessions', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-31T12:00:00Z'));
    $ip = '198.51.100.40';

    // 12 concurrent sign-ins (options + assertion = 24 requests) behind one NAT IP stay well within the 120/min budget.
    for ($attempt = 1; $attempt <= 24; $attempt++) {
        $this->flushSession();
        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->getJson(route('passkey.login-options'))
            ->assertOk();
    }

    // Exercise the real Redis session store on the throttled request to prove 429 happens before StartSession.
    $prefix = 'passkey-session-test:'.Str::uuid().':';
    config([
        'session.driver' => 'redis',
        'session.connection' => 'cache',
        'cache.prefix' => $prefix,
    ]);
    Cache::purge('redis');
    app('session')->forgetDrivers();

    $connection = Redis::connection('cache');
    $connectionPrefix = config()->string('database.redis.options.prefix');
    try {
        RateLimiter::increment(md5('passkeys-entryip:'.$ip), amount: 96);

        $this->flushSession();
        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->getJson(route('passkey.login-options'))
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertJsonPath('code', 'rate_limited');

        expect($connection->keys($prefix.'*'))->toBeEmpty();

        // A separate IP keeps its own budget.
        $this->flushSession();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.41'])
            ->getJson(route('passkey.login-options'))
            ->assertOk();
    } finally {
        foreach ($connection->keys($prefix.'*') as $key) {
            $connection->del(substr((string) $key, strlen($connectionPrefix)));
        }
    }
});

it('limits a single passkey credential ID globally across rotating IPs', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-31T12:00:00Z'));

    for ($attempt = 1; $attempt <= 10; $attempt++) {
        $this->flushSession();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.(60 + $attempt)])
            ->postJson(route('passkey.login'), [
                'credential' => ['id' => 'shared-credential-id'],
            ])
            ->assertUnprocessable();
    }

    $this->flushSession();
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.99'])
        ->postJson(route('passkey.login'), [
            'credential' => ['id' => 'shared-credential-id'],
        ])
        ->assertTooManyRequests()
        ->assertHeader('Retry-After')
        ->assertJsonPath('code', 'rate_limited');
});

it('handles malformed non-string credential IDs without 500 and still enforces the IP limit', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-31T12:00:00Z'));
    $ip = '198.51.100.52';

    $this->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson(route('passkey.login'), [
            'credential' => ['id' => ['not-a-string']],
        ])
        ->assertUnprocessable();

    RateLimiter::increment(md5('passkeys-entryip:'.$ip), amount: 119);

    $this->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson(route('passkey.login'), [
            'credential' => ['id' => ['not-a-string']],
        ])
        ->assertTooManyRequests()
        ->assertHeader('Retry-After')
        ->assertJsonPath('code', 'rate_limited');
});

it('limits authenticated passkey confirmation per user across rotating IPs and credential IDs', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-31T12:00:00Z'));
    $user = User::factory()->create();
    $this->actingAs($user);

    for ($attempt = 1; $attempt <= 15; $attempt++) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.$attempt])
            ->postJson(route('passkey.confirm'), [
                'credential' => ['id' => 'user-rotated-'.$attempt],
            ])
            ->assertUnprocessable();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
        ->postJson(route('passkey.confirm'), [
            'credential' => ['id' => 'user-rotated-16'],
        ])
        ->assertTooManyRequests()
        ->assertHeader('Retry-After')
        ->assertJsonPath('code', 'rate_limited');
});
