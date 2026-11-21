<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Actions\ChangeMemberAccess;
use Heptapod\Actions\CreateCronTask;
use Heptapod\Actions\CreateMaintenancePeriod;
use Heptapod\Actions\DeleteSite;
use Heptapod\Actions\EndMaintenanceWindow;
use Heptapod\Actions\RevokeApiToken;
use Heptapod\Actions\SnoozeCheck;
use Heptapod\Actions\SnoozeCronTask;
use Heptapod\Actions\StartMaintenance;
use Heptapod\Actions\UpdateMaintenancePeriod;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\CronTaskStore;
use Heptapod\Contracts\Notifications\ChannelRegistry;
use Heptapod\Enums\ClientScope;
use Heptapod\Enums\RecipientKind;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRunAttempt;
use Heptapod\Models\Client;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\Invitation;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

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
        ->and(Site::query()->findOrFail($fixture['site']->id)->client_id)->toBe($fixture['to']->id)
        ->and($response['waited'])->toBeTrue();
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

        return ['POST', '/api/monitors/'.$site->id.'/stop-maintenance', [], static fn (): string => MaintenancePeriod::query()->findOrFail($window->id)->ends_at->format('Y-m-d H:i:s.u')];
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
    'check snooze' => [static function (Site $site): array {
        $check = Check::factory()->create(['site_id' => $site->id, 'type' => CheckKey::Uptime]);

        return ['POST', '/api/checks/'.$check->id.'/snooze', ['minutes' => 10], static fn (): int => DB::table('check_snoozes')->where('check_id', $check->id)->count()];
    }],
    'check unsnooze' => [static function (Site $site, User $owner): array {
        $check = Check::factory()->create(['site_id' => $site->id, 'type' => CheckKey::Uptime]);
        app(SnoozeCheck::class)($check, $owner, CarbonImmutable::now('UTC')->addHour());

        return ['POST', '/api/checks/'.$check->id.'/unsnooze', [], static fn (): int => DB::table('check_snoozes')->where('check_id', $check->id)->whereNull('unsnoozed_at')->count()];
    }],
    'check enable' => [static function (Site $site): array {
        $check = Check::factory()->create(['site_id' => $site->id, 'type' => CheckKey::Uptime, 'enabled' => false]);

        return ['POST', '/api/checks/'.$check->id.'/enable', [], static fn (): bool => Check::query()->findOrFail($check->id)->enabled];
    }],
    'check disable' => [static function (Site $site): array {
        $check = Check::factory()->create(['site_id' => $site->id, 'type' => CheckKey::Uptime]);

        return ['POST', '/api/checks/'.$check->id.'/disable', [], static fn (): bool => Check::query()->findOrFail($check->id)->enabled];
    }],
    'monitor update' => [static fn (Site $site): array => ['PUT', '/api/monitors/'.$site->id, ['friendly_name' => 'raced'],
        static fn (): ?string => Site::query()->findOrFail($site->id)->friendly_name]],
    'monitor delete' => [static fn (Site $site): array => ['DELETE', '/api/monitors/'.$site->id, [], static fn (): bool => Site::query()->whereKey($site->id)->exists()]],
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
        ->and(json_encode($response['body']))->not->toContain('client:'.$fixture['to']->id)->not->toContain($fixture['to']->name)
        ->and($response['waited'])->toBeTrue();
});

/*
 * Activity was recorded within the minute, so the request takes no lock on the member before it authorizes; an API request
 * waits on its token row first, which the revocation deletes, and so is refused there already.
 */
