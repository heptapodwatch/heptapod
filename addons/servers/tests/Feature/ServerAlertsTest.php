<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Actions\ClaimNotificationDelivery;
use Heptapod\Actions\CreateApiToken;
use Heptapod\Actions\EmitNotificationDigests;
use Heptapod\Actions\SendNotificationDelivery;
use Heptapod\Actions\SetNotificationDestinationEnabled;
use Heptapod\Addons\Channels\TemplateMail;
use Heptapod\Addons\Servers\Actions\EvaluateServerIssues;
use Heptapod\Addons\Servers\Server;
use Heptapod\Addons\Servers\ServerIssue;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\Notifications\ChannelRegistry;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Enums\NotificationMode;
use Heptapod\Jobs\SendNotification;
use Heptapod\Models\Check;
use Heptapod\Models\Client;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

require_once __DIR__.'/../Pest.php';
require_once __DIR__.'/../../../../tests/Support/NotificationChannels.php';
require_once __DIR__.'/../../../../tests/Support/SystemIssueRecipients.php';

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-27T07:00:00Z'));
    alertsSwitch(true);
    config(['mail.default' => 'smtp']);
    Mail::fake();
    Http::preventStrayRequests();
    Http::fake(['https://receiver.test/*' => Http::response('', 200)]);
});

function alertsSwitch(bool $on): void
{
    config(['heptapod.servers.enabled_by_server' => $on]);
    $settings = app(InstanceSettings::class);
    $settings->servers_enabled = $on;
    $settings->save();
}

/**
 * A server with its agent token, as "Add server" leaves it.
 *
 * @param  array<string, mixed>  $settings
 * @return array{Server, string}
 */
function alertsServer(string $name = 'web-1', array $settings = []): array
{
    $server = Server::named($name);
    $token = $server->issueToken()->plainText();
    $server->forceFill(['settings' => $settings])->save();

    return [$server, $token];
}

/**
 * One agent report, observed now unless given. Calm by default: every mount 50 % full, RAM 50 % used, a 5-minute load of 1 on 4 vCPU.
 *
 * @param  array{disks?: array<string, float|int>, memory?: float|int, load?: float, ips?: list<string>}  $values
 */
function alertsReport(TestCase $test, string $token, array $values = [], ?CarbonImmutable $observedAt = null): TestResponse
{
    $total = 100_000_000_000;
    $memory = 8_000_000_000;
    $sample = ['sample_id' => (string) Str::uuid(), 'observed_at' => ($observedAt ?? now())->utc()->format('Y-m-d\TH:i:s\Z'),
        'agent' => ['version' => '0.1.0', 'os' => 'linux', 'arch' => 'amd64', 'distro' => 'Debian 13', 'kernel' => '6.12.0-1-amd64'],
        'host' => ['hostname' => 'web-1', 'uptime_seconds' => 3600, 'reboot_required' => false, 'ips' => $values['ips'] ?? ['49.12.88.204']],
        'cpu' => ['cores' => 4, 'used_percent' => 20], 'load' => ['1' => 1.0, '5' => $values['load'] ?? 1.0, '15' => 1.0],
        'memory' => ['total_bytes' => $memory, 'available_bytes' => (int) round($memory * (100 - ($values['memory'] ?? 50)) / 100)],
        'filesystems' => array_map(static fn (string $mount, float|int $used): array => ['mount' => $mount, 'total_bytes' => $total,
            'free_bytes' => (int) round($total * (100 - $used) / 100)], array_keys($values['disks'] ?? ['/' => 50]), $values['disks'] ?? ['/' => 50]),
        'updates' => ['pending' => 0, 'security' => 0]];

    return $test->call('POST', '/agent/v1/report', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token],
        content: json_encode($sample, JSON_THROW_ON_ERROR))->assertStatus(202);
}

/**
 * Reports once a minute, $count times; the clock ends one minute after the last report.
 *
 * @param  array{disks?: array<string, float|int>, memory?: float|int, load?: float, ips?: list<string>}  $values
 */
function alertsMinutes(TestCase $test, string $token, int $count, array $values = []): void
{
    for ($i = 0; $i < $count; $i++) {
        alertsReport($test, $token, $values);
        $test->travel(60)->seconds();
    }
}

/** @return list<string> kind:scope of the server's open issues */
function alertsOpen(Server $server): array
{
    return ServerIssue::query()->where('server_id', $server->id)->whereNull('resolved_at')->orderBy('id')->get()
        ->map(static fn (ServerIssue $issue): string => $issue->kind->value.':'.$issue->scope)->all();
}

/** The deliveries of one server issue phase ('opened' or 'resolved'), by channel. */
function alertsDeliveries(string $phase): Collection
{
    return DB::table('deliveries')->join('events', 'events.id', '=', 'deliveries.event_id')->where('events.type', 'server.issue_'.$phase)
        ->orderBy('deliveries.destination_id')->get(['deliveries.*'])->keyBy('destination_id');
}

function alertsTeamWebhook(?int $scopeClientId = null, NotificationMode $mode = NotificationMode::Immediate): NotificationDestination
{
    $channel = notificationWebhook();
    $channel->forceFill(['scope_client_id' => $scopeClientId])->save();
    DB::table('notification_preferences')->where('destination_id', $channel->id)->where('category', 'servers')->update(['mode' => $mode->value]);

    return $channel;
}

/** @return list<array<string, mixed>> the webhook bodies sent so far */
function alertsWebhookBodies(): array
{
    return Http::recorded()->filter(static fn (array $pair): bool => $pair[0] instanceof HttpRequest && str_starts_with($pair[0]->url(), 'https://receiver.test/'))
        ->map(static fn (array $pair): array => json_decode($pair[0]->body(), true, flags: JSON_THROW_ON_ERROR))->values()->all();
}

it('opens each kind after its duration and not before, a missed report breaks the streak, and a sample below resolves it', function (string $kind, string $scope, array $above, array $below, int $minutes): void {
    [$server, $token] = alertsServer();

    // Every report at or above the threshold, but one minute short of the duration.
    alertsMinutes($this, $token, $minutes, $above);
    expect(alertsOpen($server))->toBe([]);

    // A missed report: the streak starts again after it.
    $this->travel(2)->minutes();
    alertsMinutes($this, $token, $minutes, $above);
    expect(alertsOpen($server))->toBe([]);

    alertsMinutes($this, $token, 1, $above);
    expect(alertsOpen($server))->toBe([$kind.':'.$scope]);
    $issue = ServerIssue::query()->where('server_id', $server->id)->sole();
    expect($issue->opened_values['minutes'])->toBe($minutes)
        ->and($issue->opened_at->equalTo(now()->subMinute()))->toBeTrue();

    // Still above: the one issue stays open.
    alertsMinutes($this, $token, 3, $above);
    expect(ServerIssue::query()->where('server_id', $server->id)->count())->toBe(1);

    alertsMinutes($this, $token, 1, $below);
    expect(alertsOpen($server))->toBe([])
        ->and($issue->refresh()->resolved_at?->equalTo(now()->subMinute()))->toBeTrue()
        ->and(DB::table('events')->where('server_issue_id', $issue->id)->orderBy('id')->pluck('type')->all())->toBe(['server.issue_opened', 'server.issue_resolved']);
})->with([
    'disk, 90 % for 15 min' => ['disk_full', '/', ['disks' => ['/' => 92]], ['disks' => ['/' => 70]], 15],
    'RAM, 90 % for 10 min' => ['memory_low', '', ['memory' => 95], ['memory' => 60], 10],
    'load, 2 × 4 vCPU for 15 min' => ['load_high', '', ['load' => 8.0], ['load' => 7.9], 15],
]);

