<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Addons\Servers\Server;
use Heptapod\Contracts\CheckKey;
use Heptapod\Models\Check;
use Heptapod\Models\Client;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Pest.php';

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-27T07:00:00Z'));
    config(['heptapod.servers.enabled_by_server' => true]);
    manageSwitch(true);
});

function manageSwitch(bool $on): void
{
    $settings = app(InstanceSettings::class);
    $settings->servers_enabled = $on;
    $settings->save();
}

function manageUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/**
 * A reporting server with one stored sample, as ingest leaves it.
 *
 * @param  list<string>  $ips
 * @param  array<string, array{int, int}>  $filesystems  mount => [total, free]
 */
function reportingServer(string $name, array $ips = [], array $filesystems = ['/' => [100, 50]], ?CarbonImmutable $at = null, float $load = 1.0, int $available = 50): Server
{
    $at ??= now()->toImmutable();
    $server = Server::named($name);
    $server->issueToken();
    $server->forceFill(['ips' => $ips, 'last_report_at' => $at, 'hostname' => $name.'.host'])->save();
    DB::table('server_samples')->insert(['server_id' => $server->id, 'sample_id' => (string) Str::uuid(),
        'observed_at' => $at->format('Y-m-d H:i:s.uP'), 'received_at' => $at->format('Y-m-d H:i:s.uP'),
        'metrics' => json_encode(['cpu' => ['cores' => 2, 'used_percent' => 20], 'load' => ['1' => $load, '5' => $load, '15' => $load],
            'memory' => ['total_bytes' => 100, 'available_bytes' => $available],
            'filesystems' => array_map(static fn (string $mount, array $bytes): array => ['mount' => $mount, 'total_bytes' => $bytes[0], 'free_bytes' => $bytes[1]],
                array_keys($filesystems), $filesystems),
            'host' => ['uptime_seconds' => 3600, 'reboot_required' => false], 'updates' => ['pending' => 0, 'security' => 0]], JSON_THROW_ON_ERROR)]);

    return $server;
}

/**
 * The DNS check's accepted answer for the site: its newest run (checks.latest_run_id) has these A/AAAA records.
 *
 * @param  list<array{string, string}>  $records  [type, address]
 */
function dnsAnswer(Site $site, array $records, CarbonImmutable $endedAt, bool $latest = true): void
{
    static $runId = 1000;
    $runId++;
    $check = Check::query()->where('site_id', $site->id)->where('type', CheckKey::Dns->value)->first()
        ?? Check::factory()->for($site)->create(['type' => CheckKey::Dns]);
    DB::table('dns_observations')->insert(['site_id' => $site->id, 'check_id' => $check->id, 'run_id' => $runId, 'claim_token' => (string) Str::uuid(),
        'run_ended_at' => $endedAt->format('Y-m-d H:i:s.uP'), 'expected' => '[]', 'created_at' => $endedAt->format('Y-m-d H:i:s.uP'),
        'snapshot' => json_encode(['dns_records' => array_map(static fn (array $record): array => ['host' => 'x', 'type' => $record[0], 'class' => 'IN', 'ttl' => 60,
            ...($record[0] === 'A' ? ['ip' => $record[1]] : ['ipv6' => $record[1]])], $records)], JSON_THROW_ON_ERROR)]);
    if ($latest) {
        $check->forceFill(['latest_run_id' => $runId])->save();
    }
}

/** The "Link site…" sheet's props, read as the sheet asks for them. */
function linkProp(object $test, Server $server): array
{
    $version = $test->get("/servers/{$server->id}")->inertiaPage()['version'];

    return $test->get("/servers/{$server->id}", ['X-Inertia' => 'true', 'X-Inertia-Version' => $version,
        'X-Inertia-Partial-Component' => 'servers/show', 'X-Inertia-Partial-Data' => 'link'])->assertOk()->json('props.link');
}

