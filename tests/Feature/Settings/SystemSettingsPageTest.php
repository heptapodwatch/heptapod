<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Models\DispatcherHeartbeat;
use Heptapod\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-06T12:00:00Z'));
    config(['heptapod.health.browser_urls' => [], 'broadcasting.default' => 'log']);
});

it('projects measured health and a sample timestamp to the page and refresh endpoint without private probe errors', function (string $role): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-06T12:00:00Z'));
    $user = User::factory()->create();
    $user->assignRole($role);
    DispatcherHeartbeat::factory()->create(['last_seen_at' => now()->subSeconds(200)]);
    DB::table('failed_jobs')->insert(['uuid' => Str::uuid(), 'connection' => 'redis', 'queue' => 'checks-fast', 'payload' => '{}', 'exception' => 'private-exception', 'failed_at' => now()]);
    config(['heptapod.health.browser_urls' => ['lighthouse' => 'http://private-probe.test/health', 'gotenberg' => null]]);
    Http::preventStrayRequests();
    Http::fake(['http://private-probe.test/health' => Http::failedConnection('private-probe-detail')]);
    Cache::store('redis')->forget(config('heptapod.health.scheduler_cache_key'));

    $response = $this->actingAs($user)->get('/settings/system')->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->component('settings/system')->where('auth.canViewSystemHealth', true)
        ->where('systemPage.observed_at', '2026-10-06T12:00:00+00:00')
        ->where('systemPage.health.scheduler.value', null)->where('systemPage.health.dispatcher.value', 200)
        ->where('systemPage.health.dispatcher.status', 'down')->where('systemPage.health.recentFailedJobs.value', 1)
        ->where('systemPage.health.failedJobsTotal', 1)->where('systemPage.health.databaseBytes', fn (int $size): bool => $size > 0)
        ->where('systemPage.health.browserServices.lighthouse.status', 'down')
        ->where('systemPage.health.browserServices.gotenberg.status', 'not-configured'));
    expect($response->getContent())->not->toContain('private-probe', 'private-exception');
    $json = $this->getJson('/settings/system/data')->assertOk()
        ->assertJsonPath('observed_at', '2026-10-06T12:00:00+00:00')
        ->assertJsonPath('health.dispatcher.value', 200)->assertJsonPath('health.recentFailedJobs.value', 1)
        ->assertJsonMissingPath('health.browserServices.lighthouse.error')->assertJsonMissingPath('health.reverb.error');
    expect($json->getContent())->not->toContain('private-probe', 'private-exception');
    Http::assertSentCount(2);
})->with(['Owner', 'Admin']);

it('keeps system health page and refresh restricted to verified Owners and Admins', function (): void {
    $this->get('/settings/system')->assertRedirect('/login');
    $this->get('/settings/system/data')->assertRedirect('/login');
    $unverified = User::factory()->unverified()->create();
    $unverified->assignRole('Owner');
    $this->actingAs($unverified)->get('/settings/system')->assertRedirect('/email/verify');
    $this->get('/settings/system/data')->assertRedirect('/email/verify');
    $member = User::factory()->create();
    $member->assignRole('Member');
    $this->actingAs($member)->get('/settings/system')->assertForbidden();
    $this->getJson('/settings/system/data')->assertForbidden();
    $this->get('/settings/profile')->assertInertia(fn (Assert $page): Assert => $page->where('auth.canViewSystemHealth', false));
});

it('refreshes heartbeat observations without writing monitoring state or exposing a control endpoint', function (): void {
    $user = User::factory()->create();
    $user->assignRole('Admin');
    $heartbeat = DispatcherHeartbeat::factory()->create(['last_seen_at' => now()->subSeconds(300)]);
    $original = $heartbeat->last_seen_at->toIso8601String();
    $this->actingAs($user)->getJson('/settings/system/data')->assertJsonPath('health.dispatcher.value', 300);
    expect($heartbeat->fresh()->last_seen_at->toIso8601String())->toBe($original);
    $heartbeat->update(['last_seen_at' => now()]);
    $this->getJson('/settings/system/data')->assertJsonPath('health.dispatcher.value', 0);
    $this->postJson('/settings/system/data', ['command' => 'restart'])->assertNotFound();
    $this->postJson('/settings/system', ['command' => 'restart'])->assertNotFound();
    expect($heartbeat->fresh()->last_seen_at->toIso8601String())->toBe('2026-10-06T12:00:00+00:00');
});