it('keeps an issue open while reports stop and keeps one open issue per server, kind and mount', function (): void {
    [$server, $token] = alertsServer(settings: ['mounts' => [['mount' => '/data', 'minutes' => 5]]]);
    alertsMinutes($this, $token, 16, ['disks' => ['/' => 95, '/data' => 99]]);
    expect(alertsOpen($server))->toBe(['disk_full:/data', 'disk_full:/']);

    // No reports for an hour: the disk issues stay open next to "No report".
    $this->travel(1)->hours();
    app(EvaluateServerIssues::class)->noReports(now());
    expect(alertsOpen($server))->toBe(['disk_full:/data', 'disk_full:/', 'no_report:']);

    expect(fn () => ServerIssue::query()->create(['server_id' => $server->id, 'kind' => 'disk_full', 'scope' => '/', 'opened_at' => now(), 'opened_values' => []]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('never opens an issue for an ignored mount, and ends a disk issue without a recovery when its mount becomes ignored', function (): void {
    $owner = systemIssueRecipient('Owner');
    [$server, $token] = alertsServer(settings: ['ignored_mounts' => ['/boot/efi']]);
    alertsMinutes($this, $token, 16, ['disks' => ['/' => 95, '/boot/efi' => 100]]);
    expect(alertsOpen($server))->toBe(['disk_full:/']);
    dispatchNotifications();
    expect(alertsDeliveries('opened')->pluck('state')->all())->toBe(['sent']);

    $this->actingAs($owner)->patch("/servers/{$server->id}", ['name' => 'web-1', 'mounts' => [], 'ignored_mounts' => ['/', '/boot/efi'],
        'memory_percent' => 90, 'memory_minutes' => 10, 'load_per_core' => 2, 'load_minutes' => 15, 'no_report_minutes' => 2])->assertRedirect();

    expect(alertsOpen($server))->toBe([])
        ->and(ServerIssue::query()->sole()->resolved_values)->toBe(['reason' => 'mount_ignored'])
        ->and(alertsDeliveries('resolved'))->toBeEmpty();
    alertsMinutes($this, $token, 16, ['disks' => ['/' => 95, '/boot/efi' => 100]]);
    expect(alertsOpen($server))->toBe([]);
});

it('opens "No report" once the newest report is older than "No report after", never before the first report, and resolves it with the next sample', function (): void {
    [$server, $token] = alertsServer(settings: ['no_report_minutes' => 5]);
    [$awaiting] = alertsServer('new-server');
    alertsReport($this, $token);
    $reported = now();

    $this->travelTo($reported->addMinutes(5));
    app(EvaluateServerIssues::class)->noReports(now());
    expect(alertsOpen($server))->toBe([]);

    $this->travelTo($reported->addMinutes(7));
    app(EvaluateServerIssues::class)->noReports(now());
    app(EvaluateServerIssues::class)->noReports(now());
    $issue = ServerIssue::query()->where('server_id', $server->id)->sole();
    expect($issue->kind->value)->toBe('no_report')
        ->and($issue->opened_at->equalTo($reported->addMinutes(5)))->toBeTrue()
        ->and(alertsOpen($awaiting))->toBe([]);

    alertsReport($this, $token);
    expect(alertsOpen($server))->toBe([])->and($issue->refresh()->resolved_at?->equalTo(now()))->toBeTrue();
});

it('delivers to Owners, Admins and opted-in team channels, never to Members or a team channel limited to one client', function (): void {
    $owner = systemIssueRecipient('Owner');
    $admin = systemIssueRecipient('Admin', attributes: ['locale' => 'de']);
    $member = systemIssueRecipient('Member');
    $team = alertsTeamWebhook();
    $limited = alertsTeamWebhook(Client::factory()->create()->id);
    $silentTeam = notificationWebhook();
    [$server, $token] = alertsServer();

    alertsMinutes($this, $token, 16, ['disks' => ['/' => 92]]);
    dispatchNotifications();

    $opened = alertsDeliveries('opened');
    expect($opened->keys()->all())->toBe([systemIssueAccountChannel($owner)->id, systemIssueAccountChannel($admin)->id, $team->id])
        ->and($opened->pluck('state')->unique()->all())->toBe(['sent'])
        ->and($opened->pluck('category')->unique()->all())->toBe(['servers'])
        ->and($opened->keys()->intersect([systemIssueAccountChannel($member)->id, $limited->id, $silentTeam->id])->all())->toBe([]);
    Mail::assertSent(TemplateMail::class, fn (TemplateMail $mail): bool => $mail->hasTo($owner->email) && $mail->model->subject === '[Server] Disk almost full on web-1'
        && str_contains($mail->render(), '/ is 92% full, at or above 90% for 15 min.'));
    Mail::assertSent(TemplateMail::class, fn (TemplateMail $mail): bool => $mail->hasTo($admin->email) && $mail->model->subject === '[Server] Festplatte fast voll auf web-1');
    Mail::assertNotSent(TemplateMail::class, fn (TemplateMail $mail): bool => $mail->hasTo($member->email));
    // The webhook names the server instead of a site.
    [$body] = alertsWebhookBodies();
    expect($body)->toMatchArray(['severity' => 'warning', 'site_id' => null, 'site_label' => null, 'site_url' => null, 'check_type' => null,
        'title' => '[Server] Disk almost full on web-1', 'server' => ['id' => $server->id, 'name' => 'web-1', 'url' => route('servers.show', $server->id)]]);

    // Eligibility is current: the Admin who lost the role gets no recovery.
    $admin->syncRoles(['Member']);
    alertsMinutes($this, $token, 1, ['disks' => ['/' => 60]]);
    dispatchNotifications();

    expect(alertsDeliveries('resolved')->keys()->all())->toBe([systemIssueAccountChannel($owner)->id, $team->id])
        ->and(alertsDeliveries('resolved')->pluck('state')->unique()->all())->toBe(['sent']);
    Mail::assertSent(TemplateMail::class, fn (TemplateMail $mail): bool => $mail->hasTo($owner->email) && $mail->model->subject === '[Server recovered] Disk almost full on web-1');
    expect(alertsWebhookBodies()[1])->toMatchArray(['severity' => 'recovered', 'server' => ['id' => $server->id, 'name' => 'web-1', 'url' => route('servers.show', $server->id)]]);
});

it('holds openings while the server is snoozed, sends them when the snooze ends with the issue still open, and drops them when it resolved', function (): void {
    $owner = systemIssueRecipient('Owner');
    [$server, $token] = alertsServer();
    $this->actingAs($owner)->post("/servers/{$server->id}/snooze", ['minutes' => 60])->assertRedirect();
    $until = now()->addHour();

    alertsMinutes($this, $token, 16, ['disks' => ['/' => 92]]);
    dispatchNotifications();
    $opening = alertsDeliveries('opened')->sole();
    expect(alertsOpen($server))->toBe(['disk_full:/'])
        ->and($opening->state)->toBe('pending')->and($opening->last_reason)->toBe('deferred_by_snooze')
        ->and(CarbonImmutable::parse($opening->next_attempt_at)->equalTo($until))->toBeTrue();
    Mail::assertNothingSent();

    // RAM opens too and resolves while snoozed: neither its opening nor a recovery is ever sent.
    alertsMinutes($this, $token, 11, ['disks' => ['/' => 92], 'memory' => 95]);
    alertsMinutes($this, $token, 1, ['disks' => ['/' => 92]]);
    $this->travelTo($until->addMinute());
    dispatchNotifications();

    $openings = DB::table('deliveries')->join('events', 'events.id', '=', 'deliveries.event_id')->where('events.type', 'server.issue_opened')
        ->orderBy('deliveries.id')->get(['deliveries.state', 'deliveries.suppression_reason']);
    expect($openings->map(fn (object $row): array => [$row->state, $row->suppression_reason])->all())->toBe([['sent', null], ['pending', 'snooze']])
        ->and(alertsDeliveries('resolved'))->toBeEmpty();
    Mail::assertSent(TemplateMail::class, 1);
    Mail::assertSent(TemplateMail::class, fn (TemplateMail $mail): bool => $mail->model->subject === '[Server] Disk almost full on web-1');
});

it('sends a held opening at once when the snooze is ended early', function (): void {
    $owner = systemIssueRecipient('Owner');
    [$server, $token] = alertsServer();
    $this->actingAs($owner)->post("/servers/{$server->id}/snooze", ['snooze_until' => now()->addDay()->toIso8601String()])->assertRedirect();
    alertsMinutes($this, $token, 16, ['memory' => 95]);
    dispatchNotifications();
    expect(alertsDeliveries('opened')->sole()->state)->toBe('pending');

    $this->actingAs($owner)->delete("/servers/{$server->id}/snooze")->assertRedirect();
    dispatchNotifications();

    expect($server->refresh()->snoozed_until)->toBeNull()
        ->and(alertsDeliveries('opened')->sole())->toMatchObject(['state' => 'sent', 'last_reason' => null]);
});

it('waits for quiet hours and collects into the daily digest as site warnings do', function (): void {
    $zone = app(InstanceSettings::class)->timezone;
    $owner = systemIssueRecipient('Owner');
    $account = systemIssueAccountChannel($owner);
    // 07:00 UTC lies inside 06:00–10:00 in every zone within two hours of UTC.
    notificationSchedule($account, ['quiet_start' => '06:00', 'quiet_end' => '10:00']);
    $digest = alertsTeamWebhook(mode: NotificationMode::Digest);
    [, $token] = alertsServer();

    alertsMinutes($this, $token, 16, ['load' => 9.5]);
    dispatchNotifications();
    $opened = alertsDeliveries('opened');
    expect($opened[$account->id]->state)->toBe('pending')
        ->and(CarbonImmutable::parse($opened[$account->id]->next_attempt_at)->setTimezone($zone)->format('H:i'))->toBe('10:00')
        ->and($opened[$digest->id])->toMatchObject(['digest' => true, 'state' => 'pending']);
    Mail::assertNothingSent();
    expect(alertsWebhookBodies())->toBe([]);

    // Quiet hours end; the digest's 08:00 has passed by then too.
    $this->travelTo(CarbonImmutable::parse('2026-11-27T10:00:00', $zone));
    dispatchNotifications();
    expect(alertsDeliveries('opened')[$account->id]->state)->toBe('sent');
    $sent = DB::table('deliveries')->join('events', 'events.id', '=', 'deliveries.event_id')->where('events.type', 'notification.digest')
        ->where('deliveries.destination_id', $digest->id)->sole(['deliveries.state', 'events.payload']);
    expect($sent->state)->toBe('sent')->and(json_decode($sent->payload, true)['sites'])->toBe('web-1')
        ->and(collect(alertsWebhookBodies())->pluck('title')->all())->toBe(['Daily summary']);
});

it('lists server issues in Activity for Owners and Admins with a link to the server, never for Members or through the API', function (): void {
    $owner = User::factory()->owner()->create();
    $member = User::factory()->create();
    $member->assignRole('Member');
    [$server, $token] = alertsServer();
    alertsMinutes($this, $token, 16, ['disks' => ['/' => 92]]);
    alertsMinutes($this, $token, 1);
    // The API lists site activity only: an Owner's token, unlimited or limited to one client, never returns a server issue.
    $apiTokens = array_map(static fn (?int $clientId): string => app(CreateApiToken::class)($owner, 'activity', ['activity:read'], $clientId),
        [null, Client::factory()->create()->id]);
    foreach ($apiTokens as $apiToken) {
        $this->app['auth']->forgetGuards();
        $types = collect($this->withToken($apiToken)->getJson('/api/activity')->assertOk()->json('data'))->pluck('event_type');
        expect($types->filter(static fn (string $type): bool => str_starts_with($type, 'server.'))->all())->toBe([]);
    }

    $this->actingAs($owner)->get('/activity')->assertOk()->assertInertia(fn (Assert $page): AssertableInertia => $page->has('events.data', 2)
        ->where('events.data.0.latest.type', 'server.issue_resolved')->where('events.data.0.latest.site_name', 'web-1')
        ->where('events.data.0.latest.server_id', $server->id)->where('events.data.0.latest.message', 'Resolved: Disk almost full (/)')
        ->where('events.data.0.latest.severity', 'recovered')
        ->where('events.data.1.latest.type', 'server.issue_opened')->where('events.data.1.latest.message', 'Disk almost full: / at 92%')
        ->where('events.data.1.latest.severity', 'down'));
    $this->actingAs($owner)->get('/activity?q=web-1')->assertInertia(fn (Assert $page): AssertableInertia => $page->has('events.data', 2));
    $this->actingAs($owner)->get('/activity?q=elsewhere')->assertInertia(fn (Assert $page): AssertableInertia => $page->has('events.data', 0));
    $this->actingAs($member)->get('/activity')->assertInertia(fn (Assert $page): AssertableInertia => $page->has('events.data', 0));

    alertsSwitch(false);
    $this->actingAs($owner)->get('/activity')->assertInertia(fn (Assert $page): AssertableInertia => $page->has('events.data', 0));

    alertsSwitch(true);
    $server->delete();
    $this->app->forgetScopedInstances();
    $this->actingAs($owner)->get('/activity')->assertInertia(fn (Assert $page): AssertableInertia => $page->where('events.data.0.latest.server_id', null)
        ->where('events.data.0.latest.site_name', 'web-1'));
});

it('shows open issues and the snooze on the server page and filters the list to servers that need attention', function (): void {
    $owner = User::factory()->owner()->create();
    [$busy, $token] = alertsServer('busy');
    [, $calmToken] = alertsServer('calm');
    alertsMinutes($this, $token, 16, ['disks' => ['/' => 92], 'load' => 9.0]);
    alertsReport($this, $calmToken);
    $this->actingAs($owner)->post("/servers/{$busy->id}/snooze", ['minutes' => 60]);

    $this->actingAs($owner)->get("/servers/{$busy->id}")->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('server.issues.0.kind', 'disk_full')->where('server.issues.0.scope', '/')->where('server.issues.0.values.threshold', 90)
        ->where('server.issues.1.kind', 'load_high')->has('server.issues', 2)
        ->where('server.snoozed_until', now()->addHour()->toIso8601String()));
    $this->actingAs($owner)->get('/servers?filter[state]=attention')->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('filters.state', 'attention')->has('servers', 1)->where('servers.0.name', 'busy'));
    $this->actingAs($owner)->get('/servers')->assertInertia(fn (Assert $page): AssertableInertia => $page->where('servers.0.name', 'busy')->where('servers.1.name', 'calm'));
});

it('does nothing while servers are off: no evaluation, no Servers row, and waiting alerts wait until servers are on again', function (): void {
    $owner = systemIssueRecipient('Owner');
    [$server, $token] = alertsServer();
    alertsMinutes($this, $token, 16, ['disks' => ['/' => 92]]);
    expect(alertsDeliveries('opened')->sole()->state)->toBe('pending');

    alertsSwitch(false);
    $evaluation = collect(app(Schedule::class)->events())->first(fn ($event): bool => $event->description === 'servers:evaluate-no-report');
    $this->travel(1)->hours();
    dispatchNotifications();

    expect($evaluation?->filtersPass($this->app))->toBeFalse()
        ->and(alertsDeliveries('opened')->sole())->toMatchObject(['suppressed' => false, 'state' => 'pending', 'last_reason' => 'servers_disabled']);
    Mail::assertNothingSent();
    $this->actingAs($owner)->getJson(route('settings.notifications.data'))->assertOk()
        ->assertJsonMissing(['category' => 'servers']);
    // The ingest answers 404, so no sample and no issue arrives.
    $this->call('POST', '/agent/v1/report', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token], content: '{}')->assertNotFound();
    expect(ServerIssue::query()->count())->toBe(1)->and(alertsOpen($server))->toBe(['disk_full:/']);

    // Switched on again with the issue still open, the alert goes out.
    alertsSwitch(true);
    $this->travel(1)->hours();
    dispatchNotifications();
    expect(alertsDeliveries('opened')->sole()->state)->toBe('sent');
    Mail::assertSent(TemplateMail::class, 1);
});

it('offers the Servers row to Owners, Admins and team channels, locked off for a channel limited to one client', function (): void {
    $owner = systemIssueRecipient('Owner');
    $member = systemIssueRecipient('Member');
    $limited = alertsTeamWebhook(Client::factory()->create()->id, NotificationMode::Off);
    $team = alertsTeamWebhook(mode: NotificationMode::Off);

    $personal = $this->actingAs($owner)->getJson(route('settings.notifications.data'))->assertOk();
    expect(collect($personal->json('grid'))->firstWhere('category', 'servers'))->toBe(['category' => 'servers', 'modes' => ['immediate', 'digest', 'off']])
        ->and($personal->json('channels.0.preferences.servers'))->toBe('immediate');
    expect(collect($this->actingAs($member)->getJson(route('settings.notifications.data'))->json('grid'))->pluck('category'))->not->toContain('servers');
    expect(collect($this->actingAs($owner)->getJson(route('settings.notifications.team.data'))->json('grid'))->pluck('category'))->toContain('servers');

    $this->actingAs($owner)->putJson(route('notification-channels.preferences', $team), ['preferences' => ['servers' => 'digest']])->assertSuccessful();
    $this->actingAs($owner)->putJson(route('notification-channels.preferences', $limited), ['preferences' => ['servers' => 'immediate']])
        ->assertUnprocessable()->assertJsonValidationErrors(['preferences.servers']);
    $this->actingAs($member)->putJson(route('notification-channels.preferences', systemIssueAccountChannel($member)), ['preferences' => ['servers' => 'immediate']])
        ->assertUnprocessable()->assertJsonValidationErrors(['preferences.servers']);
    expect(DB::table('notification_preferences')->where('category', 'servers')->whereIn('destination_id', [$team->id, $limited->id])->orderBy('destination_id')->pluck('mode', 'destination_id')->all())
        ->toBe([$limited->id => 'off', $team->id => 'digest']);
});

it('keeps Activity and the notification settings within a fixed number of queries as server issues and channels grow', function (): void {
    $owner = User::factory()->owner()->create();
    // Warm the request-independent caches (activity time, roles, settings) the same way, then measure a fresh request.
    $measure = function (string $url) use ($owner): int {
        $this->actingAs($owner->fresh())->getJson($url)->assertOk();
        $this->actingAs($owner->fresh());
        $this->app->forgetScopedInstances();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson($url)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };
    $grow = function (int $servers): void {
        for ($i = 0; $i < $servers; $i++) {
            [, $token] = alertsServer('web-'.Str::random(6));
            alertsMinutes($this, $token, 11, ['memory' => 95]);
            alertsTeamWebhook();
        }
    };
    $grow(1);
    $few = [$measure('/activity'), $measure(route('settings.notifications.team.data'))];
    $grow(4);
    $many = [$measure('/activity'), $measure(route('settings.notifications.team.data'))];

    expect(DB::table('events')->whereNotNull('server_issue_id')->count())->toBe(5)->and($many)->toBe($few);
});

/** Runs $change once, inside the claim that moves a delivery to this channel to Sending: after the claim's check, before the send. */
function alertsAfterClaim(int $channelId, Closure $change): void
{
    $done = false;
    Delivery::updated(static function (Delivery $delivery) use (&$done, $channelId, $change): void {
        if (! $done && $delivery->destination_id === $channelId && $delivery->state === DeliveryState::Sending) {
            $done = true;
            $change();
        }
    });
}

/** The channel limited to another client and pointed at another address, as an edit in the settings would leave it. */
function alertsLimitAndMove(NotificationDestination $channel): void
{
    $channel->refresh()->forceFill(['scope_client_id' => Client::factory()->create()->id,
        'config' => app(ChannelRegistry::class)->get('webhook')->configSchema()->validateConfig(['url' => 'https://receiver.test/moved', 'secret' => 'private-webhook-secret'])])->save();
}

it('re-checks eligibility at send time: a server alert is not sent once its channel is limited to one client after the claim', function (): void {
    $team = alertsTeamWebhook();
    [, $token] = alertsServer();
    alertsAfterClaim($team->id, static fn () => alertsLimitAndMove($team));

    alertsMinutes($this, $token, 16, ['disks' => ['/' => 92]]);
    dispatchNotifications();

    expect(alertsDeliveries('opened')[$team->id])->toMatchObject(['suppressed' => true, 'suppression_reason' => 'servers_client_limited'])
        ->and(Http::recorded())->toHaveCount(0);
});

it('re-checks eligibility at send time: a site alert is not sent once its team channel is limited to another client after the claim', function (): void {
    $kept = notificationWebhook();
    $moved = notificationWebhook();
    $event = Event::factory()->create(['check_id' => Check::factory()->create(['type' => CheckKey::Performance])->id,
        'from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Warning]);
    $deliveries = [];
    foreach ([$kept, $moved] as $channel) {
        $deliveries[$channel->id] = Delivery::query()->create(['event_id' => $event->id, 'destination_id' => $channel->id, 'category' => 'warning',
            'next_attempt_at' => now(), 'suppression_evaluated_at' => now()]);
    }
    alertsAfterClaim($moved->id, static fn () => alertsLimitAndMove($moved));

    app(SendNotificationDelivery::class)($deliveries[$kept->id]->id);
    app(SendNotificationDelivery::class)($deliveries[$moved->id]->id);

    expect($deliveries[$kept->id]->refresh()->state)->toBe(DeliveryState::Sent)
        ->and($deliveries[$moved->id]->refresh()->only(['suppressed', 'suppression_reason']))->toBe(['suppressed' => true, 'suppression_reason' => 'channel_client_scope'])
        ->and(Http::recorded()->map(static fn (array $pair): string => $pair[0]->url())->all())->toBe(['https://receiver.test/alerts']);
});

it('re-checks eligibility at send time: an Admin demoted after the claim gets no server alert', function (): void {
    $admin = systemIssueRecipient('Admin');
    $account = systemIssueAccountChannel($admin);
    [, $token] = alertsServer();
    alertsAfterClaim($account->id, static fn (): User => $admin->syncRoles(['Member']));

    alertsMinutes($this, $token, 11, ['memory' => 95]);
    dispatchNotifications();

    expect(alertsDeliveries('opened')[$account->id])->toMatchObject(['suppressed' => true, 'suppression_reason' => 'servers_owner_or_admin_only']);
    Mail::assertNothingSent();
});

it('evaluates only the newest sample: a late healthy sample never resolves a newer issue', function (): void {
    [$server, $token] = alertsServer();
    alertsMinutes($this, $token, 11, ['memory' => 95]);
    expect(alertsOpen($server))->toBe(['memory_low:']);

    // Observed three minutes ago, received now: stored, never evaluated.
    alertsReport($this, $token, ['memory' => 50], now()->subMinutes(3));
    expect(alertsOpen($server))->toBe(['memory_low:'])
        ->and(DB::table('server_samples')->where('server_id', $server->id)->count())->toBe(12);

    $this->travel(30)->seconds();
    alertsReport($this, $token, ['memory' => 50]);
    expect(alertsOpen($server))->toBe([]);
});

it('resolves "No report" at the receipt of the next sample, never before it opened, also for a sample observed earlier', function (): void {
    [$server, $token] = alertsServer(settings: ['no_report_minutes' => 2]);
    alertsReport($this, $token);
    $reported = now();
    $this->travelTo($reported->addMinutes(9));
    app(EvaluateServerIssues::class)->noReports(now());
    $issue = ServerIssue::query()->where('server_id', $server->id)->sole();
    expect($issue->opened_at->equalTo($reported->addMinutes(2)))->toBeTrue();

    // Observed one minute after the last report (before the issue opened), received now.
    alertsReport($this, $token, [], $reported->addMinute());

    expect($issue->refresh()->resolved_at?->equalTo(now()))->toBeTrue()
        ->and($issue->resolved_at->greaterThanOrEqualTo($issue->opened_at))->toBeTrue();
});

it('silences a recovery that is still waiting when the server gets snoozed', function (): void {
    $owner = systemIssueRecipient('Owner');
    [$server, $token] = alertsServer();
    alertsMinutes($this, $token, 11, ['memory' => 95]);
    dispatchNotifications();
    alertsMinutes($this, $token, 1);
    expect(alertsDeliveries('resolved')->sole()->state)->toBe('pending');

    $this->actingAs($owner)->post("/servers/{$server->id}/snooze", ['minutes' => 60])->assertRedirect();
    dispatchNotifications();

    expect(alertsDeliveries('resolved')->sole())->toMatchObject(['suppressed' => true, 'suppression_reason' => 'snooze']);
    Mail::assertSent(TemplateMail::class, 1);
});

it('keeps a snoozed server out of the digest and holds its alerts when the channel switches from Digest to Immediately', function (): void {
    $zone = app(InstanceSettings::class)->timezone;
    $owner = systemIssueRecipient('Owner');
    $digest = alertsTeamWebhook(mode: NotificationMode::Digest);
    [$server, $token] = alertsServer();
    $this->actingAs($owner)->post("/servers/{$server->id}/snooze", ['minutes' => 3 * 1440])->assertRedirect();
    alertsMinutes($this, $token, 16, ['load' => 9.5]);

    $this->travelTo(CarbonImmutable::parse('2026-11-28T08:00:00', $zone));
    dispatchNotifications();
    expect(DB::table('events')->where('type', 'notification.digest')->count())->toBe(0)
        ->and(alertsWebhookBodies())->toBe([]);

    $this->actingAs($owner)->putJson(route('notification-channels.preferences', $digest), ['preferences' => ['servers' => 'immediate']])->assertSuccessful();
    dispatchNotifications();
    expect(alertsDeliveries('opened')[$digest->id])->toMatchObject(['state' => 'pending', 'digest' => false, 'last_reason' => 'deferred_by_snooze'])
        ->and(alertsWebhookBodies())->toBe([]);
    Mail::assertNothingSent();

    $this->actingAs($owner)->delete("/servers/{$server->id}/snooze")->assertRedirect();
    dispatchNotifications();
    expect(alertsDeliveries('opened')->pluck('state')->unique()->all())->toBe(['sent']);
});

it('skips a snoozed server in the recurring digest and ends the recurrence once its issue resolves', function (): void {
    $zone = app(InstanceSettings::class)->timezone;
    $owner = systemIssueRecipient('Owner');
    $digest = alertsTeamWebhook(mode: NotificationMode::Digest);
    [$server, $token] = alertsServer();
    alertsMinutes($this, $token, 16, ['load' => 9.5]);
    $digestCount = static fn (): int => DB::table('events')->where('type', 'notification.digest')->count();

    $this->travelTo(CarbonImmutable::parse('2026-11-28T08:00:00', $zone));
    dispatchNotifications();
    expect($digestCount())->toBe(1);

    $this->actingAs($owner)->post("/servers/{$server->id}/snooze", ['minutes' => 2 * 1440])->assertRedirect();
    $this->travelTo(CarbonImmutable::parse('2026-11-29T08:00:00', $zone));
    dispatchNotifications();
    expect($digestCount())->toBe(1);

    $this->actingAs($owner)->delete("/servers/{$server->id}/snooze")->assertRedirect();
    alertsReport($this, $token);
    expect(alertsOpen($server))->toBe([])
        ->and(alertsDeliveries('opened')[$digest->id]->digest_recurrence_ended_at)->not->toBeNull();
    $this->travelTo(CarbonImmutable::parse('2026-11-30T08:00:00', $zone));
    dispatchNotifications();
    expect($digestCount())->toBe(1);
});

it('removes every server alert and the Servers category on the way down, and restores them on the way up', function (): void {
    systemIssueRecipient('Owner');
    [, $token] = alertsServer();
    alertsMinutes($this, $token, 11, ['memory' => 95]);
    $migration = require __DIR__.'/../../database/migrations/2026_11_28_100000_create_server_issues.php';
    $backfill = require __DIR__.'/../../database/migrations/2026_11_28_100002_backfill_servers_notification_cells.php';
    expect(DB::table('events')->whereNotNull('server_issue_id')->count())->toBe(1);

    $backfill->down();
    $migration->down();

    expect(Schema::hasColumn('events', 'server_issue_id'))->toBeFalse()->and(Schema::hasTable('server_issues'))->toBeFalse()
        ->and(DB::table('notification_preferences')->where('category', 'servers')->count())->toBe(0)
        ->and(DB::table('deliveries')->where('category', 'servers')->count())->toBe(0)
        ->and(DB::table('notification_destinations')->whereRaw("jsonb_exists(default_preferences, 'servers')")->count())->toBe(0);
    $channel = NotificationDestination::query()->value('id');
    expect(fn () => DB::transaction(static fn () => DB::table('notification_preferences')->where('destination_id', $channel)->where('category', 'system')
        ->update(['category' => 'servers'])))->toThrow(QueryException::class);

    $migration->up();
    $backfill->up();

    expect(Schema::hasColumn('events', 'server_issue_id'))->toBeTrue()
        ->and(DB::table('notification_preferences')->where('category', 'servers')->where('destination_id', $channel)->value('mode'))->toBe('immediate');
});

/** A queued warning about one site to this channel, as the planner leaves it. */
function alertsSiteWarning(NotificationDestination $channel): Delivery
{
    $event = Event::factory()->create(['check_id' => Check::factory()->create(['type' => CheckKey::Performance])->id,
        'from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Warning]);

    return Delivery::query()->create(['event_id' => $event->id, 'destination_id' => $channel->id, 'category' => 'warning',
        'next_attempt_at' => now(), 'suppression_evaluated_at' => now()]);
}

/** Two open server issues as daily digest lines of a team webhook, and the digest pass of the next morning. */
function alertsDigestOfTwo(TestCase $test): NotificationDestination
{
    $digest = alertsTeamWebhook(mode: NotificationMode::Digest);
    [, $token] = alertsServer();
    alertsMinutes($test, $token, 16, ['disks' => ['/' => 95], 'load' => 9.5]);
    $test->travelTo(CarbonImmutable::parse('2026-11-28T08:00:00', app(InstanceSettings::class)->timezone));

    return $digest;
}

it('holds an alert at the send when the account address became unverified after the claim', function (): void {
    $owner = systemIssueRecipient('Owner');
    $delivery = alertsSiteWarning(systemIssueAccountChannel($owner));
    alertsAfterClaim($delivery->destination_id, static fn () => $owner->forceFill(['email' => 'moved@example.test', 'email_verified_at' => null])->save());

    app(SendNotificationDelivery::class)($delivery->id);

    expect($delivery->refresh()->only(['state', 'last_reason', 'attempts', 'suppressed']))
        ->toBe(['state' => DeliveryState::Pending, 'last_reason' => 'personal_email_unverified', 'attempts' => 0, 'suppressed' => false]);
    Mail::assertNothingSent();
});

it('suppresses an alert at the send when its site was deleted after the claim', function (): void {
    $team = notificationWebhook();
    $delivery = alertsSiteWarning($team);
    $siteId = $delivery->event()->getQuery()->value('site_id');
    alertsAfterClaim($team->id, static fn () => Site::query()->whereKey($siteId)->firstOrFail()->delete());

    app(SendNotificationDelivery::class)($delivery->id);

    expect($delivery->refresh()->only(['suppressed', 'suppression_reason']))->toBe(['suppressed' => true, 'suppression_reason' => 'site_deleted'])
        ->and(Http::recorded())->toHaveCount(0);
});

it('suppresses a claimed alert at the send when its channel was paused after the claim', function (): void {
    $team = notificationWebhook();
    $delivery = alertsSiteWarning($team);
    alertsAfterClaim($team->id, static fn () => app(SetNotificationDestinationEnabled::class)($team, false));

    app(SendNotificationDelivery::class)($delivery->id);

    expect($delivery->refresh()->only(['suppressed', 'suppression_reason']))->toBe(['suppressed' => true, 'suppression_reason' => 'destination_disabled'])
        ->and(Http::recorded())->toHaveCount(0);
});

it('builds a leased digest again when one of its items was suppressed after the claim', function (): void {
    $digest = alertsDigestOfTwo($this);
    $sources = DB::table('deliveries')->join('events', 'events.id', '=', 'deliveries.event_id')->where('events.type', 'server.issue_opened')
        ->where('deliveries.destination_id', $digest->id)->orderBy('deliveries.id')->get(['deliveries.id']);
    alertsAfterClaim($digest->id, static fn () => Delivery::query()->whereKey($sources->first()->id)
        ->update(['suppressed' => true, 'suppression_reason' => 'resolved_before_delivery']));

    dispatchNotifications();
    $digestDelivery = DB::table('deliveries')->join('events', 'events.id', '=', 'deliveries.event_id')->where('events.type', 'notification.digest')
        ->sole(['deliveries.id', 'deliveries.state', 'deliveries.attempts', 'events.payload']);
    expect([$digestDelivery->state, $digestDelivery->attempts])->toBe(['pending', 0])
        ->and(alertsWebhookBodies())->toBe([]);

    dispatchNotifications();

    $sent = DB::table('deliveries')->join('events', 'events.id', '=', 'deliveries.event_id')->where('deliveries.id', $digestDelivery->id)->sole(['deliveries.state', 'events.payload']);
    expect($sent->state)->toBe('sent')->and(json_decode($sent->payload, true)['delivery_ids'])->toBe([$sources->last()->id])
        ->and(alertsWebhookBodies())->toHaveCount(1);
});

it('sends no stale digest when its channel was paused and resumed after the claim', function (): void {
    $digest = alertsDigestOfTwo($this);
    alertsAfterClaim($digest->id, static function () use ($digest): void {
        app(SetNotificationDestinationEnabled::class)($digest, false);
        app(SetNotificationDestinationEnabled::class)($digest, true);
    });

    dispatchNotifications();
    dispatchNotifications();

    expect(DB::table('deliveries')->join('events', 'events.id', '=', 'deliveries.event_id')->where('events.type', 'notification.digest')
        ->sole(['deliveries.suppressed', 'deliveries.state']))->toMatchObject(['suppressed' => true, 'state' => 'pending'])
        ->and(alertsWebhookBodies())->toBe([]);
});

it('ends an opening for good when its issue resolves between claim and send, and its recovery with it', function (): void {
    $owner = systemIssueRecipient('Owner');
    [, $token] = alertsServer();
    alertsAfterClaim(systemIssueAccountChannel($owner)->id, fn (): TestResponse => alertsReport($this, $token));

    alertsMinutes($this, $token, 11, ['memory' => 95]);
    dispatchNotifications();
    $this->travel(5)->minutes();
    dispatchNotifications();
    dispatchNotifications();

    expect(alertsDeliveries('opened')->sole())->toMatchObject(['state' => 'pending', 'suppressed' => true, 'suppression_reason' => 'resolved_before_delivery',
        'claim_token' => null, 'lease_until' => null])
        ->and(alertsDeliveries('resolved')->sole())->toMatchObject(['suppressed' => true, 'suppression_reason' => 'opening_not_delivered', 'attempts' => 0]);
    Mail::assertNothingSent();
});

it('keeps a recurring digest line when servers are off for one digest pass', function (): void {
    $zone = app(InstanceSettings::class)->timezone;
    $digest = alertsDigestOfTwo($this);
    $digests = static fn (): int => DB::table('events')->where('type', 'notification.digest')->count();
    dispatchNotifications();
    expect($digests())->toBe(1);

    alertsSwitch(false);
    $this->travelTo(CarbonImmutable::parse('2026-11-29T08:00:00', $zone));
    dispatchNotifications();
    expect($digests())->toBe(1)
        ->and(DB::table('deliveries')->where('destination_id', $digest->id)->where('digest', true)->whereNotNull('digest_recurrence_ended_at')->count())->toBe(0);

    alertsSwitch(true);
    $this->travelTo(CarbonImmutable::parse('2026-11-30T08:00:00', $zone));
    dispatchNotifications();
    expect($digests())->toBe(2)->and(alertsWebhookBodies())->toHaveCount(2);
});

it('ends an opening reclaimed after its worker died once its issue resolved, and its recovery with it', function (): void {
    $owner = systemIssueRecipient('Owner');
    [, $token] = alertsServer();
    alertsMinutes($this, $token, 11, ['memory' => 95]);
    $opening = alertsDeliveries('opened')->sole();
    // The worker claims the opening and dies before sending: the row stays Sending under its lease.
    expect(app(ClaimNotificationDelivery::class)($opening->id)?->state)->toBe(DeliveryState::Sending);
    alertsReport($this, $token);
    expect(alertsDeliveries('resolved')->sole()->state)->toBe('pending');

    $this->travel(SendNotification::LeaseSeconds + 1)->seconds();
    dispatchNotifications();
    $this->travel(5)->minutes();
    dispatchNotifications();

    expect(alertsDeliveries('opened')->sole())->toMatchObject(['state' => 'pending', 'suppressed' => true, 'suppression_reason' => 'resolved_before_delivery',
        'claim_token' => null, 'lease_until' => null])
        ->and(alertsDeliveries('resolved')->sole())->toMatchObject(['suppressed' => true, 'suppression_reason' => 'opening_not_delivered']);
    Mail::assertNothingSent();
});

it('keeps a waiting digest line while servers are off and sends it in the first digest after they are on again', function (): void {
    $zone = app(InstanceSettings::class)->timezone;
    $digest = alertsDigestOfTwo($this);
    $lines = static fn () => DB::table('deliveries')->where('destination_id', $digest->id)->where('digest', true)->orderBy('id')->get();
    expect($lines()->pluck('state')->unique()->all())->toBe(['pending']);

    alertsSwitch(false);
    dispatchNotifications();
    expect(DB::table('events')->where('type', 'notification.digest')->count())->toBe(0)
        ->and($lines()->pluck('suppressed')->unique()->all())->toBe([false]);

    alertsSwitch(true);
    $this->travelTo(CarbonImmutable::parse('2026-11-29T08:00:00', $zone));
    dispatchNotifications();
    expect($lines()->pluck('state')->unique()->all())->toBe(['sent'])
        ->and(alertsWebhookBodies())->toHaveCount(1);
});

it('lists sites before servers in a digest summary, also after a rebuild, within its 20 names', function (): void {
    $zone = app(InstanceSettings::class)->timezone;
    // The server line is the oldest item; 21 site warnings follow it.
    $digest = alertsDigestOfTwo($this);
    $this->travelTo(CarbonImmutable::parse('2026-11-27T08:00:00', $zone));
    DB::table('notification_preferences')->where('destination_id', $digest->id)->where('category', 'warning')->update(['mode' => 'digest']);
    $sites = [];
    for ($i = 1; $i <= 21; $i++) {
        $check = Check::factory()->create(['type' => CheckKey::Performance, 'latest_run_result' => CheckResult::Warning]);
        $check->site?->update(['friendly_name' => sprintf('Site %02d', $i)]);
        $event = Event::factory()->create(['check_id' => $check->id, 'from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Warning]);
        $sites[] = Delivery::query()->create(['event_id' => $event->id, 'destination_id' => $digest->id, 'category' => 'warning', 'digest' => true,
            'next_attempt_at' => now(), 'suppression_evaluated_at' => now()]);
    }
    $this->travelTo(CarbonImmutable::parse('2026-11-28T08:00:00', $zone));
    // One site line drops out after the digest's claim, so the send hands it back and the claim builds it again.
    alertsAfterClaim($digest->id, static fn () => $sites[0]->update(['suppressed' => true, 'suppression_reason' => 'site_unfollowed']));
    app(EmitNotificationDigests::class)();
    $assembled = DB::table('events')->where('type', 'notification.digest')->sole(['id', 'payload']);
    expect(json_decode($assembled->payload, true)['sites'])->not->toContain('web-1');

    dispatchNotifications();
    dispatchNotifications();

    $payload = json_decode(DB::table('events')->where('id', $assembled->id)->value('payload'), true);
    expect(explode(', ', $payload['sites']))->toHaveCount(20)
        ->and($payload['sites'])->toStartWith('Site 02')->not->toContain('web-1')
        ->and($payload['remaining_sites'])->toBe(1)
        ->and(alertsWebhookBodies())->toHaveCount(1);
});

/** A site warning that waits for this channel's daily digest (its Warning cell on Digest). */
function alertsSiteDigestLine(NotificationDestination $channel, string $name): Delivery
{
    DB::table('notification_preferences')->where('destination_id', $channel->id)->where('category', 'warning')->update(['mode' => 'digest']);
    $check = Check::factory()->create(['type' => CheckKey::Performance, 'latest_run_result' => CheckResult::Warning]);
    $check->site?->update(['friendly_name' => $name]);
    $event = Event::factory()->create(['check_id' => $check->id, 'from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Warning]);

    return Delivery::query()->create(['event_id' => $event->id, 'destination_id' => $channel->id, 'category' => 'warning', 'digest' => true,
        'next_attempt_at' => now()->subMinute(), 'suppression_evaluated_at' => now()]);
}

it('keeps the hold of server digest lines while servers are off, waiting and recurring, also when they are switched on in the same minute', function (): void {
    $zone = app(InstanceSettings::class)->timezone;
    $digest = alertsDigestOfTwo($this);
    alertsSiteDigestLine($digest, 'Shop');
    $serverLines = static fn () => DB::table('deliveries')->join('events', 'events.id', '=', 'deliveries.event_id')->where('events.type', 'server.issue_opened')
        ->where('deliveries.destination_id', $digest->id)->orderBy('deliveries.id')->get(['deliveries.state', 'deliveries.next_attempt_at', 'deliveries.last_reason']);
    $digests = static fn (): int => DB::table('events')->where('type', 'notification.digest')->count();

    // Waiting lines: servers off for the digest pass, on again within the same minute.
    alertsSwitch(false);
    dispatchNotifications();
    $held = $serverLines();
    expect($digests())->toBe(1)
        ->and($held->map(static fn (object $line): array => [$line->state, CarbonImmutable::parse($line->next_attempt_at)->equalTo(now()->addHour()), $line->last_reason])->all())
        ->toBe([['pending', true, 'servers_disabled'], ['pending', true, 'servers_disabled']]);
    alertsSwitch(true);
    dispatchNotifications();
    $this->travel(1)->minutes();
    dispatchNotifications();
    expect($digests())->toBe(1);
    $this->travel(59)->minutes();
    dispatchNotifications();
    expect($digests())->toBe(2)->and($serverLines()->pluck('state')->unique()->all())->toBe(['sent']);

    // Recurring lines the next morning: the same hold, then the next digest after it.
    $this->travelTo(CarbonImmutable::parse('2026-11-29T08:00:00', $zone));
    alertsSwitch(false);
    dispatchNotifications();
    expect($digests())->toBe(3)
        ->and($serverLines()->every(static fn (object $line): bool => CarbonImmutable::parse($line->next_attempt_at)->equalTo(now()->addHour())))->toBeTrue();
    alertsSwitch(true);
    dispatchNotifications();
    $this->travel(1)->minutes();
    dispatchNotifications();
    expect($digests())->toBe(3);
    $this->travel(59)->minutes();
    dispatchNotifications();
    expect($digests())->toBe(4);
});

it('lets a channel that already has this minute\'s digest wait for the next pass without stopping the channels after it', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-28T08:00:00', app(InstanceSettings::class)->timezone));
    $first = notificationWebhook();
    $second = notificationWebhook();
    alertsSiteDigestLine($first, 'Shop');
    app(EmitNotificationDigests::class)();
    $late = alertsSiteDigestLine($first, 'Blog');
    alertsSiteDigestLine($second, 'Docs');

    app(EmitNotificationDigests::class)();

    $digestsOf = static fn (NotificationDestination $channel): int => DB::table('deliveries')->join('events', 'events.id', '=', 'deliveries.event_id')
        ->where('events.type', 'notification.digest')->where('deliveries.destination_id', $channel->id)->count();
    expect([$digestsOf($first), $digestsOf($second)])->toBe([1, 1])
        ->and($late->refresh()->digest_event_id)->toBeNull();

    $this->travel(1)->minutes();
    app(EmitNotificationDigests::class)();
    expect($digestsOf($first))->toBe(2)->and($late->refresh()->digest_event_id)->not->toBeNull();
});

it('puts a recurring server digest line back in the next digest when its snooze ends early', function (): void {
    $zone = app(InstanceSettings::class)->timezone;
    $owner = systemIssueRecipient('Owner');
    $digest = alertsTeamWebhook(mode: NotificationMode::Digest);
    [$server, $token] = alertsServer();
    alertsMinutes($this, $token, 16, ['load' => 9.5]);
    $digestCount = static fn (): int => DB::table('events')->where('type', 'notification.digest')->count();
    $this->travelTo(CarbonImmutable::parse('2026-11-28T08:00:00', $zone));
    dispatchNotifications();
    expect($digestCount())->toBe(1);

    // Snoozed for three days: the next morning's digest holds the recurring line until the snooze's end.
    $this->actingAs($owner)->post("/servers/{$server->id}/snooze", ['minutes' => 3 * 1440])->assertRedirect();
    $this->travelTo(CarbonImmutable::parse('2026-11-29T08:00:00', $zone));
    dispatchNotifications();
    $line = DB::table('deliveries')->join('events', 'events.id', '=', 'deliveries.event_id')->where('events.type', 'server.issue_opened')
        ->where('deliveries.destination_id', $digest->id)->sole(['deliveries.id', 'deliveries.state', 'deliveries.next_attempt_at']);
    expect($digestCount())->toBe(1)->and($line->state)->toBe('sent')
        ->and(CarbonImmutable::parse($line->next_attempt_at)->greaterThan(now()->addDay()))->toBeTrue();

    $this->travel(2)->hours();
    $this->actingAs($owner)->delete("/servers/{$server->id}/snooze")->assertRedirect();
    $this->travel(1)->minutes();
    dispatchNotifications();

    expect($digestCount())->toBe(2)->and(alertsWebhookBodies())->toHaveCount(2);
});
