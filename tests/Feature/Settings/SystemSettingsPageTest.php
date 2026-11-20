<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Http\ListPageSize;
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
    config(['heptapod.health.browser_urls' => ['lighthouse' => 'http://private-probe.test/health']]);
    Http::preventStrayRequests();
    $probes = 0;
    // Pooled requests that fail to connect are not recorded, so the fake counts the probes itself.
    Http::fake(['http://private-probe.test/health' => function () use (&$probes) {
        $probes++;

        return Http::failedConnection('private-probe-detail');
    }]);
    Cache::store('redis')->forget(config('heptapod.health.scheduler_cache_key'));

    $response = $this->actingAs($user)->get('/settings/system')->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->component('settings/system')->where('auth.canViewSystemHealth', true)
        ->where('systemPage.observed_at', '2026-10-06T12:00:00+00:00')
        ->where('systemPage.health.scheduler.value', null)->where('systemPage.health.dispatcher.value', 200)
        ->where('systemPage.health.dispatcher.status', 'down')->where('systemPage.health.recentFailedJobs.value', 1)
        ->where('systemPage.health.failedJobsTotal', 1)->where('systemPage.health.databaseBytes', fn (int $size): bool => $size > 0)
        ->where('systemPage.health.browserServices.lighthouse.status', 'down'));
    expect($response->getContent())->not->toContain('private-probe', 'private-exception');
    $json = $this->getJson('/settings/system/data')->assertOk()
        ->assertJsonPath('observed_at', '2026-10-06T12:00:00+00:00')
        ->assertJsonPath('health.dispatcher.value', 200)->assertJsonPath('health.recentFailedJobs.value', 1)
        ->assertJsonMissingPath('health.browserServices.lighthouse.error')->assertJsonMissingPath('health.reverb.error');
    expect($json->getContent())->not->toContain('private-probe', 'private-exception');
    expect($probes)->toBe(2);
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

it('pages open system issues with remembered size and keeps refresh pager URLs on the system page', function (): void {
    $owner = User::factory()->owner()->create();
    foreach (range(1, 12) as $i) {
        DB::table('system_issues')->insert([
            'kind' => 'check_not_fresh',
            'scope' => (string) $i,
            'details' => json_encode(['site_id' => 1, 'check_id' => $i, 'site_name' => 'Site '.$i, 'check_type' => 'uptime'], JSON_THROW_ON_ERROR),
            'opened_at' => now()->subMinutes($i),
        ]);
    }

    $this->actingAs($owner)->get('/settings/system?system_issues_per_page=10')
        ->assertOk()
        ->assertCookie(ListPageSize::Cookie, json_encode(['system_issues' => 10]), false)
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('systemPage.health.systemIssues', 10)
            ->where('systemPage.system_issues_pagination.total', 12)
            ->where('systemPage.system_issues_pagination.last_page', 2)
            ->where('systemPage.system_issues_pagination.page_name', 'system_issues_page'));

    $this->withUnencryptedCookie(ListPageSize::Cookie, json_encode(['system_issues' => 10]));
    $this->get('/settings/system?system_issues_page=2')->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->has('systemPage.health.systemIssues', 2));

    $refresh = $this->withCredentials()
        ->withUnencryptedCookie(ListPageSize::Cookie, json_encode(['system_issues' => 10]))
        ->getJson('/settings/system/data?system_issues_page=2')
        ->assertOk()
        ->assertJsonCount(2, 'health.systemIssues');
    expect((string) $refresh->json('system_issues_pagination.previous_page_url'))->toContain('/settings/system?');
});
