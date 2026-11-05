<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckKey;
use Heptapod\Enums\ClientScope;
use Heptapod\Models\Check;
use Heptapod\Models\DispatcherHeartbeat;
use Heptapod\Models\SystemIssue;
use Heptapod\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-26T10:00:00Z'));
    $this->withoutVite();
    Http::preventStrayRequests();
    Http::fake([]);
});

it('matches monitoring action labels and destinations to the viewers access on the real sites response', function (string $source, string $title, string $role, string $healthAction, string $healthPath, string $mailAction, string $mailPath): void {
    $user = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $user->assignRole($role);
    if ($source !== 'heartbeat') {
        DispatcherHeartbeat::query()->create(['id' => '00000000-0000-0000-0000-000000000099', 'last_seen_at' => now()]);
    }
    if ($source === 'issue') {
        SystemIssue::query()->create(['kind' => 'mail_log_only', 'scope' => '', 'details' => ['mailer' => 'log'], 'opened_at' => now()]);
    }
    if ($source === 'availability') {
        config(['heptapod.browser.enabled' => false]);
        $check = Check::factory()->create(['type' => CheckKey::Lighthouse, 'enabled' => true, 'next_run_at' => now()->addDay()]);
        $user->clients()->attach($check->site->client_id);
    }
    if ($source === 'late') {
        $check = Check::factory()->create(['next_run_at' => now()->subHour(), 'latest_run_ended_at' => null]);
        $user->clients()->attach($check->site->client_id);
    }

    $this->actingAs($user)->get(route('sites.index'))->assertInertia(function (AssertableInertia $page) use ($source, $title, $role, $healthAction, $healthPath, $mailAction, $mailPath): void {
        $page->where('mailConfigurationAction.action_key', $mailAction)->where('mailConfigurationAction.href', url($mailPath));
        if ($source === 'issue' && $role === 'Member') {
            $page->has('overallHealth.causes', 0);

            return;
        }
        $page->where('overallHealth.causes.0.title_key', $title)
            ->where('overallHealth.causes.0.action_key', $source === 'issue' ? $mailAction : $healthAction)
            ->where('overallHealth.causes.0.href', url($source === 'issue' ? $mailPath : $healthPath));
    });
})->with([
    'heartbeat' => ['heartbeat', 'The check dispatcher has not reported recently'],
    'late check' => ['late', 'Scheduled checks have no recent result'],
    'persisted mail issue' => ['issue', 'Email delivery is not configured'],
    'unavailable enabled check' => ['availability', 'lighthouse.browser_unavailable'],
])->with([
    'Owner' => ['Owner', 'Open System health', '/settings/system', 'Open mail settings', '/settings/instance'],
    'Admin' => ['Admin', 'Open System health', '/settings/system', 'Open System health', '/settings/system'],
    'Member' => ['Member', 'Learn more', 'https://heptapod.app/docs/deploy/hetzner', 'Learn more', 'https://heptapod.app/docs/notifications#email-setup'],
]);

it('puts critical causes first and caps the list without changing check-health status', function (): void {
    $owner = User::factory()->owner()->create();
    DispatcherHeartbeat::query()->create(['id' => '00000000-0000-0000-0000-000000000099', 'last_seen_at' => now()]);
    foreach (['mail_log_only', 'backup_failed', 'backup_stale', 'mail_transport_dead'] as $kind) {
        SystemIssue::query()->create(['kind' => $kind, 'scope' => '', 'details' => [], 'opened_at' => now()]);
    }

    $this->actingAs($owner)->get(route('sites.index'))->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->where('overallHealth.status', 'ok')->has('overallHealth.causes', 3)
        ->where('overallHealth.causes.0.title_key', 'Email delivery is failing'));
});
