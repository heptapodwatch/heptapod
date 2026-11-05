<?php

use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\RunPhase;
use Heptapod\Enums\ClientScope;
use Heptapod\Enums\SiteStatus;
use Heptapod\Incidents\SiteState;
use Heptapod\Models\Check;
use Heptapod\Models\Client;
use Heptapod\Models\DispatcherHeartbeat;
use Heptapod\Models\Incident;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Support\Facades\DB;

it('selects the board state with the demo command', function (string $scenario, ?SiteStatus $status): void {
    $this->artisan('heptapod:seed-demo', ['--scenario' => $scenario])->assertSuccessful();
    expect(User::query()->count())->toBe(1)
        ->and(User::query()->sole()->email)->toBe(DatabaseSeeder::DEMO_EMAIL);
    if ($scenario === 'first-run') {
        expect(Site::query()->count())->toBe(0)
            ->and(NotificationDestination::query()->count())->toBe(0);

        return;
    }
    expect(Client::query()->count())->toBe(24)
        ->and(Site::query()->count())->toBe(64)
        ->and(NotificationDestination::query()->count())->toBe($scenario === 'no-channel' ? 0 : 1);
    $owner = User::query()->sole();
    $coverage = $this->actingAs($owner)->get('/dashboard')->assertOk()->inertiaProps()['overallHealth']['notifications_covered'];
    expect($coverage)->toBe(! in_array($scenario, ['no-channel', 'nothing-checked'], true));
    if ($scenario === 'nothing-checked') {
        expect(Check::query()->count())->toBe(0)
            ->and(Site::query()->whereNotNull('paused_at')->count())->toBe(64);

        return;
    }
    $shop = Site::query()->where('url', 'https://demo-01.example')->sole();
    expect(app(SiteState::class)->derive($shop))->toBe($status);
    expect(Incident::query()->whereNull('resolved_at')->count())->toBe(match ($scenario) {
        'calm', 'no-channel' => 0, 'incident' => 4, default => 3
    });
    expect(DispatcherHeartbeat::query()->sole()->last_seen_at->lt(now()->subMinutes(40)))->toBe($scenario === 'stopped');
    expect(DB::table('maintenance_periods')->count())->toBe(1);
    if (in_array($scenario, ['attention', 'incident', 'stopped', 'calm'], true)) {
        expect(DB::table('cron_checks')->count())->toBe(38);
    }
    if ($scenario === 'calm') {
        expect(Check::query()->where('type', 'cron')->where('latest_run_result', CheckResult::Succeeded)->count())->toBe(19)
            ->and($this->get('/dashboard')->assertOk()->inertiaProps()['since_last_looked']['events_total'])->toBe(0)
            ->and($this->get('/activity?period=all')->assertOk()->inertiaProps()['total'])->toBe(0);
    }
    if ($scenario === 'attention') {
        expect(Check::query()->where('type', 'cron')->where('latest_run_result', CheckResult::Failed)->exists())->toBeTrue()
            ->and(Check::query()->where('type', 'lighthouse')->count())->toBe(6)
            ->and(Check::query()->where('type', 'lighthouse')->where('latest_run_result', CheckResult::Errored)->count())->toBe(2)
            ->and(Check::query()->where('type', 'lighthouse')->where('latest_run_result', CheckResult::Warning)->count())->toBe(1)
            ->and(Check::query()->where('type', 'lighthouse')->where('next_run_at', '<', now())->exists())->toBeTrue()
            ->and(User::query()->sole()->last_seen_at)->not->toBeNull();
        $owner = User::query()->sole();
        $causes = $this->actingAs($owner)->get('/dashboard')->assertOk()->inertiaProps()['lighthouse_causes'];
        expect($causes)->toBe([
            ['key' => 'causes.error', 'params' => ['message' => 'Demo browser unavailable'], 'sites' => 2],
            ['key' => 'lighthouse.warning', 'params' => [], 'sites' => 1],
        ]);
    }
})->with([
    'attention' => ['attention', SiteStatus::Ok],
    'incident' => ['incident', SiteStatus::Down],
    'calm' => ['calm', SiteStatus::Ok],
    'stopped' => ['stopped', SiteStatus::Ok],
    'no-channel' => ['no-channel', SiteStatus::Ok],
    'nothing-checked' => ['nothing-checked', null],
    'first-run' => ['first-run', null],
]);

