<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Models\User;

it('limits passkey login option requests per IP even when cookies are cleared', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-31T12:00:00Z'));

    for ($attempt = 1; $attempt <= 20; $attempt++) {
        $this->flushSession();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.40'])
            ->getJson(route('passkey.login-options'))
            ->assertOk();
    }

    $this->flushSession();
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.40'])
        ->getJson(route('passkey.login-options'))
        ->assertTooManyRequests()
        ->assertHeader('Retry-After')
        ->assertJsonPath('code', 'rate_limited');

    // A separate IP keeps its own budget.
    $this->flushSession();
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.41'])
        ->getJson(route('passkey.login-options'))
        ->assertOk();
});

it('limits passkey login assertions per IP when credential IDs are rotated', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-31T12:00:00Z'));

    for ($attempt = 1; $attempt <= 20; $attempt++) {
        $this->flushSession();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.50'])
            ->postJson(route('passkey.login'), [
                'credential' => ['id' => 'rotated-credential-'.$attempt],
            ]);
    }

    $this->flushSession();
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.50'])
        ->postJson(route('passkey.login'), [
            'credential' => ['id' => 'rotated-credential-21'],
        ])
        ->assertTooManyRequests()
        ->assertHeader('Retry-After')
        ->assertJsonPath('code', 'rate_limited');
});

it('limits authenticated passkey confirmation per user across rotating IPs and credential IDs', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-31T12:00:00Z'));
    $user = User::factory()->create();
    $this->actingAs($user);

    for ($attempt = 1; $attempt <= 10; $attempt++) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.$attempt])
            ->postJson(route('passkey.confirm'), [
                'credential' => ['id' => 'user-rotated-'.$attempt],
            ]);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
        ->postJson(route('passkey.confirm'), [
            'credential' => ['id' => 'user-rotated-11'],
        ])
        ->assertTooManyRequests()
        ->assertHeader('Retry-After')
        ->assertJsonPath('code', 'rate_limited');
});
