<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Addons\Servers\Server;
use Heptapod\Addons\Servers\ServersSettings;
use Heptapod\Models\User;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Pest.php';

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-27T07:00:00Z'));
    config(['heptapod.servers.enabled_by_server' => true]);
    serversSwitch(true);
});

function serversSwitch(bool $on): void
{
    app(ServersSettings::class)->turn($on);
}

function serversUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/** A stored sample as ingest writes it (ingest only takes the last 10 minutes, so history is staged here). */
function storeServerSample(Server $server, CarbonImmutable $observedAt, float $cpu = 20.0, int $rootFreeBytes = 50, int $availableBytes = 50): void
{
    DB::table('server_samples')->insert(['server_id' => $server->id, 'sample_id' => (string) Str::uuid(),
        'observed_at' => $observedAt->format('Y-m-d H:i:s.uP'), 'received_at' => $observedAt->format('Y-m-d H:i:s.uP'),
        'metrics' => json_encode(['cpu' => ['cores' => 2, 'used_percent' => $cpu], 'load' => ['1' => 0.5, '5' => 1.0, '15' => 1.5],
            'memory' => ['total_bytes' => 100, 'available_bytes' => $availableBytes], 'filesystems' => [['mount' => '/', 'total_bytes' => 100, 'free_bytes' => $rootFreeBytes]],
            'host' => ['uptime_seconds' => 3600, 'reboot_required' => null], 'updates' => ['pending' => null, 'security' => null]], JSON_THROW_ON_ERROR)]);
}

it('shows servers to Owners and Admins only', function (string $role, bool $allowed): void {
    $this->actingAs(serversUser($role));

    $this->get('/servers')->assertStatus($allowed ? 200 : 403);
    $this->post('/servers', ['name' => 'web-1'])->assertStatus($allowed ? 302 : 403);
    $this->get('/settings/profile')->assertInertia(fn (Assert $page): Assert => $page->where('auth.canViewServers', $allowed));
})->with([['Owner', true], ['Admin', true], ['Member', false]]);

it('hides navigation and answers 404 while switched off, by the environment or by the Owner', function (string $switch): void {
    $server = Server::named('web-1');
    $server->issueToken();
    $this->get('/servers')->assertRedirect('/login');
    $switch === 'environment' ? config(['heptapod.servers.enabled_by_server' => false]) : serversSwitch(false);

    $this->get('/servers')->assertNotFound();
    $this->actingAs(serversUser('Owner'));
    $this->get('/servers')->assertNotFound();
    $this->get("/servers/{$server->id}")->assertNotFound();
    $this->post("/servers/{$server->id}/token")->assertNotFound();
    $this->delete("/servers/{$server->id}")->assertNotFound();
    $this->get('/settings/profile')->assertInertia(fn (Assert $page): Assert => $page->where('auth.canViewServers', false));
    expect(Server::query()->count())->toBe(1);
})->with(['environment', 'Owner toggle']);

it('lets the Owner switch servers in Settings → General only where the environment allows it, keeping the data', function (): void {
    serversSwitch(false);
    $owner = serversUser('Owner');
    $this->actingAs($owner)->get('/settings/instance')->assertInertia(fn (Assert $page): Assert => $page
        ->where('instancePage.servers_enabled', false)->where('instancePage.servers_enabled_by_server', true));

    $this->patch('/settings/instance', ['name' => 'Heptapod', 'servers_enabled' => true])->assertRedirect('/settings/instance');
    $this->post('/servers', ['name' => 'web-1'])->assertRedirect();
    $this->patch('/settings/instance', ['name' => 'Heptapod', 'servers_enabled' => false])->assertRedirect('/settings/instance');
    $this->get('/servers')->assertNotFound();
    $this->patch('/settings/instance', ['name' => 'Heptapod', 'servers_enabled' => true]);
    $this->get('/servers')->assertInertia(fn (Assert $page): Assert => $page->component('servers/index')->where('servers.0.name', 'web-1'));

    config(['heptapod.servers.enabled_by_server' => false]);
    $this->get('/settings/instance')->assertInertia(fn (Assert $page): Assert => $page->where('instancePage.servers_enabled_by_server', false));
    $this->patchJson('/settings/instance', ['name' => 'Heptapod', 'servers_enabled' => false])->assertUnprocessable()
        ->assertJsonPath('code', 'servers_disabled_by_server');
    expect(app(ServersSettings::class)->enabled)->toBeTrue();
    $this->patch('/settings/instance', ['name' => 'Renamed'])->assertRedirect('/settings/instance');
    expect(app(InstanceSettings::class)->name)->toBe('Renamed');
});

