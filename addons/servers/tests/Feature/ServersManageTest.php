<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Addons\Servers\Server;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\SiteServers;
use Heptapod\Models\Check;
use Heptapod\Models\Client;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
 * The DNS check's accepted answer for the site as DnsCheck stores it: its newest run (checks.latest_run_id) has these
 * A/AAAA records, grouped by the name queried (`<name>|A`); the site's own host name unless a record names another (the
 * apex or an extra name the check also watches).
 *
 * @param  list<array{0: string, 1: string, 2?: string}>  $records  [type, address, queried name]
 */
function dnsAnswer(Site $site, array $records, CarbonImmutable $endedAt, bool $latest = true): void
{
    static $runId = 1000;
    $runId++;
    $check = Check::query()->where('site_id', $site->id)->where('type', CheckKey::Dns->value)->first()
        ?? Check::factory()->for($site)->create(['type' => CheckKey::Dns]);
    $groups = [];
    foreach ($records as $record) {
        $name = $record[2] ?? parse_url($site->url, PHP_URL_HOST);
        $groups[$name.'|'.$record[0]][] = ['host' => $name, 'type' => $record[0], 'class' => 'IN', ...($record[0] === 'A' ? ['ip' => $record[1]] : ['ipv6' => $record[1]])];
    }
    DB::table('dns_observations')->insert(['site_id' => $site->id, 'check_id' => $check->id, 'run_id' => $runId, 'claim_token' => (string) Str::uuid(),
        'run_ended_at' => $endedAt->format('Y-m-d H:i:s.uP'), 'expected' => '[]', 'created_at' => $endedAt->format('Y-m-d H:i:s.uP'),
        'snapshot' => json_encode(['dns_records' => array_merge([], ...array_values($groups)), 'normalized_records' => $groups], JSON_THROW_ON_ERROR)]);
    if ($latest) {
        $check->forceFill(['latest_run_id' => $runId])->save();
    }
}