it('refuses a check snooze change by a member whose access to the client is revoked while it waits', function (string $method): void {
    $fixture = siteWriteRaceFixture();
    $member = User::factory()->create(['client_scope' => ClientScope::Restricted, 'last_active_at' => now()]);
    $member->assignRole('Member');
    $member->clients()->sync([$fixture['from']->id]);
    $check = Check::factory()->create(['site_id' => $fixture['site']->id, 'type' => CheckKey::Uptime]);
    if ($method === 'DELETE') {
        app(SnoozeCheck::class)($check, $fixture['owner'], CarbonImmutable::now('UTC')->addHour());
    }
    $snoozes = static fn (): array => DB::table('check_snoozes')->where('check_id', $check->id)->orderBy('id')->get(['id', 'unsnoozed_at'])->map(static fn (object $row): array => (array) $row)->all();
    $before = $snoozes();

    $response = siteWriteRace(static fn (): mixed => app(ChangeMemberAccess::class)($fixture['owner'], $member, ['role' => 'Member', 'client_ids' => [$fixture['to']->id]]),
        $method, '/sites/'.$fixture['site']->id.'/checks/'.$check->id.'/snooze', $method === 'POST' ? ['minutes' => 10] : [], webUser: $member);

    expect($response['status'])->toBe(404, json_encode($response['body']))
        ->and($snoozes())->toBe($before)
        ->and($response['waited'])->toBeTrue();
})->with(['snooze' => 'POST', 'unsnooze' => 'DELETE']);

/* DeleteSite takes the access-structure lock exclusive first, so a write authorized under it waits for the deletion. */
it('answers 404 to a write whose site is deleted while it waits', function (Closure $prepare): void {
    $fixture = siteWriteRaceFixture();
    $token = $fixture['owner']->createToken('owner', ['*'])->plainTextToken;
    /** @var array{0: string, 1: string, 2: array<string, mixed>, 3?: string} $request */
    $request = $prepare($fixture['site'], $fixture['owner']);
    [$method, $uri, $body] = $request;

    $response = siteWriteRace(static function () use ($fixture): void {
        DB::select('SELECT pg_advisory_xact_lock(?)', [SiteWriteRacePause]);
        app(DeleteSite::class)($fixture['site']);
    }, $method, $uri, $body, $token, pauseAfter: $request[3] ?? '');

    expect($response['status'])->toBe(404, json_encode($response['body']))
        ->and(Site::query()->whereKey($fixture['site']->id)->exists())->toBeFalse()
        ->and(MaintenancePeriod::query()->where('site_id', $fixture['site']->id)->exists())->toBeFalse()
        ->and($response['waited'])->toBeTrue();
})->with([
    'check unsnooze' => [static function (Site $site, User $owner): array {
        $check = Check::factory()->create(['site_id' => $site->id, 'type' => CheckKey::Uptime]);
        app(SnoozeCheck::class)($check, $owner, CarbonImmutable::now('UTC')->addHour());

        return ['POST', '/api/checks/'.$check->id.'/unsnooze', []];
    }],
    'maintenance start' => [static fn (Site $site): array => ['POST', '/api/monitors/'.$site->id.'/start-maintenance', []]],
    'incident note' => [static function (Site $site): array {
        $incident = Incident::factory()->create(['check_id' => Check::factory()->create(['site_id' => $site->id])->id, 'resolved_at' => now()]);

        return ['PUT', '/api/internal/incidents/'.$incident->id.'/note', ['note' => 'raced']];
    }],
    // The incident is read while its site still exists; the site is gone by the time the incident's site is loaded.
    'incident note, site gone after the incident was read' => [static function (Site $site): array {
        $incident = Incident::factory()->create(['check_id' => Check::factory()->create(['site_id' => $site->id])->id, 'resolved_at' => now()]);

        return ['PUT', '/api/internal/incidents/'.$incident->id.'/note', ['note' => 'raced'], 'from "incidents" where'];
    }],
]);

/* A move that commits while the write waits: the write then reads the site under its new client and lands there when allowed. */
it('lands a write of an unscoped token in the client its site moved to while it waited', function (): void {
    $fixture = siteWriteRaceFixture();
    $token = $fixture['owner']->createToken('owner', ['*'])->plainTextToken;

    $response = siteWriteRace(siteWriteRaceMove($fixture), 'POST', '/api/monitors/'.$fixture['site']->id.'/start-maintenance', token: $token);

    expect($response['status'])->toBe(200, json_encode($response['body']))
        ->and(MaintenancePeriod::query()->where('site_id', $fixture['site']->id)->count())->toBe(1)
        ->and(Site::query()->findOrFail($fixture['site']->id)->client_id)->toBe($fixture['to']->id)
        ->and($response['waited'])->toBeTrue();
});