it('seeds a nearby minute schedule and a genuinely overdue check without a future due date', function (): void {
    $now = CarbonImmutable::parse('2026-10-05T12:00:00Z');
    $this->travelTo($now);
    $this->artisan('heptapod:seed-demo', ['--scenario' => 'attention'])->assertSuccessful();
    $owner = User::query()->sole();
    $this->actingAs($owner);

    $uptime = Check::query()->where('site_id', Site::query()->where('url', 'https://demo-02.example')->sole()->id)
        ->where('type', CheckKey::Uptime)->sole();
    $upcoming = $this->get('/sites/'.$uptime->site_id.'/checks/'.$uptime->id)->assertOk()->inertiaProps()['schedule'];
    expect($upcoming['run']['phase'])->toBe(RunPhase::Scheduled->value)
        ->and(CarbonImmutable::parse($upcoming['next_due_at'])->betweenIncluded($now, $now->addMinute()))->toBeTrue();

    $late = Check::query()->where('site_id', Site::query()->where('url', 'https://demo-05.example')->sole()->id)
        ->where('type', CheckKey::Lighthouse)->sole();
    $schedule = $this->get('/sites/'.$late->site_id.'/checks/'.$late->id)->assertOk()->inertiaProps()['schedule'];
    expect($schedule['run']['phase'])->toBe(RunPhase::Late->value)
        ->and($schedule['run']['due_at'])->toBe($schedule['next_due_at'])
        ->and(CarbonImmutable::parse($schedule['run']['due_at'])->lessThan($now))->toBeTrue()
        ->and(CarbonImmutable::parse($schedule['next_due_at'])->lessThan($now))->toBeTrue();
});

it('shows accepted expiry and scheduled-task counts only for visible sites at the UTC day boundary', function (): void {
    $this->travelTo(CarbonImmutable::now('UTC')->startOfDay()->addMinutes(30));
    $this->artisan('heptapod:seed-demo', ['--scenario' => 'attention'])->assertSuccessful();
    $owner = User::query()->sole();
    $tiles = $this->actingAs($owner)->get('/dashboard')->assertOk()->inertiaProps()['tiles'];
    expect($tiles)->toMatchArray([
        'certificates_expiring_30_days' => 4, 'domains_expiring_30_days' => 3,
        'scheduled_tasks' => 38, 'scheduled_tasks_overdue_pings' => 1,
    ]);
    expect(DB::table('certificate_health_snapshots')->whereNotNull('run_ended_at')->count())->toBe(5)
        ->and(DB::table('domain_snapshots')->whereNotNull('run_ended_at')->count())->toBe(3);

    $member = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $member->assignRole('Member');
    $site = Site::query()->where('url', 'https://demo-02.example')->sole();
    $member->clients()->attach($site->client_id);
    $scoped = $this->actingAs($member)->get('/dashboard')->assertOk()->inertiaProps()['tiles'];
    expect($scoped)->toMatchArray([
        'certificates_expiring_30_days' => 1, 'domains_expiring_30_days' => 1,
        'scheduled_tasks' => 2, 'scheduled_tasks_overdue_pings' => 1,
    ]);
    Check::query()->where('site_id', $site->id)->where('type', CheckKey::CertificateHealth)->update(['enabled' => false]);
    expect($this->get('/dashboard')->assertOk()->inertiaProps()['tiles']['certificates_expiring_30_days'])->toBe(0);

    $this->travel(80)->days();
    $expired = $this->actingAs($owner)->get('/dashboard')->assertOk()->inertiaProps()['tiles'];
    expect($expired['certificates_expiring_30_days'])->toBe(4)
        ->and($expired['domains_expiring_30_days'])->toBe(3);
});

it('rejects unknown and production demo scenarios without inserting sites', function (): void {
    $this->artisan('heptapod:seed-demo', ['--scenario' => 'missing'])->assertFailed();
    app()->instance('env', 'production');
    expect(fn () => $this->artisan('heptapod:seed-demo')->run())->toThrow(LogicException::class);
    expect(Site::query()->count())->toBe(0);
});