it('opens a new server awaiting its first report with the install command parts and a token shown once', function (): void {
    config(['heptapod.version' => 'v0.19.0', 'app.url' => 'https://monitor.example.com']);
    $this->actingAs(serversUser('Admin'));

    $response = $this->post('/servers', ['name' => 'solitary-darkness']);
    $server = Server::query()->sole();
    $response->assertRedirect("/servers/{$server->id}");
    $token = session(SessionKey::FLASH_DATA)['server_token'];
    expect($token)->toMatch('/^hpa_'.$server->public_id.'_[A-Za-z0-9_-]{43}$/')
        ->and($server->token_hash)->toBe(hash('sha256', substr($token, strlen('hpa_'.$server->public_id.'_'))));

    $this->get("/servers/{$server->id}")->assertInertia(fn (Assert $page): Assert => $page->component('servers/show')
        ->where('server.state', 'awaiting')->where('server.values', null)->where('history.points', [])
        ->where('install', ['endpoint' => 'https://monitor.example.com/agent/v1/report', 'installed_version' => 'v0.19.0', 'version' => 'v0.19.0',
            'release_url' => 'https://github.com/heptapodwatch/heptapod/releases/download/v0.19.0']));
    // The token is never part of a page; only the one redirect after creating or rotating carries it.
    expect(session(SessionKey::FLASH_DATA))->toBeNull();
});

it('lists every server with its state from the newest sample, "No report" after two missed intervals', function (): void {
    $this->actingAs(serversUser('Owner'));
    foreach (['awaiting', 'quiet', 'reporting'] as $name) {
        $this->post('/servers', ['name' => $name]);
    }
    $quiet = Server::query()->where('name', 'quiet')->sole();
    $reporting = Server::query()->where('name', 'reporting')->sole();
    $quiet->forceFill(['last_report_at' => now()->subSeconds(121)])->save();
    storeServerSample($quiet, now()->subSeconds(121), rootFreeBytes: 8);
    $reporting->forceFill(['last_report_at' => now()->subSeconds(30)])->save();
    storeServerSample($reporting, now()->subMinutes(2), cpu: 10.0);
    storeServerSample($reporting, now()->subSeconds(30), cpu: 55.5);

    // Problems first by default: No report, then Awaiting, then Reporting.
    $this->get('/servers')->assertInertia(fn (Assert $page): Assert => $page->component('servers/index')
        ->where('servers.0.name', 'quiet')->where('servers.0.state', 'no_report')
        ->where('servers.0.values.filesystems.0.free_bytes', 8)
        ->where('servers.1.name', 'awaiting')->where('servers.1.state', 'awaiting')->where('servers.1.values', null)
        ->where('servers.2.state', 'reporting')->where('servers.2.values.cpu_used_percent', 55.5)
        ->where('defaults', ['disk_percent' => 90, 'disk_minutes' => 15, 'mounts' => [], 'memory_percent' => 90, 'memory_minutes' => 10,
            'load_per_core' => 2, 'load_minutes' => 15, 'no_report_minutes' => 2])
        // The 5-minute load, the one its alert threshold reads.
        ->where('servers.2.values.load_5', 1)
        ->where('servers.2.thresholds.no_report_minutes', 2));
});