it('refuses a write whose API token is revoked while it waits', function (): void {
    $fixture = siteWriteRaceFixture();
    $created = $fixture['owner']->createToken('revoked', ['*']);
    $check = Check::factory()->create(['site_id' => $fixture['site']->id, 'type' => CheckKey::Uptime]);

    $response = siteWriteRace(static fn () => app(RevokeApiToken::class)($fixture['owner'], $created->accessToken->id),
        'POST', '/api/checks/'.$check->id.'/snooze', ['minutes' => 10], $created->plainTextToken);

    expect($response['status'])->toBe(401, json_encode($response['body']))
        ->and(DB::table('check_snoozes')->where('check_id', $check->id)->count())->toBe(0)
        ->and($response['waited'])->toBeTrue();
});

/* Activity was recorded within the minute, so the admin's request takes no lock on the account before it authorizes. */
it('refuses a write by an admin demoted while it waits', function (Closure $prepare): void {
    $fixture = siteWriteRaceFixture();
    $admin = User::factory()->create(['last_active_at' => now()]);
    $admin->assignRole('Admin');
    /** @var array{0: string, 1: string, 2: array<string, mixed>, 3: Closure(): mixed, 4: int} $request */
    $request = $prepare($fixture['site']);
    [$method, $uri, $body, $observe, $status] = $request;
    $before = $observe();

    $response = siteWriteRace(static fn (): mixed => app(ChangeMemberAccess::class)($fixture['owner'], $admin, ['role' => 'Member', 'client_ids' => [$fixture['from']->id]]),
        $method, $uri, $body, webUser: $admin);

    expect($response['status'])->toBe($status, (string) $response['blocked_on'])
        ->and($observe())->toEqual($before)
        ->and($response['waited'])->toBeTrue();
})->with([
    'maintenance edit' => [static function (Site $site): array {
        $window = app(CreateMaintenancePeriod::class)($site, 'planned', CarbonImmutable::now()->addHour(), CarbonImmutable::now()->addHours(2));

        return ['PUT', '/maintenance/'.$window->id, ['name' => 'edited', 'starts_local' => CarbonImmutable::now('UTC')->addHour()->format('Y-m-d H:i'),
            'ends_local' => CarbonImmutable::now('UTC')->addHours(3)->format('Y-m-d H:i'), 'timezone' => 'UTC', 'mute_alerts' => true,
            'catch_up_at_end' => true, 'target_type' => 'site', 'target_id' => $site->id], static fn (): ?string => MaintenancePeriod::query()->findOrFail($window->id)->name, 403];
    }],
    'maintenance end' => [static function (Site $site): array {
        $window = app(CreateMaintenancePeriod::class)($site, 'running', CarbonImmutable::now()->subMinute(), CarbonImmutable::now()->addHour());

        return ['POST', '/maintenance/'.$window->id.'/end', [], static fn (): string => MaintenancePeriod::query()->findOrFail($window->id)->ends_at->format('Y-m-d H:i:s.u'), 403];
    }],
    'maintenance delete' => [static function (Site $site): array {
        $window = app(CreateMaintenancePeriod::class)($site, 'planned', CarbonImmutable::now()->addHour(), CarbonImmutable::now()->addHours(2));

        return ['DELETE', '/maintenance/'.$window->id, [], static fn (): bool => MaintenancePeriod::query()->whereKey($window->id)->exists(), 403];
    }],
    'check disable' => [static function (Site $site): array {
        $check = Check::factory()->create(['site_id' => $site->id, 'type' => CheckKey::Uptime]);

        return ['POST', '/sites/'.$site->id.'/checks/'.$check->id.'/disable', [], static fn (): bool => Check::query()->findOrFail($check->id)->enabled, 403];
    }],
]);

it('refuses a client channel mute once its site has moved to another client', function (): void {
    $fixture = siteWriteRaceFixture();
    $fixture['owner']->update(['last_active_at' => now()]);
    $channel = NotificationDestination::query()->create(['recipient_kind' => RecipientKind::Client, 'client_id' => $fixture['from']->id, 'channel' => 'mail',
        'label' => 'Office', 'enabled' => true, 'config' => app(ChannelRegistry::class)->get('mail')->configSchema()->validateConfig(['email' => 'office@race.test'])]);

    $response = siteWriteRace(siteWriteRaceMove($fixture), 'PUT', '/notification-channels/'.$channel->id.'/site-mutes/'.$fixture['site']->id, webUser: $fixture['owner']);

    expect($response['status'])->toBe(422, is_string($response['body']) ? 'HTML page' : json_encode($response['body']))
        ->and(DB::table('notification_site_mutes')->where('destination_id', $channel->id)->count())->toBe(0)
        ->and($response['waited'])->toBeTrue();
});