/** @return array<string, int|float|string|list<mixed>> */
function settingsPayload(array $overrides = []): array
{
    return [...['name' => 'web-1', 'mounts' => [], 'ignored_mounts' => [], 'memory_percent' => 90, 'memory_minutes' => 10, 'load_per_core' => 2,
        'load_minutes' => 15, 'no_report_minutes' => 2], ...$overrides];
}

it('saves the name and only the thresholds that differ from the defaults, and the page colours by them', function (): void {
    $this->actingAs(manageUser('Admin'));
    $server = reportingServer('web-1', filesystems: ['/' => [100, 50], '/var/lib/docker' => [100, 20]]);

    $this->patch("/servers/{$server->id}", settingsPayload(['name' => 'web-renamed', 'memory_percent' => 80, 'no_report_minutes' => 5,
        'mounts' => [['mount' => '/', 'percent' => 90, 'minutes' => 15], ['mount' => '/var/lib/docker', 'percent' => 85, 'minutes' => 30]]]))
        ->assertRedirect();

    expect($server->fresh()->name)->toBe('web-renamed')
        ->and($server->fresh()->settings)->toEqual(['memory_percent' => 80, 'no_report_minutes' => 5, 'mounts' => [['mount' => '/var/lib/docker', 'percent' => 85, 'minutes' => 30]]]);
    $this->get("/servers/{$server->id}")->assertInertia(fn (Assert $page): Assert => $page
        ->where('server.thresholds.memory_percent', 80)->where('server.thresholds.memory_minutes', 10)->where('server.thresholds.disk_percent', 90)
        ->where('server.thresholds.mounts', [['mount' => '/var/lib/docker', 'percent' => 85, 'minutes' => 30]])
        ->where('server.thresholds.no_report_minutes', 5)->where('defaults.memory_percent', 90));

    // Back on the defaults, nothing custom is stored, so a later change of the defaults applies.
    $this->patch("/servers/{$server->id}", settingsPayload())->assertRedirect();
    expect($server->fresh()->settings)->toBe([]);
});

it('refuses settings without a name or with a threshold out of range, saving nothing', function (array $change, string $field): void {
    $this->actingAs(manageUser('Owner'));
    $server = reportingServer('web-1');

    $this->patch("/servers/{$server->id}", settingsPayload($change))->assertSessionHasErrors($field);

    expect($server->fresh()->name)->toBe('web-1')->and($server->fresh()->settings)->toBe([]);
})->with([
    'no name' => [['name' => ''], 'name'],
    'RAM 0 %' => [['memory_percent' => 0], 'memory_percent'],
    'RAM over 100 %' => [['memory_percent' => 101], 'memory_percent'],
    'no duration' => [['memory_minutes' => 0], 'memory_minutes'],
    'load 0 per vCPU' => [['load_per_core' => 0], 'load_per_core'],
    'No report after 1 min' => [['no_report_minutes' => 1], 'no_report_minutes'],
    'No report after 61 min' => [['no_report_minutes' => 61], 'no_report_minutes'],
    'disk over 100 %' => [['mounts' => [['mount' => '/', 'percent' => 101, 'minutes' => 15]]], 'mounts.0.percent'],
    'mount not a path' => [['ignored_mounts' => ['boot']], 'ignored_mounts.0'],
]);

it('hides an ignored mount from the values and the chart but keeps its samples', function (): void {
    $this->actingAs(manageUser('Owner'));
    $server = reportingServer('acre-web', filesystems: ['/' => [100, 50], '/boot/efi' => [100, 1]]);

    $this->patch("/servers/{$server->id}", settingsPayload(['name' => 'acre-web', 'ignored_mounts' => ['/boot/efi'],
        'mounts' => [['mount' => '/boot/efi', 'percent' => 50, 'minutes' => 15]]]))->assertRedirect();

    // An ignored mount keeps no threshold of its own.
    expect($server->fresh()->settings)->toBe(['ignored_mounts' => ['/boot/efi']]);
    $this->get("/servers/{$server->id}")->assertInertia(fn (Assert $page): Assert => $page
        ->where('server.ignored_mounts', ['/boot/efi'])->where('server.values.filesystems', [['mount' => '/', 'total_bytes' => 100, 'free_bytes' => 50]])
        ->where('history.points.0.disks', ['/' => 50]));
    // Sorting by disk reads the shown mounts only: /boot/efi at 99 % does not count.
    $this->get('/servers?sort=disk')->assertInertia(fn (Assert $page): Assert => $page->where('servers.0.name', 'acre-web'));
    expect(DB::table('server_samples')->where('server_id', $server->id)->sole()->metrics)->toContain('/boot/efi');
});