/** What the "Link site…" sheet reads when it opens, or for a search. */
function linkChoices(object $test, Server $server, string $search = ''): array
{
    return $test->getJson("/servers/{$server->id}/sites".($search === '' ? '' : '?q='.urlencode($search)))->assertOk()->json();
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

it('keeps a mount\'s threshold and duration each on its own, so a duration change follows a later default percent', function (): void {
    $this->actingAs(manageUser('Owner'));
    $server = reportingServer('web-1', filesystems: ['/' => [100, 50]]);

    $this->patch("/servers/{$server->id}", settingsPayload(['mounts' => [['mount' => '/', 'percent' => 90, 'minutes' => 30]]]))->assertRedirect();
    config(['servers.thresholds.disk.percent' => 80]);

    expect($server->fresh()->settings)->toBe(['mounts' => [['mount' => '/', 'minutes' => 30]]]);
    $this->get("/servers/{$server->id}")->assertInertia(fn (Assert $page): Assert => $page
        ->where('server.thresholds.mounts', [['mount' => '/', 'percent' => 80, 'minutes' => 30]]));
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

    $link = linkChoices($this, $server);

    expect(collect($link['suggestions'])->mapWithKeys(fn (array $row): array => [$row['site']['id'] => $row['address']])->all())
        ->toEqual([$ipv4->id => '49.12.88.204', $ipv6->id => '2a01:4f9:3a:1f::2'])
        // The picker offers the other sites not on this server, and names the one linking would move.
        ->and(collect($link['sites'])->pluck('id')->sort()->values()->all())->toEqual(collect([$cdn, $onOther, $moved])->pluck('id')->sort()->values()->all())
        ->and($link['linked_elsewhere'])->toBe([['site_id' => $onOther->id, 'server_name' => 'other']]);
});

it('suggests a site only for the answer of its own host name, never for the apex or an extra name the check watches', function (): void {
    $this->actingAs(manageUser('Owner'));
    $server = reportingServer('web-1', ['49.12.88.204']);
    [$own, $apexOnly, $extraOnly] = Site::factory()->count(3)->create();
    $at = now()->toImmutable();
    dnsAnswer($own, [['A', '49.12.88.204']], $at);
    // Behind a CDN, while the apex and an extra CNAME the same check monitors point at the server.
    dnsAnswer($apexOnly, [['A', '104.21.32.1'], ['A', '49.12.88.204', 'example.test']], $at);
    dnsAnswer($extraOnly, [['A', '104.21.32.1'], ['A', '49.12.88.204', 'legacy.example.test']], $at);

    expect(array_column(array_column(linkChoices($this, $server)['suggestions'], 'site'), 'id'))->toBe([$own->id]);
});

it('suggests at most 20 sites, an answer wholly on the server first, then more matching addresses', function (): void {
    $this->actingAs(manageUser('Owner'));
    $server = reportingServer('web-1', ['49.12.88.204', '2a01:4f9:3a:1f::2']);
    $at = now()->toImmutable();
    // Created first, so a plain id order would put them first.
    $partial = Site::factory()->create();
    dnsAnswer($partial, [['A', '49.12.88.204'], ['A', '203.0.113.9']], $at);
    $single = Site::factory()->count(22)->create();
    foreach ($single as $site) {
        dnsAnswer($site, [['A', '49.12.88.204']], $at);
    }
    $both = Site::factory()->create();
    dnsAnswer($both, [['A', '49.12.88.204'], ['AAAA', '2a01:4f9:3a:1f::2']], $at);

    $ids = array_column(array_column(linkChoices($this, $server)['suggestions'], 'site'), 'id');

    expect($ids)->toHaveCount(20)
        ->and($ids[0])->toBe($both->id)
        ->and(array_slice($ids, 1))->toBe($single->take(19)->pluck('id')->all())
        ->and($ids)->not->toContain($partial->id);
});

it('still suggests when a DNS check stored no record groups at all (an empty snapshot)', function (): void {
    $this->actingAs(manageUser('Owner'));
    $server = reportingServer('web-1', ['49.12.88.204']);
    [$empty, $pointing] = Site::factory()->count(2)->create();
    // A check that ignores every record type stores normalized_records as an empty JSON array.
    $check = Check::factory()->for($empty)->create(['type' => CheckKey::Dns]);
    DB::table('dns_observations')->insert(['site_id' => $empty->id, 'check_id' => $check->id, 'run_id' => 990001, 'claim_token' => (string) Str::uuid(),
        'run_ended_at' => now(), 'expected' => '[]', 'created_at' => now(), 'snapshot' => json_encode(['dns_records' => [], 'normalized_records' => []])]);
    $check->forceFill(['latest_run_id' => 990001])->save();
    dnsAnswer($pointing, [['A', '49.12.88.204']], now()->toImmutable());

    expect(array_column(array_column(linkChoices($this, $server)['suggestions'], 'site'), 'id'))->toBe([$pointing->id]);
});

it('leaves sites that cannot be linked out before the 20 strongest suggestions are taken', function (): void {
    $this->actingAs(manageUser('Owner'));
    $server = reportingServer('web-1', ['49.12.88.204', '2a01:4f9:3a:1f::2']);
    $at = now()->toImmutable();
    // 21 stronger matches on an archived client: the user cannot see them, so they must not take the 20 places.
    $archived = Client::factory()->create(['archived_at' => now()]);
    foreach (Site::factory()->count(21)->for($archived)->create() as $site) {
        dnsAnswer($site, [['A', '49.12.88.204'], ['AAAA', '2a01:4f9:3a:1f::2']], $at);
    }
    $visible = Site::factory()->create();
    dnsAnswer($visible, [['A', '49.12.88.204']], $at);

    expect(array_column(array_column(linkChoices($this, $server)['suggestions'], 'site'), 'id'))->toBe([$visible->id]);
});

it('refuses the Link site choices to Members and answers 404 while servers are off', function (): void {
    $server = reportingServer('web-1');
    $this->actingAs(manageUser('Member'))->getJson("/servers/{$server->id}/sites")->assertForbidden();
    $owner = manageUser('Owner');
    $this->actingAs($owner)->getJson("/servers/{$server->id}/sites")->assertOk();
    manageSwitch(false);
    $this->actingAs($owner)->getJson("/servers/{$server->id}/sites")->assertNotFound();
});

it('searches the sites to pick on the server, at most 20 a time', function (): void {
    $this->actingAs(manageUser('Owner'));
    $server = reportingServer('web-1');
    Site::factory()->count(25)->sequence(fn ($sequence): array => ['friendly_name' => 'Shop '.$sequence->index])->create();
    $blog = Site::factory()->create(['friendly_name' => 'Company blog']);

    expect(linkChoices($this, $server)['sites'])->toHaveCount(20)
        ->and(array_column(linkChoices($this, $server, 'BLOG')['sites'], 'id'))->toBe([$blog->id])
        ->and(linkChoices($this, $server, 'nothing-like-this')['sites'])->toBe([]);
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
    $server = reportingServer('hidden-server-name', ['198.51.100.77']);
    DB::table('server_sites')->insert(['site_id' => $site->id, 'server_id' => $server->id, 'created_at' => now()]);
    $owner = manageUser('Owner');
    $token = $owner->createToken('agent-tools', ['*']);
    $token->accessToken->forceFill(['client_id' => $site->client_id])->save();

    $response = $this->withToken($token->plainTextToken)->getJson("/api/monitors/{$site->id}")->assertOk();

    // No server key at any depth (`server`, `server_id`, `servers`…), and none of the server's name, host name or address.
    $serverKeys = array_filter(array_keys(Arr::dot($response->json())), fn (string $path): bool => preg_match('/(^|\.)servers?(_[a-z_]+)?(\.|$)/', $path) === 1);
    expect($serverKeys)->toBe([])
        ->and($response->getContent())->not->toContain('hidden-server-name')->not->toContain('hidden-server-name.host')->not->toContain('198.51.100.77');
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
    // A server that does not exist refuses the whole Save: the rename is not kept either.
    $this->patch("/sites/{$site->id}", ['friendly_name' => 'Renamed shop', 'server_id' => $second->id + 999])->assertSessionHasErrors('server_id');
    expect($site->fresh()->friendly_name)->not->toBe('Renamed shop');
    // A Save without the field (the form sends it only when changed) leaves the link as it is.
    $save(['friendly_name' => 'Renamed shop'])->assertRedirect();
    expect($site->fresh()->friendly_name)->toBe('Renamed shop')
        ->and(DB::table('server_sites')->pluck('server_id', 'site_id')->all())->toBe([$site->id => $second->id]);
    $save(['server_id' => null])->assertRedirect();
    expect(DB::table('server_sites')->count())->toBe(0);

    manageSwitch(false);
    $save(['server_id' => $first->id])->assertForbidden();
    expect(DB::table('server_sites')->count())->toBe(0);
});

it('treats a server change as an extra setting: a site of an archived client takes a move only', function (): void {
    $this->actingAs(manageUser('Owner'));
    $server = reportingServer('web-1');
    $archived = Client::factory()->create(['archived_at' => now()]);
    $active = Client::factory()->create();
    $site = Site::factory()->for($archived)->create();

    $this->patch("/sites/{$site->id}", ['server_id' => $server->id])->assertForbidden();
    $this->patch("/sites/{$site->id}", ['client_id' => $active->id, 'server_id' => $server->id])->assertForbidden();

    expect(DB::table('server_sites')->count())->toBe(0)->and($site->fresh()->client_id)->toBe($archived->id);
    // The bare move stays allowed, and the moved site then takes its server.
    $this->patch("/sites/{$site->id}", ['client_id' => $active->id])->assertRedirect();
    $this->patch("/sites/{$site->id}", ['server_id' => $server->id])->assertRedirect();
    expect($site->fresh()->client_id)->toBe($active->id)->and(DB::table('server_sites')->pluck('server_id', 'site_id')->all())->toBe([$site->id => $server->id]);
});

it('reads and writes no server link through SiteServers while servers are off', function (): void {
    $site = Site::factory()->create();
    $server = reportingServer('web-1');
    DB::table('server_sites')->insert(['site_id' => $site->id, 'server_id' => $server->id, 'created_at' => now()]);
    $servers = app(SiteServers::class);
    manageSwitch(false);

    expect($servers->ofSite($site->id))->toBeNull()->and($servers->choices($site->id))->toBeNull()
        ->and(fn () => $servers->link($site->id, null))->toThrow(NotFoundHttpException::class)
        ->and(fn () => $servers->holdServer($server->id))->toThrow(NotFoundHttpException::class)
        ->and(DB::table('server_sites')->count())->toBe(1);
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

it('pages the server list with the shared page sizes, the last page for a page beyond the end', function (): void {
    $this->actingAs(manageUser('Owner'));
    foreach (range(1, 12) as $index) {
        reportingServer(sprintf('web-%02d', $index));
    }

    $this->get('/servers?servers_per_page=10')->assertInertia(fn (Assert $page): Assert => $page
        ->count('servers', 10)->where('pagination.total', 12)->where('pagination.last_page', 2)->where('pagination.page_name', 'servers_page'));
    $this->get('/servers?servers_per_page=10&servers_page=2')->assertInertia(fn (Assert $page): Assert => $page
        ->where('servers.0.name', 'web-11')->where('servers.1.name', 'web-12')->count('servers', 2));
    // A page beyond the end shows the last page.
    $this->get('/servers?servers_per_page=10&servers_page=3')->assertInertia(fn (Assert $page): Assert => $page
        ->count('servers', 2)->where('pagination.current_page', 2)->where('pagination.total', 12));
    $this->get('/servers?servers_per_page=7')->assertSessionHasErrors('servers_per_page');
});

it('pages the linked sites, the last page beyond the end, and counts the links of archived clients apart', function (): void {
    $this->actingAs(manageUser('Owner'));
    $server = reportingServer('web-1');
    $sites = Site::factory()->count(12)->create();
    $this->post("/servers/{$server->id}/sites", ['site_ids' => $sites->pluck('id')->all()]);
    $archived = $sites->first()->client;
    $archived->forceFill(['archived_at' => now()])->save();

    // 12 stored links, 11 listed: the archived client's site keeps its link (for a restore) but is not shown.
    $this->get("/servers/{$server->id}?linked_sites_per_page=10&linked_sites_page=5")->assertInertia(fn (Assert $page): Assert => $page
        ->where('server.site_count', 12)->where('linked_sites_pagination.total', 11)->where('linked_sites_pagination.current_page', 2)
        ->count('linked_sites', 1));
    expect(DB::table('server_sites')->where('server_id', $server->id)->count())->toBe(12);
});

it('sorts the list by each key, problems first, and refuses an unknown key', function (string $sort, array $order): void {
    $this->actingAs(manageUser('Owner'));
    // busy: fullest disk, most RAM, highest load; quiet: no report; idle: reporting, calm; new: awaiting its first report.
    reportingServer('busy', filesystems: ['/' => [100, 5]], load: 6.0, available: 5);
    $idle = reportingServer('idle', filesystems: ['/' => [100, 80]], load: 0.2, available: 80);
    $quiet = reportingServer('quiet', filesystems: ['/' => [100, 50]], at: now()->subMinutes(10)->toImmutable(), load: 1.0, available: 50);
    Server::named('new')->issueToken();
    // Linked sites: quiet 2, idle 1, the others none.
    $this->post("/servers/{$quiet->id}/sites", ['site_ids' => Site::factory()->count(2)->create()->pluck('id')->all()]);
    $this->post("/servers/{$idle->id}/sites", ['site_ids' => [Site::factory()->create()->id]]);

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
    'linked sites, most first' => ['sites', ['quiet', 'idle', 'busy', 'new']],
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
        ->where('filters.search.value', null)->where('filters.state.value', 'awaiting')->where('pagination.total', 1));
});

it('searches for server names containing literal commas and boolean words', function (): void {
    $this->actingAs(manageUser('Owner'));
    Server::named('true host')->issueToken();
    Server::named('east,west')->issueToken();
    Server::named('east')->issueToken();

    $this->get('/servers?filter[search]=true')->assertInertia(fn (Assert $page): Assert => $page
        ->has('servers', 1)->where('servers.0.name', 'true host'));
    $this->get('/servers?filter[search]=east%2Cwest')->assertInertia(fn (Assert $page): Assert => $page
        ->has('servers', 1)->where('servers.0.name', 'east,west'));
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

it('reads no server table while servers are off, none for Members and one for Owners while on', function (string $role, string $page): void {
    $client = Client::factory()->create();
    $site = Site::factory()->for($client)->create();
    Check::factory()->for($site)->create(['type' => CheckKey::Uptime]);
    $server = reportingServer('web-1');
    DB::table('server_sites')->insert(['site_id' => $site->id, 'server_id' => $server->id, 'created_at' => now()]);
    $user = manageUser($role);
    $user->clients()->attach($client);
    $path = $page === 'overview' ? "/sites/{$site->id}" : "/sites/{$site->id}/settings";
    /** @return array{int, list<string>} every query, and those on a server table */
    $count = function () use ($user, $path): array {
        $this->actingAs($user)->get($path);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get($path);
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        return [count($queries), array_values(array_filter($queries, fn (string $query): bool => preg_match('/\b(servers|server_sites|server_samples)\b/', $query) === 1))];
    };
    config(['heptapod.servers.enabled_by_server' => false]);
    [$off, $serverQueriesOff] = $count();
    config(['heptapod.servers.enabled_by_server' => true]);
    [$on, $serverQueriesOn] = $count();

    // Off, no query touches a server table; on, Members still read none and Owners one.
    expect($serverQueriesOff)->toBe([])
        ->and($on - $off)->toBeLessThanOrEqual($role === 'Member' ? 0 : 1)
        ->and(count($serverQueriesOn))->toBe($role === 'Member' ? 0 : 1);
})->with([['Owner', 'overview'], ['Owner', 'settings'], ['Member', 'overview']]);
