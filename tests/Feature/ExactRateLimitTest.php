<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

// Tests run on the array cache. These run the default cache, and with it the rate limiter, on Redis as production
// does, under a cache prefix of their own, so the atomic scripts decide and no other test or shard shares a key.
beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-31T12:00:00Z'));
    config(['cache.prefix' => 'exact-rate-limit-test:'.Str::uuid().':']);
    Cache::purge('redis');
    Cache::store()->setStore(Cache::store('redis')->getStore());
    $this->rateLimitCounter = static fn (string $key): ?string => Redis::connection('cache')->get(config('cache.prefix').$key) ?: null;
});

afterEach(function (): void {
    $connection = Redis::connection('cache');
    $connectionPrefix = config()->string('database.redis.options.prefix');
    foreach ($connection->keys(config('cache.prefix').'*') as $key) {
        $connection->del(substr((string) $key, strlen($connectionPrefix)));
    }
});

it('admits exactly five wrong login attempts per email and IP, then rejects without counting the rejection', function (): void {
    $user = User::factory()->create();
    $key = md5('login'.Str::lower($user->email).'|127.0.0.1');
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $this->postJson(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertUnprocessable()->assertHeader('X-RateLimit-Limit', '5')->assertHeader('X-RateLimit-Remaining', (string) (5 - $attempt));
    }
    foreach (['wrong-password', 'password'] as $password) {
        $this->postJson(route('login.store'), ['email' => $user->email, 'password' => $password])->assertTooManyRequests()
            ->assertHeader('Retry-After', '60')->assertHeader('X-RateLimit-Remaining', '0')->assertJsonPath('message', 'Too Many Attempts.');
    }

    $this->assertGuest();
    expect(($this->rateLimitCounter)($key))->toBe('5');
});

it('rejects an API request over its token budget without spending the user budget the request would also use', function (): void {
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $limited = $owner->createToken('limited', ['identity:read']);
    $other = $owner->createToken('other', ['identity:read']);
    RateLimiter::increment(md5('apitoken:'.$limited->accessToken->id), amount: 60);

    $this->withToken($limited->plainTextToken)->getJson('/api/me')->assertTooManyRequests()
        ->assertHeader('Retry-After', '60')->assertJsonPath('error', 'Too Many Attempts.');
    expect(($this->rateLimitCounter)(md5('apiuser:'.$owner->id)))->toBeNull();

    $this->withToken($other->plainTextToken)->getJson('/api/me')->assertOk();
    expect([($this->rateLimitCounter)(md5('apiuser:'.$owner->id)), ($this->rateLimitCounter)(md5('apitoken:'.$other->accessToken->id))])
        ->toBe(['1', '1']);
});
