<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Addons\Channels\RenderedAlert;
use Heptapod\Checks\Execution\CheckExecutor;
use Heptapod\Checks\Execution\ClaimService;
use Heptapod\Checks\Execution\RunOrigin;
use Heptapod\Contracts\AlertPreviewRenderer;
use Heptapod\Contracts\CertificateFetcher;
use Heptapod\Contracts\CertificateFetchResult;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\Http\FetchFailure;
use Heptapod\Contracts\Http\FetchFailureKind;
use Heptapod\Contracts\Http\FetchResult;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Contracts\Notifications\ChannelRegistry;
use Heptapod\Contracts\SuppressionComponent;
use Heptapod\Events\EventPresenter;
use Heptapod\Events\Notifications\NotificationMessage;
use Heptapod\Jobs\RunCheck;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Testing\FakeCertificateFetcher;
use Heptapod\Testing\FakeOutboundHttp;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-03T12:00:00Z'));
});

it('shows meaningful transitions once with site identity and operator vocabulary', function (): void {
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $check = Check::factory()->create(['type' => CheckKey::Uptime]);
    $incident = Incident::factory()->for($check)->create(['first_failure_at' => now()->subSeconds(192)]);
    Event::factory()->for($check)->create();
    Event::factory()->for($check)->create(['from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Succeeded]);
    $payload = ['run_id' => 42, 'run_ended_at' => now()->format('Y-m-d\TH:i:s.uP')];
    Event::factory()->for($check)->for($incident)->create(['from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Failed, 'payload' => $payload]);
    $opened = Event::factory()->for($check)->for($incident)->create(['type' => 'incident.opened', 'from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Failed, 'payload' => $payload]);
    $recovered = Event::factory()->for($check)->for($incident)->create(['type' => 'incident.resolved', 'from_result' => CheckResult::Failed, 'to_result' => CheckResult::Succeeded]);

    foreach (['/sites/'.$check->site_id] as $route) {
        $this->actingAs($owner)->get($route)->assertInertia(fn (Assert $page): Assert => $page
            ->has('events', 2)->where('events.0.id', $recovered->id)
            ->where('events.0.message', 'Recovered after 3m 12s')->where('events.1.message', 'Down')
            ->where('events.1.site_name', $check->site->displayName()));
    }
    $timeline = collect($this->get('/incidents/'.$incident->id)->inertiaProps()['timeline'])->pluck('event')->filter()->values();
    expect($timeline->pluck('id')->all())->toBe([$opened->id, $recovered->id])
        ->and($timeline->pluck('message')->all())->toBe(['Down', 'Recovered after 3m 12s']);
    $message = app(NotificationMessage::class)->build($recovered);
    expect(new RenderedAlert($message, 'en', 'UTC', 'Heptapod', 'https://heptapod.example')->title())->not->toContain('●', '✓');
});

it('names each cron task result in site timeline', function (string $result, string $expected): void {
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $check = Check::factory()->create(['type' => CheckKey::Cron]);
    Event::factory()->for($check)->create([
        'type' => 'cron.task_result_changed',
        'component_type' => 'cron',
        'component_id' => 1,
        'payload' => ['component_name' => 'Cleanup 2', 'from_result' => $result === 'succeeded' ? 'warning' : 'pending', 'result' => $result, 'summary' => match ($result) {
            'succeeded' => 'cron.task_succeeded',
            'failed' => 'cron.task_failed',
            'warning' => 'cron.task_late',
        }, 'summary_params' => ['presentation' => 'direct']],
    ]);

    foreach (['/sites/'.$check->site_id] as $route) {
        $events = $this->actingAs($owner)->get($route)->assertOk()->inertiaProps()['events'];
        expect($events[0]['message'])->toBe('Cleanup 2: '.$expected.'.');
    }
})->with([
    'succeeded' => ['succeeded', 'Succeeded'],
    'failed' => ['failed', 'Failed'],
    'late' => ['warning', 'Late'],
]);

it('backfills retained cron task labels without changing existing event facts', function (): void {
    $check = Check::factory()->create(['type' => CheckKey::Cron]);
    $events = [];
    foreach (['succeeded', 'failed', 'warning'] as $result) {
        $events[$result] = Event::factory()->for($check)->create([
            'type' => 'cron.task_result_changed',
            'component_type' => 'cron',
            'component_id' => 1,
            'payload' => ['component_name' => 'Cleanup 2', 'result' => $result, 'other' => 'retained'],
        ]);
    }
    $migration = require base_path('addons/cron/database/migrations/2026_10_04_211406_backfill_cron_task_event_labels.php');
    $migration->up();
    $migration->up();

    foreach (['succeeded' => 'Succeeded', 'failed' => 'Failed', 'warning' => 'Late'] as $result => $label) {
        expect($events[$result]->refresh()->payload)->toMatchArray([
            'summary' => 'cron.task_'.($result === 'warning' ? 'late' : $result),
            'summary_params' => ['presentation' => 'direct'],
            'result' => $result,
            'other' => 'retained',
        ]);
        expect(app(EventPresenter::class)->present($events[$result])->message)->toBe('Cleanup 2: '.$label.'.');
    }
});

it('shows one incident opening across threshold failures and labels only incident cause changes', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-03T12:00:00Z'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $check = Check::factory()->create(['failure_threshold' => 2, 'config' => app(CheckRegistry::class)->get(CheckKey::Uptime)->definition()->configSchema->validateConfig([])]);
    $http = (new FakeOutboundHttp)->respondUsing(fn (): FetchResult => new FetchResult(200));
    app()->instance(OutboundHttp::class, $http);
    $execute = function () use ($check): void {
        Bus::fake([RunCheck::class]);
        expect(app(ClaimService::class)->runNow($check->id, RunOrigin::System))->not->toBeNull();
        app(CheckExecutor::class)->execute($check->id, $check->refresh()->claim_token, 60);
        $check->refresh();
        $this->travel(1)->minutes();
    };
    $execute();
    $http->respondUsing(fn (): FetchResult => new FetchResult(503));
    $execute();
    $execute();
    $incident = Incident::query()->where('check_id', $check->id)->sole();
    $before = Event::query()->where('check_id', $check->id)->count();
    $execute();
    expect(Event::query()->where('check_id', $check->id)->count())->toBe($before);
    foreach (['/sites/'.$check->site_id] as $route) {
        $rows = $this->actingAs($owner)->get($route)->assertOk()->inertiaProps()['events'];
        expect(array_column($rows, 'message'))->toBe(['Down']);
    }
    $timeline = collect($this->get('/incidents/'.$incident->id)->assertOk()->inertiaProps()['timeline'])->pluck('event')->filter();
    expect($timeline->pluck('message')->all())->toBe(['Down']);
    $http->respondUsing(function (): FetchResult {
        throw new RuntimeException('Worker unavailable');
    });
    $execute();
    $http->respondUsing(fn (): FetchResult => new FetchResult(503));
    $execute();
    $rows = $this->get('/sites/'.$check->site_id)->assertOk()->inertiaProps()['events'];
    expect(array_column($rows, 'type'))->toBe(['incident.cause_changed', 'incident.cause_changed', 'incident.opened']);
    expect(array_column($rows, 'message'))->toBe(['Cause changed: HTTP 503 Service Unavailable', 'Cause changed: Worker unavailable', 'Down']);
});

it('uses the same concrete redacted run cause in UI, mail and chat', function (array $config, FetchResult $response, string $expected): void {
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $site = Site::factory()->create(['url' => 'https://cause.example?token=query-private-secret']);
    $check = Check::factory()->for($site)->create(['type' => CheckKey::Uptime, 'failure_threshold' => 1,
        'config' => app(CheckRegistry::class)->get(CheckKey::Uptime)->definition()->configSchema->validateConfig($config)]);
    app()->instance(OutboundHttp::class, (new FakeOutboundHttp)->respondUsing(fn (): FetchResult => $response));
    app()->instance(CertificateFetcher::class, (new FakeCertificateFetcher)->respondWith('cause.example', new CertificateFetchResult([], 'cause.example', 443, false)));
    Bus::fake([RunCheck::class]);
    expect(app(ClaimService::class)->runNow($check->id, RunOrigin::System))->not->toBeNull();
    app(CheckExecutor::class)->execute($check->id, $check->refresh()->claim_token, 60);
    $event = Event::query()->where('check_id', $check->id)->where('type', 'incident.opened')->firstOrFail();
    $check->refresh();
    expect(__($check->summary, $check->summary_params))->toBe($expected);
    if ($response->transfers !== []) {
        expect(CheckRun::query()->where('check_id', $check->id)->sole()->metrics['total_time_in_seconds'])->toBe(3.5);
    }
    foreach (['/sites/'.$site->id, '/incidents/'.$event->incident_id] as $route) {
        $props = $this->actingAs($owner)->get($route)->assertOk()->inertiaProps();
        $strip = $props['site']['checks'][0];
        expect(__($strip['summary'], $strip['summary_params']))->toBe($expected);
        // Site URL is intentionally public; reflected credentials must not enter summaries.
        expect(json_encode($strip['summary_params'], JSON_THROW_ON_ERROR))->not->toContain('query-private-secret');
    }
    $message = app(NotificationMessage::class)->build($event);
    $rendered = new RenderedAlert($message, 'en', 'UTC', 'Heptapod', 'https://heptapod.example');
    expect($rendered->title())->not->toContain('●', '✓');
    expect($rendered->details())->toStartWith($expected)->not->toContain('query-private-secret');
    $mailbox = NotificationDestination::factory()->create(['locale' => 'en', 'config' => app(ChannelRegistry::class)->get('mail')->configSchema()->validateConfig(['email' => 'ops@example.test'])]);
    $mail = app(AlertPreviewRenderer::class)->render(app(NotificationMessage::class)->build($event, $mailbox), 'en', 'UTC', 'Heptapod', 'https://heptapod.example');
    expect(html_entity_decode($mail['html'], ENT_QUOTES))->toContain($expected)->not->toContain('query-private-secret');
})->with([
    'status and timing' => [[], new FetchResult(503, transfers: [['total_time' => 3.5]]), 'HTTP 503 Service Unavailable'],
    'timeout' => [['timeout' => 10], new FetchResult(failure: new FetchFailure(FetchFailureKind::Timeout)), 'Timed out after 10 s'],
    'TLS' => [[], new FetchResult(failure: new FetchFailure(FetchFailureKind::Tls)), 'TLS handshake failed; certificate validation could not complete'],
    'keyword' => [['look_for_string' => 'Welcome'], new FetchResult(200, 'Hello'), "Keyword 'Welcome' not found"],
    'accepted 401 with missing keyword' => [['valid_status_codes' => ['401'], 'look_for_string' => 'Welcome'], new FetchResult(401, 'Hello'), "Keyword 'Welcome' not found"],
    'redirect query secrets' => [['expected_final_redirect_url' => 'https://cause.example/expected?token=query-private-secret'], new FetchResult(200, effectiveUrl: 'https://cause.example/actual?token=query-private-secret'), 'Redirected to https://cause.example/actual?[redacted] (expected https://cause.example/expected?[redacted])'],
]);

it('accepts retained JSON null summary parameters as an empty parameter set', function (): void {
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $check = Check::factory()->create(['summary' => 'uptime.succeeded']);
    $run = CheckRun::factory()->for($check)->create(['summary' => 'uptime.succeeded']);
    DB::table('checks')->where('id', $check->id)->update(['summary_params' => DB::raw("'null'::jsonb")]);
    DB::table('check_runs')->where('id', $run->id)->where('ended_at', $run->ended_at)->update(['summary_params' => DB::raw("'null'::jsonb")]);
    Event::factory()->for($check)->create(['from_result' => CheckResult::Warning, 'to_result' => CheckResult::Succeeded,
        'payload' => ['run_id' => $run->id, 'run_ended_at' => $run->ended_at->format('Y-m-d\\TH:i:s.uP')]]);

    $props = $this->actingAs($owner)->get('/sites/'.$check->site_id)->assertOk()->inertiaProps();
    expect($props['site']['checks'][0]['summary_params'])->toBe([])
        ->and($props['events'][0]['message'])->toBe('Recovered');

    $check->update(['summary_params' => ['optional' => null]]);
    $run->update(['summary_params' => ['optional' => null]]);
    $props = $this->get('/sites/'.$check->site_id)->assertOk()->inertiaProps();
    expect($props['site']['checks'][0]['summary_params'])->toBe(['optional' => null]);
});

it('presents historical check facts rather than the latest result', function (CheckKey $type, string $summary, array $params, array $metrics, array $evidence, string $message): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-03T12:00:00Z'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $check = Check::factory()->create(['type' => $type, 'config' => app(CheckRegistry::class)->get($type)->definition()->configSchema->validateConfig($type === CheckKey::Performance ? ['threshold_in_ms' => 2500] : [])]);
    $run = CheckRun::factory()->for($check)->create(['check_type' => $type, 'summary' => $summary,
        'summary_params' => $params, 'metrics' => $metrics, 'evidence' => $evidence, 'started_at' => now()->subMinutes(2), 'ended_at' => now()->subMinute()]);
    $payload = ['run_id' => $run->id, 'run_ended_at' => $run->ended_at->format('Y-m-d\TH:i:s.uP')];
    if ($type === CheckKey::Dns) {
        $payload['changes'] = ['A' => ['added' => [['ip' => '192.0.2.1']], 'removed' => [['ip' => '192.0.2.2']]]];
    }
    Event::factory()->for($check)->create(['type' => $type === CheckKey::Dns ? 'dns.records_changed' : 'check.result_changed',
        'from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Warning, 'payload' => $payload]);
    // Same sequence ID in a different partition must not replace the event's physical run.
    CheckRun::factory()->for($check)->create(['id' => $run->id, 'summary' => 'uptime.succeeded', 'ended_at' => now()->addDay()]);
    $this->actingAs($owner)->get('/sites/'.$check->site_id)->assertInertia(fn (Assert $page): Assert => $page->where('events.0.message', $message));
})->with([
    'performance warning' => [CheckKey::Performance, 'causes.response_time', ['seconds' => 2.8, 'threshold' => 2.5], ['average_response_time_in_ms' => 2800], [], 'Warning: response time 2.8 s > 2.5 s'],
    'certificate expiry' => [CheckKey::CertificateHealth, 'causes.certificate_expiry', ['days' => 9, 'presentation' => 'direct'], [], ['certificate_details' => ['valid_until' => '2026-10-12T11:59:00Z']], 'Certificate expires in 9 days'],
    'DNS changes' => [CheckKey::Dns, 'dns.changed', [], [], [], 'A records changed: 1 removed, 1 added.'],
]);

it('keeps the stored performance outcome independent of the current chart window', function (bool $hasRecent): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-03T12:00:00Z'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $uptime = Check::factory()->create(['type' => CheckKey::Uptime]);
    Check::factory()->for($uptime->site)->create(['type' => CheckKey::Performance, 'latest_run_result' => CheckResult::Succeeded,
        'summary' => 'performance.succeeded', 'summary_params' => ['average' => 999]]);
    CheckRun::factory()->for($uptime)->create(['started_at' => now()->subHours(2)->subSecond(), 'ended_at' => now()->subHours(2), 'metrics' => ['total_time_in_seconds' => 0.999]]);
    if ($hasRecent) {
        foreach ([0.1, 0.3, 0.8] as $seconds) {
            CheckRun::factory()->for($uptime)->create(['started_at' => now()->subMinutes(2), 'ended_at' => now()->subMinute(), 'metrics' => ['total_time_in_seconds' => $seconds]]);
        }
    }
    $props = $this->actingAs($owner)->get('/sites/'.$uptime->site_id)->assertOk()->inertiaProps();
    $performance = collect($props['site']['checks'])->firstWhere('key', 'performance');
    $chart = $this->get('/sites/'.$uptime->site_id.'/checks/'.$uptime->id)->assertOk()->inertiaProps()['response_times'][0];
    expect($chart['key'])->toBe('24h')->and($chart['points'][0]['maximum_ms'])->toBe(999);
    if ($hasRecent) {
        expect($performance['summary_params']['average'])->toBe(999)->and($chart['recent_average_ms'])->toBe(400);
    } else {
        expect($performance['summary'])->toBe('performance.succeeded')->and($performance['summary_params']['average'])->toBe(999)->and($chart['recent_average_ms'])->toBeNull();
    }
})->with([true, false]);

it('shows recovery even when a transient failure did not reach the incident threshold', function (): void {
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $check = Check::factory()->create();
    Event::factory()->for($check)->create(['from_result' => CheckResult::Errored, 'to_result' => CheckResult::Succeeded]);
    $this->actingAs($owner)->get('/sites/'.$check->site_id)->assertInertia(fn (Assert $page): Assert => $page
        ->where('events.0.message', 'Recovered')->where('events.0.severity', 'recovered')->where('events.0.incident_id', null));
});

it('localizes the retained component identifier when its name is missing', function (string $locale, string $name, array $payload): void {
    $owner = User::factory()->create(['locale' => $locale]);
    $owner->assignRole('Owner');
    $check = Check::factory()->create(['type' => CheckKey::Cron]);
    Event::factory()->for($check)->create(['type' => 'cron.task_result_changed', 'component_type' => SuppressionComponent::Cron,
        'component_id' => 17, 'payload' => $payload, 'to_result' => CheckResult::Failed]);

    $props = $this->actingAs($owner)->get('/sites/'.$check->site_id)->assertOk()->inertiaProps();
    expect($props['events'][0]['message_params']['component'])->toBe($name);
})->with([
    ['en', 'Component #17', ['result' => 'failed']],
    ['de', 'Komponente #17', ['result' => 'failed']],
    ['en', 'Component #17', ['result' => 'failed', 'component_name' => '']],
    ['de', 'Komponente #17', ['result' => 'failed', 'component_name' => '']],
]);

it('keeps component names and recovery facts distinct in a shared timeline', function (string $locale, string $failed, string $backupRecovery, string $cleanupRecovery): void {
    $at = CarbonImmutable::parse('2026-10-03T12:00:00Z');
    $this->travelTo($at);
    $owner = User::factory()->create(['locale' => $locale]);
    $owner->assignRole('Owner');
    $site = Site::factory()->create(['url' => 'https://tasks.example?year=2026', 'crawler_headers' => [['name' => 'Authorization', 'value' => 'task-private-secret']]]);
    $check = Check::factory()->for($site)->create(['type' => CheckKey::Cron]);
    $incident = Incident::factory()->for($check)->create(['first_failure_at' => $at->subHours(4)]);
    $events = [];
    foreach ([[11, 'Backup task-private-secret', 192], [12, 'Cleanup', 120]] as [$id, $name, $seconds]) {
        foreach ([CheckResult::Failed, CheckResult::Succeeded] as $result) {
            $events[] = Event::factory()->for($check)->for($incident)->create([
                'type' => 'cron.task_result_changed', 'component_type' => SuppressionComponent::Cron, 'component_id' => $id,
                'to_result' => CheckResult::Failed, 'occurred_at' => $result === CheckResult::Succeeded ? $at->addSecond() : $at->subSeconds($seconds),
                'payload' => ['component_name' => $name, 'from_result' => $result === CheckResult::Succeeded ? 'failed' : 'succeeded',
                    'result' => $result->value, 'failure_started_at' => $at->subSeconds($seconds)->toIso8601String(),
                    'recovered_at' => $result === CheckResult::Succeeded ? $at->toIso8601String() : null],
            ]);
        }
    }
    foreach (['/sites/'.$site->id] as $route) {
        $rows = collect($this->actingAs($owner)->get($route)->assertOk()->inertiaProps()['events'])->keyBy('id');
        expect($rows[$events[0]->id]['message'])->toBe('Backup [redacted]: '.$failed);
        expect($rows[$events[1]->id]['message'])->toBe('Backup [redacted]: '.$backupRecovery)
            ->and($rows[$events[1]->id]['duration_seconds'])->toBe(192)->and($rows[$events[1]->id]['time'])->toBe($at->toIso8601String());
        expect($rows[$events[2]->id]['message'])->toBe('Cleanup: '.$failed);
        expect($rows[$events[3]->id]['message'])->toBe('Cleanup: '.$cleanupRecovery)->and($rows[$events[3]->id]['duration_seconds'])->toBe(120);
        expect(json_encode($rows, JSON_THROW_ON_ERROR))->not->toContain('task-private-secret');
    }
})->with([
    ['en', 'Failed', 'Recovered after 3m 12s', 'Recovered after 2m'],
    ['de', 'Fehlgeschlagen', 'Wiederhergestellt nach 3m 12s', 'Wiederhergestellt nach 2m'],
]);
