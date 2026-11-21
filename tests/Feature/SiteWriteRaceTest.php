<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Actions\CreateCronTask;
use Heptapod\Actions\CreateMaintenancePeriod;
use Heptapod\Actions\SnoozeCronTask;
use Heptapod\Actions\StartMaintenance;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\CronTaskStore;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRunAttempt;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Models\Site;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Support/SiteWriteRace.php';

const SiteWriteRaceCron = ['name' => 'schedule', 'type' => 'cron', 'cron_expression' => '* * * * *', 'server_timezone' => 'UTC', 'grace_time_in_minutes' => 5, 'description' => ''];

/*
 * A write whose site moves to another client while it waits answers as if the site had already moved: a token scoped
 * to the old client gets 404 and the write does not land in the new client.
 */
it('refuses a scoped token write once the site has moved to another client', function (Closure $prepare): void {
    $fixture = siteWriteRaceFixture();
    /** @var array{0: string, 1: string, 2: array<string, mixed>, 3: Closure(): mixed, 4?: string} $request */
    $request = $prepare($fixture['site'], $fixture['owner']);
    [$method, $uri, $body, $observe] = $request;
    $before = $observe();

    $response = siteWriteRace(siteWriteRaceMove($fixture), $method, $uri, $body, $fixture['token'], pauseAfter: $request[4] ?? '');

    expect($response['status'])->toBe(404, json_encode($response['body']))
        ->and($observe())->toEqual($before)
        ->and(Site::query()->findOrFail($fixture['site']->id)->client_id)->toBe($fixture['to']->id);
})->with([
    'cron task create' => [static fn (Site $site): array => ['POST', '/api/monitors/'.$site->id.'/cron-checks', SiteWriteRaceCron,
        static fn (): bool => Check::query()->where('site_id', $site->id)->where('type', CheckKey::Cron)->exists()]],
    'cron task sync' => [static fn (Site $site): array => ['POST', '/api/monitors/'.$site->id.'/cron-checks/sync', ['cron_checks' => [SiteWriteRaceCron]],
        static fn (): bool => Check::query()->where('site_id', $site->id)->where('type', CheckKey::Cron)->exists()]],
    'cron task update' => [static function (Site $site): array {
        $task = app(CreateCronTask::class)($site, SiteWriteRaceCron);

        return ['PUT', '/api/cron-checks/'.$task->id, ['cron_expression' => '0 * * * *'], static fn (): mixed => app(CronTaskStore::class)->find($task->id)->definition['cron_expression']];
    }],
    'cron task delete' => [static function (Site $site): array {
        $task = app(CreateCronTask::class)($site, SiteWriteRaceCron);

        return ['DELETE', '/api/cron-checks/'.$task->id, [], static fn (): array => array_map(static fn (object $task): int => $task->id, app(CronTaskStore::class)->list($task->checkId))];
    }],
    'cron task snooze' => [static function (Site $site): array {
        $task = app(CreateCronTask::class)($site, SiteWriteRaceCron);

        return ['POST', '/api/cron-checks/'.$task->id.'/snooze', ['minutes' => 10], static fn (): int => DB::table('check_snoozes')->where('check_id', $task->checkId)->count()];
    }],
    'cron task unsnooze' => [static function (Site $site, $owner): array {
        $task = app(CreateCronTask::class)($site, SiteWriteRaceCron);
        app(SnoozeCronTask::class)(Check::query()->findOrFail($task->checkId), $task->id, $owner, 60);

        return ['POST', '/api/cron-checks/'.$task->id.'/unsnooze', [], static fn (): int => DB::table('check_snoozes')->where('check_id', $task->checkId)->whereNull('unsnoozed_at')->count()];
    }],
    'maintenance start' => [static fn (Site $site): array => ['POST', '/api/monitors/'.$site->id.'/start-maintenance', [],
        static fn (): int => MaintenancePeriod::query()->where('site_id', $site->id)->count()]],
    'maintenance stop' => [static function (Site $site): array {
        $window = app(StartMaintenance::class)($site);

        return ['POST', '/api/monitors/'.$site->id.'/stop-maintenance', [], static fn (): string => MaintenancePeriod::query()->findOrFail($window->id)->ends_at->toIso8601String()];
    }],
    'maintenance window create' => [static fn (Site $site): array => ['POST', '/api/maintenance-periods', ['monitor_id' => $site->id,
        'starts_at' => CarbonImmutable::now('UTC')->addDay()->format('Y-m-d H:i'), 'ends_at' => CarbonImmutable::now('UTC')->addDay()->addHour()->format('Y-m-d H:i')],
        static fn (): int => MaintenancePeriod::query()->where('site_id', $site->id)->count()]],
    'maintenance window update' => [static function (Site $site): array {
        $window = app(CreateMaintenancePeriod::class)($site, 'planned', CarbonImmutable::now()->addDay(), CarbonImmutable::now()->addDay()->addHour());

        return ['PUT', '/api/maintenance-periods/'.$window->id, ['name' => 'renamed', 'starts_at' => CarbonImmutable::now('UTC')->addDays(2)->format('Y-m-d H:i'),
            'ends_at' => CarbonImmutable::now('UTC')->addDays(2)->addHour()->format('Y-m-d H:i')], static fn (): ?string => MaintenancePeriod::query()->findOrFail($window->id)->name];
    }],
    'maintenance window delete' => [static function (Site $site): array {
        $window = app(CreateMaintenancePeriod::class)($site, 'planned', CarbonImmutable::now()->addDay(), CarbonImmutable::now()->addDay()->addHour());

        return ['DELETE', '/api/maintenance-periods/'.$window->id, [], static fn (): bool => MaintenancePeriod::query()->whereKey($window->id)->exists()];
    }],
    'manual run' => [static function (Site $site): array {
        $check = Check::factory()->create(['site_id' => $site->id, 'type' => CheckKey::Uptime]);

        // A run claims its check with SKIP LOCKED, so it would refuse while the move holds the check: it waits after its
        // authorization (SitePolicy's archived-client check) instead, and claims once the move has committed.
        return ['POST', '/api/checks/'.$check->id.'/runs', [], static fn (): int => CheckRunAttempt::query()->where('check_id', $check->id)->count(), '"archived_at" is null'];
    }],
    'requested run' => [static function (Site $site): array {
        $check = Check::factory()->create(['site_id' => $site->id, 'type' => CheckKey::Uptime]);

        return ['POST', '/api/checks/'.$check->id.'/request-run', [], static fn (): int => CheckRunAttempt::query()->where('check_id', $check->id)->count(), '"archived_at" is null'];
    }],
    'tag attach' => [static fn (Site $site): array => ['POST', '/api/tags', ['team_id' => 1, 'name' => 'raced', 'monitors' => [$site->id]],
        static fn (): int => DB::table('taggables')->where('taggable_id', $site->id)->count()]],
    'incident note' => [static function (Site $site): array {
        $incident = Incident::factory()->create(['check_id' => Check::factory()->create(['site_id' => $site->id])->id, 'resolved_at' => now()]);

        return ['PUT', '/api/internal/incidents/'.$incident->id.'/note', ['note' => 'raced'], static fn (): ?string => Incident::query()->findOrFail($incident->id)->notes];
    }],
    'incident reminders snooze' => [static function (Site $site): array {
        $incident = Incident::factory()->create(['check_id' => Check::factory()->create(['site_id' => $site->id])->id]);

        return ['POST', '/api/internal/incidents/'.$incident->id.'/snooze', ['minutes' => 10], static fn (): mixed => Incident::query()->findOrFail($incident->id)->snoozed_until];
    }],
]);