it('links sites, moves a site linked to another server and unlinks only on its own server', function (): void {
    $this->actingAs(manageUser('Owner'));
    $first = reportingServer('first');
    $second = reportingServer('second');
    [$a, $b] = Site::factory()->count(2)->create();

    $this->post("/servers/{$first->id}/sites", ['site_ids' => [$a->id, $b->id]])->assertRedirect();
    $this->post("/servers/{$second->id}/sites", ['site_ids' => [$b->id]])->assertRedirect();

    expect(DB::table('server_sites')->orderBy('site_id')->pluck('server_id', 'site_id')->all())->toBe([$a->id => $first->id, $b->id => $second->id]);
    $this->get("/servers/{$first->id}")->assertInertia(fn (Assert $page): Assert => $page
        ->where('server.site_count', 1)->where('linked_sites.0.id', $a->id)->where('linked_sites_pagination.total', 1));

    // The first server no longer has the moved site, so its Unlink leaves it on the second.
    $this->delete("/servers/{$first->id}/sites/{$b->id}")->assertRedirect();
    $this->delete("/servers/{$first->id}/sites/{$a->id}")->assertRedirect();
    expect(DB::table('server_sites')->pluck('server_id', 'site_id')->all())->toBe([$b->id => $second->id]);
});

it('refuses to link a site that does not exist, and refuses Members', function (): void {
    $server = reportingServer('web-1');
    $site = Site::factory()->create();

    $this->actingAs(manageUser('Owner'))->post("/servers/{$server->id}/sites", ['site_ids' => [$site->id, $site->id + 999]])->assertSessionHasErrors('site_ids');
    $member = manageUser('Member');
    $member->clients()->attach($site->client);
    $this->actingAs($member)->post("/servers/{$server->id}/sites", ['site_ids' => [$site->id]])->assertForbidden();

    expect(DB::table('server_sites')->count())->toBe(0);
});

it('suggests unlinked sites whose newest DNS answer points at the server, never linked or CDN sites', function (): void {
    $this->actingAs(manageUser('Owner'));
    $server = reportingServer('web-1', ['49.12.88.204', '2a01:4f9:3a:1f::2']);
    $other = reportingServer('other', ['95.217.40.12']);
    [$ipv4, $ipv6, $cdn, $onOther, $onThis, $moved] = Site::factory()->count(6)->create();
    $at = now()->toImmutable();
    dnsAnswer($ipv4, [['A', '49.12.88.204']], $at);
    // Written differently from the agent's report, the same address.
    dnsAnswer($ipv6, [['AAAA', '2a01:04f9:003a:001f:0000:0000:0000:0002']], $at);
    dnsAnswer($cdn, [['A', '104.21.32.1'], ['AAAA', '2606:4700::6815:2001']], $at);
    dnsAnswer($onOther, [['A', '49.12.88.204']], $at);
    dnsAnswer($onThis, [['A', '49.12.88.204']], $at);
    // Pointed here once; its newest answer points elsewhere.
    dnsAnswer($moved, [['A', '49.12.88.204']], $at->subDay());
    dnsAnswer($moved, [['A', '203.0.113.9']], $at);
    $this->post("/servers/{$other->id}/sites", ['site_ids' => [$onOther->id]]);
    $this->post("/servers/{$server->id}/sites", ['site_ids' => [$onThis->id]]);

    // Not read with the page, only when the sheet asks.
    $this->get("/servers/{$server->id}")->assertInertia(fn (Assert $page): Assert => $page->missing('link'));
    $link = linkProp($this, $server);

    expect(collect($link['suggestions'])->mapWithKeys(fn (array $row): array => [$row['site']['id'] => $row['address']])->all())
        ->toEqual([$ipv4->id => '49.12.88.204', $ipv6->id => '2a01:4f9:3a:1f::2'])
        // The picker offers every site not on this server, and names the one linking would move.
        ->and(collect($link['sites'])->pluck('id')->sort()->values()->all())->toEqual(collect([$ipv4, $ipv6, $cdn, $onOther, $moved])->pluck('id')->sort()->values()->all())
        ->and($link['linked_elsewhere'])->toBe([['site_id' => $onOther->id, 'server_name' => 'other']]);
});