/*
 * The web feed shows the client of each row's site. The request pauses after its head events query, the site moves, and the
 * eager-loaded site would show the new client: the row is left out instead.
 */
it('leaves out a web activity row whose site moved while its relations were loaded', function (): void {
    $fixture = siteWriteRaceFixture();
    $member = User::factory()->create(['client_scope' => ClientScope::Restricted, 'last_active_at' => now()]);
    $member->assignRole('Member');
    $member->clients()->sync([$fixture['from']->id]);
    $fixture['site']->update(['friendly_name' => 'raced-site-'.$fixture['site']->id]);
    $check = Check::factory()->create(['site_id' => $fixture['site']->id, 'type' => CheckKey::Uptime]);
    Event::factory()->create(['check_id' => $check->id, 'client_id' => $fixture['from']->id, 'from_result' => CheckResult::Failed,
        'to_result' => CheckResult::Succeeded, 'occurred_at' => now()->subHour(), 'notification_routed_at' => now()->subHour()]);
    $this->actingAs($member)->get('/activity')->assertOk()->assertSee('raced-site-'.$fixture['site']->id);
    app('auth')->forgetGuards();

    $response = siteWriteRace(siteWriteRaceMove($fixture), 'GET', '/activity', webUser: $member, pauseAfter: 'select * from "events" where "type" <>');

    expect($response['status'])->toBe(200)
        ->and($response['body'])->toBeString()->not->toContain($fixture['to']->name)->not->toContain('raced-site-'.$fixture['site']->id)
        ->and($response['waited'])->toBeTrue();
});

/*
 * Two admins on one window: a lifecycle change commits while the other's edit or deletion waits for the window. The
 * waiting request decides on the window as it is now.
 */
it('refuses a maintenance edit or deletion that a concurrent lifecycle change made stale', function (string $change): void {
    $fixture = siteWriteRaceFixture();
    $admin = User::factory()->create();
    $admin->assignRole('Admin');
    $window = $change === 'ended'
        ? app(CreateMaintenancePeriod::class)($fixture['site'], 'running', CarbonImmutable::now()->subMinute(), CarbonImmutable::now()->addHour())
        : app(CreateMaintenancePeriod::class)($fixture['site'], 'planned', CarbonImmutable::now()->addHour(), CarbonImmutable::now()->addHours(2));
    $form = ['name' => 'edited', 'starts_local' => CarbonImmutable::now('UTC')->subMinute()->format('Y-m-d H:i'),
        'ends_local' => CarbonImmutable::now('UTC')->addHours(3)->format('Y-m-d H:i'), 'timezone' => 'UTC', 'mute_alerts' => true,
        'catch_up_at_end' => true, 'target_type' => 'site', 'target_id' => $fixture['site']->id];

    $response = $change === 'ended'
        ? siteWriteRace(static fn () => app(EndMaintenanceWindow::class)($fixture['owner'], $window), 'PUT', '/maintenance/'.$window->id, $form, webUser: $admin)
        : siteWriteRace(static fn () => app(UpdateMaintenancePeriod::class)($window, 'planned', CarbonImmutable::now()->subMinute(), CarbonImmutable::now()->addHours(2)),
            'DELETE', '/maintenance/'.$window->id, webUser: $admin);

    $locked = MaintenancePeriod::query()->find($window->id);
    expect($response['status'])->toBe(409, json_encode($response['body']))
        ->and($locked?->name)->toBe($change === 'ended' ? 'running' : 'planned')
        ->and($change === 'ended' ? $locked?->ends_at->isPast() : $locked?->starts_at->isPast())->toBeTrue()
        ->and($response['waited'])->toBeTrue();
})->with(['ended', 'started']);

/*
 * Structural changes and site writes meet only at the access-structure lock (NotificationLocks level 0). Each test below
 * holds one structural change after it took that lock and has the others queue on it; every transaction then completes.
 * The first two are the cycles the per-row client and user locks formed: API site deletion against a membership change of
 * its actor, and against an invitation acceptance and the inviter's profile deletion.
 */