it('leaves out an activity row whose site moved to another client while the feed was read', function (): void {
    $fixture = siteWriteRaceFixture();
    $check = Check::factory()->create(['site_id' => $fixture['site']->id, 'type' => CheckKey::Uptime]);
    Event::factory()->create(['check_id' => $check->id, 'client_id' => $fixture['from']->id, 'from_result' => CheckResult::Failed,
        'to_result' => CheckResult::Succeeded, 'occurred_at' => now()->subHour(), 'notification_routed_at' => now()->subHour()]);
    // ApiTestCase validates against the pinned Oh Dear spec, which has no activity endpoint: call the kernel directly.
    $visible = app(HttpKernel::class)->handle(Request::create('/api/activity', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$fixture['token'], 'HTTP_ACCEPT' => 'application/json']));
    expect(json_decode((string) $visible->getContent(), true)['data'] ?? null)->toHaveCount(1)->{0}->client_ref->toBe('client:'.$fixture['from']->id);
    app('auth')->forgetGuards();
    app()->instance('request', Request::create('/'));

    // The groups query still sees the site under the token's client; the head events are read after the move.
    $response = siteWriteRace(siteWriteRaceMove($fixture), 'GET', '/api/activity', token: $fixture['token'], pauseAfter: 'event_flap_groups');

    expect($response['status'])->toBe(200, json_encode($response['body']))
        ->and(json_encode($response['body']))->not->toContain('client:'.$fixture['to']->id)->not->toContain($fixture['to']->name);
});