it('reads the suggestions with the same queries however much DNS history exists', function (): void {
    $this->actingAs(manageUser('Owner'));
    $server = reportingServer('web-1', ['49.12.88.204']);
    $sites = Site::factory()->count(3)->create();
    $count = function () use ($server): int {
        DB::enableQueryLog();
        DB::flushQueryLog();
        linkProp($this, $server);
        $queries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'dns_observations'))->count();
        DB::disableQueryLog();

        return $queries;
    };
    foreach ($sites as $site) {
        dnsAnswer($site, [['A', '49.12.88.204']], now()->toImmutable());
    }
    $few = $count();
    foreach ($sites as $site) {
        foreach (range(1, 20) as $hours) {
            dnsAnswer($site, [['A', '49.12.88.204']], now()->subHours($hours)->toImmutable(), latest: false);
        }
    }

    expect($few)->toBe(1)->and($count())->toBe($few);
});

it('shows the server parts on site pages to Owners and Admins only, and nothing while servers are off', function (string $role, bool $shown): void {
    $site = Site::factory()->create();
    Check::factory()->for($site)->create(['type' => CheckKey::Uptime]);
    $server = reportingServer('web-1', ['49.12.88.204']);
    DB::table('server_sites')->insert(['site_id' => $site->id, 'server_id' => $server->id, 'created_at' => now()]);
    $user = manageUser($role);
    $user->clients()->attach($site->client);
    $this->actingAs($user);

    $this->get("/sites/{$site->id}")->assertInertia(fn (Assert $page): Assert => $shown
        ? $page->where('server.id', $server->id)->where('server.name', 'web-1')->where('server.site_count', 1)
        : $page->where('server', null));
    if ($shown) {
        $this->get("/sites/{$site->id}/settings")->assertInertia(fn (Assert $page): Assert => $page
            ->where('server.server_id', $server->id)->where('server.servers', [['id' => $server->id, 'name' => 'web-1', 'ips' => ['49.12.88.204']]]));
        manageSwitch(false);
        $this->get("/sites/{$site->id}")->assertInertia(fn (Assert $page): Assert => $page->where('server', null));
        $this->get("/sites/{$site->id}/settings")->assertInertia(fn (Assert $page): Assert => $page->where('server', null));
    } else {
        $this->get("/sites/{$site->id}/settings")->assertForbidden();
    }
})->with([['Owner', true], ['Admin', true], ['Member', false]]);

it('never shows the server a site runs on through an API token limited to one client', function (): void {
    $site = Site::factory()->create();
    $server = reportingServer('hidden-server-name');
    DB::table('server_sites')->insert(['site_id' => $site->id, 'server_id' => $server->id, 'created_at' => now()]);
    $owner = manageUser('Owner');
    $token = $owner->createToken('agent-tools', ['*']);
    $token->accessToken->forceFill(['client_id' => $site->client_id])->save();

    $response = $this->withToken($token->plainTextToken)->getJson("/api/monitors/{$site->id}")->assertOk();

    expect($response->getContent())->not->toContain('hidden-server-name');
});