/** A PHP process that runs $code with the test database, as session $application; it prints 'done' when $code returns. */
function siteWriteRaceAction(string $application, string $code, array $environment = []): Process
{
    $script = <<<PHP
        require 'vendor/autoload.php';
        \$app = require 'bootstrap/app.php';
        \$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
        Illuminate\\Support\\Facades\\DB::statement("SET application_name = '{$application}'");
        {$code}
        echo 'done';
        PHP;

    return new Process([PHP_BINARY, '-r', $script], base_path(), [...siteWriteRaceEnvironment(), ...$environment], timeout: 30);
}

/**
 * Starts $first, which pauses right after it took the access-structure lock, then each of $queued, waits until every one of
 * them is blocked, and lets $first go on. Returns the statements the queued processes waited in.
 *
 * @param  list<Process>  $queued  started here; each runs as session site_write_race_queued_<index>
 * @return list<string>
 */
function siteWriteRaceQueueBehind(Process $first, array $queued): array
{
    DB::beginTransaction();
    DB::select('SELECT pg_advisory_xact_lock(?)', [SiteWriteRacePause]);
    $first->start();
    siteWriteRaceBlocked($first, 'site_write_race', (int) DB::scalar('SELECT pg_backend_pid()'));
    $statements = [];
    foreach ($queued as $index => $process) {
        $process->start();
        $statements[] = siteWriteRaceBlocked($process, 'site_write_race_queued_'.$index);
    }
    DB::commit();

    return $statements;
}

it('completes a site deletion and a membership change for its actor without a deadlock', function (): void {
    $fixture = siteWriteRaceFixture();
    $admin = User::factory()->create();
    $admin->assignRole('Admin');
    $token = $admin->createToken('admin', ['*'])->plainTextToken;
    DB::commit();
    $delete = siteWriteRaceRequest('DELETE', '/api/monitors/'.$fixture['site']->id, token: $token, pauseAfter: 'pg_advisory_xact_lock(7341');
    $change = siteWriteRaceAction('site_write_race_queued_0', <<<'PHP'
        app(Heptapod\Actions\ChangeMemberAccess::class)(Heptapod\Models\User::findOrFail((int) getenv('RACE_OWNER')),
            Heptapod\Models\User::findOrFail((int) getenv('RACE_MEMBER')), ['role' => 'Member', 'client_ids' => [(int) getenv('RACE_CLIENT')]]);
        PHP, ['RACE_OWNER' => (string) $fixture['owner']->id, 'RACE_MEMBER' => (string) $admin->id, 'RACE_CLIENT' => (string) $fixture['from']->id]);
    try {
        $waitedOn = siteWriteRaceQueueBehind($delete, [$change]);
        $response = siteWriteRaceResponse($delete);
        $change->wait();

        expect($change->getOutput())->toBe('done', $change->getErrorOutput())
            ->and($response['status'])->toBe(204, json_encode($response['body']))
            ->and($waitedOn[0])->toContain('pg_advisory_xact_lock')
            ->and(Site::query()->whereKey($fixture['site']->id)->exists())->toBeFalse()
            ->and(DB::table('client_user')->where('user_id', $admin->id)->pluck('client_id')->all())->toBe([$fixture['from']->id]);
    } finally {
        $delete->stop(1);
        $change->stop(1);
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DB::beginTransaction();
    }
});

