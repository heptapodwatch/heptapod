<?php

use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Database\Seeders\ReviewStatesSeeder;
use Heptapod\Addons\Cron\CronCheck;
use Heptapod\Addons\Uptime\UptimeCheck;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Contracts\RunReference;
use Heptapod\Contracts\RunResult;
use Heptapod\Enums\SiteStatus;
use Heptapod\Incidents\SiteState;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\CheckSnooze;
use Heptapod\Models\Client;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05T12:00:00Z'));
});

it('seeds an agency attention scenario through accepted runs and the real recorder', function (): void {
    $this->seed(DemoSeeder::class);
    expect(Client::query()->count())->toBe(25)
        ->and(Site::query()->count())->toBe(74);
    $states = Site::query()->get()->map(fn (Site $site): string => app(SiteState::class)->derive($site)->value)->countBy();
    expect($states->sortKeys()->all())->toBe([
        'check-error' => 4, 'disabled' => 1, 'down' => 1, 'failed' => 2, 'maintenance' => 1,
        'ok' => 60, 'paused' => 1, 'pending' => 1, 'warning' => 3,
    ]);
    expect(CheckRun::query()->where('result', RunResult::Errored)->whereNull('metrics')->whereNotNull('error')->exists())->toBeTrue()
        ->and(CheckSnooze::query()->where('ends_at', '<', now())->exists())->toBeTrue()
        ->and(Incident::query()->whereNull('resolved_at')->exists())->toBeTrue();
    expect(Event::query()->where('type', 'check.result_changed')->exists())->toBeTrue();
    expect(DB::table('unmonitored_periods')->whereNull('check_id')->whereNull('ended_at')->pluck('site_id')->all())
        ->toBe([Site::query()->where('friendly_name', 'Review paused')->sole()->id]);
    foreach (Incident::query()->get() as $incident) {
        expect(Event::query()->where('incident_id', $incident->id)->where('type', 'incident.opened')->sole()->occurred_at->eq($incident->opened_at))->toBeTrue();
    }
    $events = Event::query()->count();
    $this->seed(DemoSeeder::class);
    expect(Event::query()->count())->toBe($events);
    foreach (CheckRun::query()->distinct()->pluck('summary') as $summary) {
        foreach (['en', 'de'] as $locale) {
            expect(__($summary, locale: $locale))->not->toBe($summary);
        }
    }
    foreach (Site::query()->with('checks')->get() as $site) {
        foreach ($site->checks as $check) {
            if ($check->latest_run_id === null) {
                continue;
            }
            $latest = CheckRun::forRun(new RunReference($check->latest_run_id, $check->latest_run_ended_at))->firstOrFail();
            expect($check->latest_run_result->value)->toBe($latest->result->value);
        }
    }
});

it('refuses review fixtures in production before replacing the HTTP seam or writing data', function (): void {
    $http = app(OutboundHttp::class);
    app()->instance(OutboundHttp::class, $http);
    app()->instance('env', 'production');
    expect(fn () => $this->artisan('db:seed', ['--class' => ReviewStatesSeeder::class, '--force' => true, '--no-interaction' => true])->run())->toThrow(LogicException::class, 'Review fixtures are restricted to local and testing environments.');
    expect(app(OutboundHttp::class))->toBe($http);
    expect(Client::query()->where('slug', 'wp5-review-fixture')->exists())->toBeFalse();
});

it('builds reachable review states and component recovery through the recorder and ping endpoint', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05T12:00:00Z'));
    $now = now('UTC');
    $this->seed(ReviewStatesSeeder::class);
    $client = Client::query()->where('slug', 'wp5-review-fixture')->sole();
    $sites = Site::query()->where('client_id', $client->id)->get()->keyBy('friendly_name');
    expect($sites)->toHaveCount(10);
    foreach ($sites as $site) {
        $scheduledCheck = $site->checks()->sole();
        expect($scheduledCheck->next_run_at->eq($now->copy()->addSeconds($scheduledCheck->interval_seconds)))->toBeTrue($site->friendly_name);
    }
    foreach (['down', 'failed', 'check-error', 'warning', 'pending', 'ok', 'maintenance', 'paused', 'disabled'] as $state) {
        expect(app(SiteState::class)->derive($sites['Review '.$state])->value)->toBe($state);
    }
    expect(DB::table('unmonitored_periods')->where('site_id', $sites['Review paused']->id)->whereNull('check_id')->whereNull('ended_at')->count())->toBe(1);
    expect(DB::table('unmonitored_periods')->where('site_id', $sites['Review disabled']->id)->where('check_id', $sites['Review disabled']->checks()->sole()->id)->whereNull('ended_at')->count())->toBe(1);
    $recovered = $sites['Review recovered-tasks'];
    $check = $recovered->checks()->sole();
    expect($check->latest_run_result)->toBe(CheckResult::Succeeded)
        ->and(__($check->summary, $check->summary_params))->toBe('2 tasks · all on time')
        ->and($check->summary_params)->toMatchArray(['succeeded' => 2, 'failed' => 0]);
    expect(CronCheck::query()->where('check_id', $check->id)->pluck('evaluated_result')->all())->toBe([CheckResult::Succeeded, CheckResult::Succeeded]);
    expect($check->summary_params['next_due_at_epoch'])->toBeGreaterThan(now()->timestamp);
    $failedCheck = $sites['Review failed']->checks()->sole();
    expect(__($failedCheck->summary, $failedCheck->summary_params))->toBe('1 of 2 failing · Backup');
    expect(__($check->summary, $check->summary_params, 'de'))->toBe('2 Aufgaben · alle pünktlich');
    expect(__($failedCheck->summary, $failedCheck->summary_params, 'de'))->toBe('1 von 2 fehlgeschlagen · Backup');
    expect($check->incidents()->sole()->resolved_at)->not->toBeNull();
    expect(app(SiteState::class)->derive($recovered))->toBe(SiteStatus::Ok);
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $props = $this->actingAs($owner)->get('/sites/'.$recovered->id)->assertOk()->inertiaProps();
    expect($props['site']['checks'][0]['open_incident_id'])->toBeNull();
    expect(collect($props['events'])->where('severity', 'recovered')->where('duration_seconds', 192))->toHaveCount(1);
    expect(collect($props['events'])->where('severity', 'recovered')->where('duration_seconds', 120))->toHaveCount(1);
    foreach ($sites as $site) {
        foreach ($site->checks as $item) {
            if ($item->latest_run_id === null) {
                continue;
            }
            $run = CheckRun::forRun(new RunReference($item->latest_run_id, $item->latest_run_ended_at))->sole();
            expect($run->result->value)->toBe($item->latest_run_result->value);
            expect($item->claim_token)->toBeNull();
        }
    }
    $count = Event::query()->count();
    $this->seed(ReviewStatesSeeder::class);
    expect(Event::query()->count())->toBe($count);
});

it('does not seed checks for an uninstalled demo addon', function (): void {
    $registry = new CheckRegistry(app());
    $registry->register(UptimeCheck::class);
    app()->instance(CheckRegistry::class, $registry);
    $this->seed(DemoSeeder::class);
    expect(Check::query()->distinct()->pluck('type')->all())->toBe([CheckKey::Uptime]);
});