it('links, moves and unlinks a site with the Site settings Save, and refuses the field to those without servers', function (): void {
    $owner = manageUser('Owner');
    $site = Site::factory()->create();
    $first = reportingServer('first');
    $second = reportingServer('second');
    $save = fn (array $data) => $this->patch("/sites/{$site->id}", ['friendly_name' => $site->friendly_name, ...$data]);
    $this->actingAs($owner);

    $save(['server_id' => $first->id])->assertRedirect();
    expect(DB::table('server_sites')->pluck('server_id', 'site_id')->all())->toBe([$site->id => $first->id]);
    $save(['server_id' => $second->id])->assertRedirect();
    expect(DB::table('server_sites')->pluck('server_id', 'site_id')->all())->toBe([$site->id => $second->id]);
    $save(['server_id' => $second->id + 999])->assertSessionHasErrors('server_id');
    $save(['server_id' => null])->assertRedirect();
    expect(DB::table('server_sites')->count())->toBe(0);

    manageSwitch(false);
    $save(['server_id' => $first->id])->assertForbidden();
    expect(DB::table('server_sites')->count())->toBe(0);
});

it('unlinks every site when the server is removed, and names how many on the page', function (): void {
    $this->actingAs(manageUser('Owner'));
    $server = reportingServer('web-1');
    $sites = Site::factory()->count(3)->create();
    $this->post("/servers/{$server->id}/sites", ['site_ids' => $sites->pluck('id')->all()]);
    $this->get("/servers/{$server->id}")->assertInertia(fn (Assert $page): Assert => $page->where('server.site_count', 3));

    $this->delete("/servers/{$server->id}")->assertRedirect('/servers');

    expect(DB::table('server_sites')->count())->toBe(0)->and(Site::query()->count())->toBe(3);
});

it('pages the server list with the shared page sizes, an empty page beyond the end', function (): void {
    $this->actingAs(manageUser('Owner'));
    foreach (range(1, 12) as $index) {
        reportingServer(sprintf('web-%02d', $index));
    }

    $this->get('/servers?servers_per_page=10')->assertInertia(fn (Assert $page): Assert => $page
        ->count('servers', 10)->where('pagination.total', 12)->where('pagination.last_page', 2)->where('pagination.page_name', 'servers_page'));
    $this->get('/servers?servers_per_page=10&servers_page=2')->assertInertia(fn (Assert $page): Assert => $page
        ->where('servers.0.name', 'web-11')->where('servers.1.name', 'web-12')->count('servers', 2));
    $this->get('/servers?servers_per_page=10&servers_page=3')->assertInertia(fn (Assert $page): Assert => $page
        ->where('servers', [])->where('pagination.current_page', 3)->where('pagination.total', 12));
    $this->get('/servers?servers_per_page=7')->assertSessionHasErrors('servers_per_page');
});

it('sorts the list by each key, problems first, and refuses an unknown key', function (string $sort, array $order): void {
    $this->actingAs(manageUser('Owner'));
    // busy: fullest disk, most RAM, highest load; quiet: no report; idle: reporting, calm; new: awaiting its first report.
    reportingServer('busy', filesystems: ['/' => [100, 5]], load: 6.0, available: 5);
    reportingServer('idle', filesystems: ['/' => [100, 80]], load: 0.2, available: 80);
    reportingServer('quiet', filesystems: ['/' => [100, 50]], at: now()->subMinutes(10)->toImmutable(), load: 1.0, available: 50);
    Server::named('new')->issueToken();

    $this->get('/servers?sort='.$sort)->assertInertia(fn (Assert $page): Assert => $page->where('sort', $sort)
        ->where('servers', fn ($servers): bool => collect($servers)->pluck('name')->all() === $order));
})->with([
    'status (the default)' => ['status', ['quiet', 'new', 'busy', 'idle']],
    'status reversed' => ['-status', ['busy', 'idle', 'new', 'quiet']],
    'name' => ['name', ['busy', 'idle', 'new', 'quiet']],
    'name reversed' => ['-name', ['quiet', 'new', 'idle', 'busy']],
    'disk' => ['disk', ['busy', 'quiet', 'idle', 'new']],
    'disk reversed, no values still last' => ['-disk', ['idle', 'quiet', 'busy', 'new']],
    'RAM' => ['memory', ['busy', 'quiet', 'idle', 'new']],
    'load per vCPU' => ['load', ['busy', 'quiet', 'idle', 'new']],
    'last report, oldest first' => ['last_report', ['quiet', 'busy', 'idle', 'new']],
]);