it('charts the peaks of the last 14 days only', function (): void {
    $this->actingAs(serversUser('Owner'));
    $this->post('/servers', ['name' => 'web-1']);
    $server = Server::query()->sole();
    storeServerSample($server, now()->subDays(14)->subMinute(), cpu: 99.0);
    storeServerSample($server, now()->subHours(3)->startOfHour()->addMinutes(5), cpu: 40.0, rootFreeBytes: 20, availableBytes: 70);
    storeServerSample($server, now()->subHours(3)->startOfHour()->addMinutes(35), cpu: 60.0, rootFreeBytes: 30, availableBytes: 40);
    storeServerSample($server, now()->subMinutes(5), cpu: 10.0);

    $history = $this->get("/servers/{$server->id}")->inertiaProps('history');

    expect($history['step_seconds'])->toBe(60)
        ->and(array_column($history['points'], 'cpu'))->toEqual([40, 60, 10]);

    storeServerSample($server, now()->subDays(13), cpu: 30.0);
    $history = $this->get("/servers/{$server->id}")->inertiaProps('history');
    expect($history['step_seconds'])->toBe(3600)
        // One point per hour with its peak: the hour 3 h ago keeps 60 % CPU, 80 % disk and 60 % memory.
        ->and($history['points'][1])->toEqual(['at' => now()->subHours(3)->startOfHour()->toIso8601String(), 'cpu' => 60, 'load' => 1,
            'memory' => 60, 'disks' => ['/' => 80]])
        ->and(array_column($history['points'], 'cpu'))->toEqual([30, 60, 10]);
});

it('refreshes the values without recomputing the 14-day chart', function (): void {
    $this->actingAs(serversUser('Owner'));
    $this->post('/servers', ['name' => 'web-1']);
    $server = Server::query()->sole();
    storeServerSample($server, now());
    $version = $this->get("/servers/{$server->id}")->inertiaPage()['version'];

    DB::enableQueryLog();
    $this->get("/servers/{$server->id}", ['X-Inertia' => 'true', 'X-Inertia-Version' => $version,
        'X-Inertia-Partial-Component' => 'servers/show', 'X-Inertia-Partial-Data' => 'server'])
        ->assertOk()->assertJsonPath('props.server.values.cpu_used_percent', 20)->assertJsonMissingPath('props.history');

    expect(collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'date_trunc')))->toBeEmpty();
});

it('removes a server with its samples', function (): void {
    $this->actingAs(serversUser('Owner'));
    $this->post('/servers', ['name' => 'web-1']);
    $server = Server::query()->sole();
    storeServerSample($server, now());

    $this->delete("/servers/{$server->id}")->assertRedirect('/servers')->assertInertiaFlash('toast.message', 'web-1 removed.');

    expect(Server::query()->count())->toBe(0)->and(DB::table('server_samples')->count())->toBe(0);
});

it('deletes only samples older than 14 days on its hourly schedule, and nothing while switched off', function (): void {
    $this->actingAs(serversUser('Owner'));
    $this->post('/servers', ['name' => 'web-1']);
    $server = Server::query()->sole();
    storeServerSample($server, now()->subDays(14)->subSecond());
    storeServerSample($server, now()->subDays(14)->addSecond());
    $prune = collect(app(Schedule::class)->events())->sole(fn (Event $event): bool => $event->description === 'servers:prune-samples');
    expect($prune->expression)->toBe('0 * * * *');

    serversSwitch(false);
    expect($prune->filtersPass(app()))->toBeFalse();
    serversSwitch(true);
    expect($prune->filtersPass(app()))->toBeTrue();
    $prune->run(app());

    expect(DB::table('server_samples')->pluck('observed_at')->map(fn (string $at): string => CarbonImmutable::parse($at)->toIso8601String())->all())
        ->toBe([now()->subDays(14)->addSecond()->toIso8601String()]);
});

it('adds no layout query while servers are off and at most one while on', function (string $role): void {
    $user = serversUser($role);
    $count = function () use ($user): int {
        $this->actingAs($user)->get('/settings/profile')->assertOk();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get('/settings/profile')->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };
    config(['heptapod.servers.enabled_by_server' => false]);
    $off = $count();
    config(['heptapod.servers.enabled_by_server' => true]);

    expect($count() - $off)->toBeLessThanOrEqual($role === 'Member' ? 0 : 1);
})->with(['Owner', 'Member']);
