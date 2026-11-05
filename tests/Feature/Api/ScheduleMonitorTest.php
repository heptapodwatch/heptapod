<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use Heptapod\Contracts\CheckKey;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Spatie\ScheduleMonitor\Models\MonitoredScheduledTask;
use Spatie\ScheduleMonitor\ScheduleMonitorServiceProvider;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-03T12:00:00Z'));
});

it('runs unchanged schedule-monitor sync through the HTTP kernel then pings and deletes through sync', function (): void {
    Http::preventStrayRequests();
    $this->app->register(ScheduleMonitorServiceProvider::class);
    require_once base_path('vendor/spatie/laravel-schedule-monitor/database/migrations/create_schedule_monitor_tables.php.stub');
    (new CreateScheduleMonitorTables)->up();
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $token = $owner->createToken('schedule-monitor', ['checks:write', 'checks:read'])->plainTextToken;
    $site = Site::factory()->create();
    $base = 'http://localhost';
    $syncUrl = $base.'/api/monitors/'.$site->id.'/cron-checks/sync';
    // Only the package's outbound HTTP transport is replaced; sync uses the real authenticated API and database.
    Http::fake([$syncUrl => function (Request $request): PromiseInterface {
        $headers = array_map(static fn (array $values): string => implode(', ', $values), $request->headers());
        $response = $this->postJson($request->url(), $request->data(), $headers);

        return Http::response($response->json(), $response->status());
    }]);
    $this->withToken($token);
    config()->set('schedule-monitor.oh_dear.api_token', $token);
    config()->set('schedule-monitor.oh_dear.monitor_id', $site->id);
    config()->set('schedule-monitor.oh_dear.api_url', $base.'/api/');
    config()->set('schedule-monitor.oh_dear.endpoint_url', $base.'/cron');
    $schedule = new Schedule('UTC');
    $schedule->exec('true')->everyMinute()->monitorName('e2e-scheduled-task');
    app()->instance(Schedule::class, $schedule);

    $this->artisan('schedule-monitor:sync')->assertSuccessful();

    $local = MonitoredScheduledTask::query()->sole();
    expect($local->ping_url)->toStartWith($base.'/cron/');
    expect($local->registered_on_oh_dear_at)->not->toBeNull();
    $check = $site->checks()->where('type', CheckKey::Cron)->sole();
    $response = $this->getJson('/api/monitors/'.$site->id.'/cron-checks')->assertOk()->assertJsonPath('data.0.name', 'e2e-scheduled-task');
    $uuid = $response->json('data.0.uuid');
    $this->post($local->ping_url)->assertSuccessful();
    $this->getJson('/api/monitors/'.$site->id.'/cron-checks')->assertOk()->assertJsonPath('data.0.latest_result', 'pingFinished');
    $this->artisan('schedule-monitor:sync')->assertSuccessful();
    $this->getJson('/api/monitors/'.$site->id.'/cron-checks')->assertOk()->assertJsonPath('data.0.uuid', $uuid);
    app()->instance(Schedule::class, new Schedule('UTC'));
    $this->artisan('schedule-monitor:sync')->assertSuccessful();
    $this->getJson('/api/monitors/'.$site->id.'/cron-checks')->assertOk()->assertExactJson(['data' => []]);
    expect($check->refresh()->enabled)->toBeTrue();
    $this->post($local->ping_url)->assertNotFound();
    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request): bool => $request->url() === $syncUrl
        && $request->hasHeader('Authorization', 'Bearer '.$token)
        && $request['cron_checks'] === [[
            'name' => 'e2e-scheduled-task', 'type' => 'cron', 'cron_expression' => '* * * * *',
            'grace_time_in_minutes' => 5, 'server_timezone' => 'UTC', 'description' => '',
        ]]);
});

it('does not register schedule-monitor commands in Heptapod', function (): void {
    $artisan = new Process([PHP_BINARY, 'artisan', 'list', '--format=json'], base_path());
    $commands = json_decode($artisan->mustRun()->getOutput(), true, 512, JSON_THROW_ON_ERROR)['commands'];

    expect(array_filter(array_column($commands, 'name'), fn (string $name): bool => str_starts_with($name, 'schedule-monitor:')))->toBe([]);
});