it('refuses an unknown sort key or state', function (string $query, string $field): void {
    $this->actingAs(manageUser('Owner'))->get('/servers?'.$query)->assertSessionHasErrors($field);
})->with([['sort=client', 'sort'], ['sort=--name', 'sort'], ['filter[state]=down', 'filter.state']]);

it('filters the list by name or host name and by state', function (): void {
    $this->actingAs(manageUser('Owner'));
    reportingServer('solitary-darkness');
    reportingServer('dermarktladen', at: now()->subMinutes(10)->toImmutable());
    Server::named('acre-web')->issueToken();

    $names = fn (string $query): array => collect($this->get('/servers?'.$query)->inertiaProps('servers'))->pluck('name')->all();

    expect($names('filter[search]=DARK'))->toBe(['solitary-darkness'])
        ->and($names('filter[search]=dermarktladen.host'))->toBe(['dermarktladen'])
        ->and($names('filter[search]=100%25'))->toBe([])
        ->and($names('filter[state]=no_report'))->toBe(['dermarktladen'])
        ->and($names('filter[state]=awaiting'))->toBe(['acre-web'])
        ->and($names('filter[state]=reporting&filter[search]=acre'))->toBe([]);
    $this->get('/servers?filter[state]=awaiting')->assertInertia(fn (Assert $page): Assert => $page
        ->where('filters', ['search' => null, 'state' => 'awaiting'])->where('pagination.total', 1));
});

it('installs the agent of the newest known release on a source build, and names none when none is known', function (?string $latest, ?string $version): void {
    config(['heptapod.version' => 'sha-1f1e83e9']);
    if ($latest !== null) {
        DB::table('update_checks')->insert(['id' => 1, 'status' => 'reachable', 'latest_version' => $latest]);
    }
    $this->actingAs(manageUser('Owner'));
    $server = reportingServer('web-1');

    $this->get("/servers/{$server->id}")->assertInertia(fn (Assert $page): Assert => $page
        ->where('install.installed_version', 'sha-1f1e83e9')->where('install.version', $version)
        ->where('install.release_url', $version === null ? null : 'https://github.com/heptapodwatch/heptapod/releases/download/'.$version));
})->with([['v0.19.0', 'v0.19.0'], [null, null], ['main', null]]);

it('adds no site page query for Members and at most one for Owners while servers are on', function (string $role, string $page): void {
    $client = Client::factory()->create();
    $site = Site::factory()->for($client)->create();
    Check::factory()->for($site)->create(['type' => CheckKey::Uptime]);
    $server = reportingServer('web-1');
    DB::table('server_sites')->insert(['site_id' => $site->id, 'server_id' => $server->id, 'created_at' => now()]);
    $user = manageUser($role);
    $user->clients()->attach($client);
    $path = $page === 'overview' ? "/sites/{$site->id}" : "/sites/{$site->id}/settings";
    $count = function () use ($user, $path): int {
        $this->actingAs($user)->get($path);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get($path);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };
    config(['heptapod.servers.enabled_by_server' => false]);
    $off = $count();
    config(['heptapod.servers.enabled_by_server' => true]);

    expect($count() - $off)->toBeLessThanOrEqual($role === 'Member' ? 0 : 1);
})->with([['Owner', 'overview'], ['Owner', 'settings'], ['Member', 'overview']]);