it('completes a site deletion, an invitation acceptance and the inviter\'s profile deletion without a deadlock', function (): void {
    $fixture = siteWriteRaceFixture();
    $inviter = User::factory()->create();
    $inviter->assignRole('Admin');
    $token = $inviter->createToken('inviter', ['*'])->plainTextToken;
    $invite = str_repeat('b', 64);
    Invitation::factory()->create(['email' => 'invited-'.$fixture['site']->id.'@example.test', 'token_hash' => hash('sha256', $invite), 'role' => 'Member',
        'inviter_id' => $inviter->id, 'client_scope' => ClientScope::Restricted, 'client_ids' => [$fixture['from']->id]]);
    DB::commit();
    $delete = siteWriteRaceRequest('DELETE', '/api/monitors/'.$fixture['site']->id, token: $token, pauseAfter: 'pg_advisory_xact_lock(7341');
    $accept = siteWriteRaceAction('site_write_race_queued_0', <<<'PHP'
        app(Heptapod\Actions\AcceptInvitation::class)((string) getenv('RACE_INVITE'), ['name' => 'Invited', 'password' => 'invited-password-1', 'password_confirmation' => 'invited-password-1']);
        PHP, ['RACE_INVITE' => $invite]);
    $deleteProfile = siteWriteRaceAction('site_write_race_queued_1', <<<'PHP'
        app(Heptapod\Actions\DeleteProfile::class)(Heptapod\Models\User::findOrFail((int) getenv('RACE_INVITER')));
        PHP, ['RACE_INVITER' => (string) $inviter->id]);
    try {
        $waitedOn = siteWriteRaceQueueBehind($delete, [$accept, $deleteProfile]);
        $response = siteWriteRaceResponse($delete);
        $accept->wait();
        $deleteProfile->wait();

        expect($response['status'])->toBe(204, json_encode($response['body']))
            ->and($accept->getOutput())->toBe('done', $accept->getErrorOutput())
            ->and($deleteProfile->getOutput())->toBe('done', $deleteProfile->getErrorOutput())
            ->and($waitedOn)->each->toContain('pg_advisory_xact_lock')
            ->and(Site::query()->whereKey($fixture['site']->id)->exists())->toBeFalse()
            ->and(User::query()->whereKey($inviter->id)->exists())->toBeFalse()
            ->and(User::query()->where('email', 'invited-'.$fixture['site']->id.'@example.test')->sole()->clients()->pluck('clients.id')->all())->toBe([$fixture['from']->id]);
    } finally {
        foreach ([$delete, $accept, $deleteProfile] as $process) {
            $process->stop(1);
        }
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DB::beginTransaction();
    }
});

/* A keyed write (Idempotency-Key) queued behind a move: its record and the window land under the site's new client. */
it('completes an idempotent write that waited for a move of its site', function (): void {
    $fixture = siteWriteRaceFixture();
    $token = $fixture['owner']->createToken('owner', ['*'])->plainTextToken;

    $response = siteWriteRace(siteWriteRaceMove($fixture), 'POST', '/api/monitors/'.$fixture['site']->id.'/start-maintenance', token: $token,
        headers: ['Idempotency-Key' => 'race-'.$fixture['site']->id]);

    expect($response['status'])->toBe(200, json_encode($response['body']))
        ->and($response['waited'])->toBeTrue()
        ->and(MaintenancePeriod::query()->where('site_id', $fixture['site']->id)->count())->toBe(1)
        ->and(DB::table('api_idempotency_records')->where('target', 'site:'.$fixture['site']->id)->whereNotNull('response_status')->count())->toBe(1)
        ->and(Site::query()->findOrFail($fixture['site']->id)->client_id)->toBe($fixture['to']->id);
});

/*
 * A structural change that holds the access-structure lock longer than a write waits for it: the write is refused as busy,
 * with its code and Retry-After, instead of running into the statement timeout.
 */
it('refuses a write as busy while a structural change holds the access lock too long', function (): void {
    $fixture = siteWriteRaceFixture();
    $token = $fixture['owner']->createToken('owner', ['*'])->plainTextToken;
    DB::commit();
    $request = siteWriteRaceRequest('POST', '/api/monitors/'.$fixture['site']->id.'/start-maintenance', token: $token);
    try {
        DB::beginTransaction();
        NotificationLocks::accessExclusive();
        $request->start();
        $waitedOn = siteWriteRaceBlocked($request, 'site_write_race', (int) DB::scalar('SELECT pg_backend_pid()'));
        $response = siteWriteRaceResponse($request);
        DB::rollBack();

        expect($response['status'])->toBe(409, json_encode($response['body']))
            ->and($response['body'])->toMatchArray(['error' => 'busy_try_again', 'code' => 'busy_try_again'])
            ->and($response['headers']['retry-after'] ?? null)->toBe('5')
            ->and($waitedOn)->toContain('pg_advisory_xact_lock_shared')
            ->and(MaintenancePeriod::query()->where('site_id', $fixture['site']->id)->exists())->toBeFalse();
    } finally {
        $request->stop(1);
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DB::beginTransaction();
    }
});
