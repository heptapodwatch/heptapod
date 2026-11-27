<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use cebe\openapi\Reader;
use Heptapod\Actions\CreateApiToken;
use Heptapod\Addons\Servers\Server;
use Heptapod\Addons\Servers\ServerIssue;
use Heptapod\Addons\Servers\ServerIssueKind;
use Heptapod\Models\Client;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spectator\Validation\ResponseValidator;

require_once __DIR__.'/../Pest.php';

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-27T07:00:00Z'));
    config(['heptapod.servers.enabled_by_server' => true]);
    apiSwitch(true);
});

function apiSwitch(bool $on): void
{
    $settings = app(InstanceSettings::class);
    $settings->servers_enabled = $on;
    $settings->save();
}

function apiUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/** @param list<string> $abilities */
function apiToken(User $user, array $abilities, ?int $clientId = null): string
{
    return app(CreateApiToken::class)($user, 'agent', $abilities, $clientId);
}

/**
 * A client-limited token stored with server abilities, as issued before CreateApiToken refused that pair: still denied.
 *
 * @param  list<string>  $abilities
 */
function storedClientToken(User $user, array $abilities, int $clientId): string
{
    $token = $user->createToken('stored', $abilities);
    $token->accessToken->forceFill(['client_id' => $clientId])->save();

    return $token->plainTextToken;
}

/** A reporting server with one stored sample, as ingest leaves it: root 90 % used, RAM 75 % used, load 3 on 2 vCPU. */
function apiServer(string $name, array $ips = ['203.0.113.10']): Server
{
    $at = now()->toImmutable()->subSeconds(30);
    $server = Server::named($name);
    $server->issueToken();
    $server->forceFill(['ips' => $ips, 'last_report_at' => $at, 'hostname' => $name.'.example.com', 'agent_version' => '0.3.0', 'agent_os' => 'linux',
        'agent_distro' => 'Debian 13', 'settings' => ['memory_percent' => 80, 'ignored_mounts' => ['/boot/efi']]])->save();
    DB::table('server_samples')->insert(['server_id' => $server->id, 'sample_id' => (string) Str::uuid(),
        'observed_at' => $at->format('Y-m-d H:i:s.uP'), 'received_at' => $at->format('Y-m-d H:i:s.uP'),
        'metrics' => json_encode(['cpu' => ['cores' => 2, 'used_percent' => 20], 'load' => ['1' => 3, '5' => 3, '15' => 3],
            'memory' => ['total_bytes' => 400, 'available_bytes' => 100],
            'filesystems' => [['mount' => '/', 'total_bytes' => 1000, 'free_bytes' => 100], ['mount' => '/boot/efi', 'total_bytes' => 10, 'free_bytes' => 9]],
            'host' => ['uptime_seconds' => 3600, 'reboot_required' => true], 'updates' => ['pending' => 4, 'security' => 1]], JSON_THROW_ON_ERROR)]);

    return $server;
}

/** @param array<string, string|int|float>|null $resolvedValues */
function apiIssue(Server $server, ServerIssueKind $kind, string $openedAt, ?string $resolvedAt = null, ?array $resolvedValues = null, string $scope = ''): ServerIssue
{
    return ServerIssue::query()->create(['server_id' => $server->id, 'kind' => $kind, 'scope' => $scope, 'opened_at' => CarbonImmutable::parse($openedAt),
        'resolved_at' => $resolvedAt === null ? null : CarbonImmutable::parse($resolvedAt), 'opened_values' => ['value' => 91.0, 'threshold' => 90, 'minutes' => 15],
        'resolved_values' => $resolvedValues]);
}

/**
 * Calls the kernel and validates every 2xx response against the committed contract (docs/openapi.json).
 *
 * @param  array<string, mixed>  $body
 */
function serversApi(string $method, string $uri, ?string $token, array $body = []): TestResponse
{
    app('auth')->forgetGuards();
    $response = app(HttpKernel::class)->handle(Request::create($uri, $method, server: [...($token === null ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$token]),
        'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'], content: $body === [] ? null : json_encode($body, JSON_THROW_ON_ERROR)));
    // Monitors are validated against the pinned Oh Dear spec elsewhere (ApiTestCase).
    if ($response->getStatusCode() < 300 && str_starts_with($uri, '/api/servers')) {
        $path = (string) preg_replace(['~^/api~', '~/servers/[0-9]+~'], ['', '/servers/{server}'], (string) parse_url($uri, PHP_URL_PATH));
        ResponseValidator::validate($response, Reader::readFromJsonFile(base_path('docs/openapi.json'))->paths[$path]->{strtolower($method)}, '3.1.2');
    }

    return TestResponse::fromBaseResponse($response);
}

it('lists servers with their state, newest values, open issue count and snooze, filtered by state, without any agent token', function (): void {
    $web = apiServer('web-1');
    $quiet = apiServer('quiet-db', ['198.51.100.7']);
    $quiet->forceFill(['last_report_at' => now()->subMinutes(10), 'snoozed_until' => now()->addHour()])->save();
    $awaiting = Server::named('new-box');
    $awaiting->issueToken();
    apiIssue($web, ServerIssueKind::DiskFull, '2026-11-27T06:40:00Z', scope: '/');
    apiIssue($web, ServerIssueKind::MemoryLow, '2026-11-26T06:00:00Z', '2026-11-26T07:00:00Z', ['value' => 40.0]);
    $token = apiToken(apiUser('Admin'), ['servers:read']);

    $list = serversApi('GET', '/api/servers', $token)->assertOk()->assertJsonPath('meta.total', 3)->assertJsonPath('data.*.name', ['web-1', 'quiet-db', 'new-box']);
    $list->assertJsonPath('data.0', ['id' => $web->id, 'name' => 'web-1', 'hostname' => 'web-1.example.com', 'ips' => ['203.0.113.10'], 'state' => 'reporting',
        'open_issue_count' => 1, 'last_report_at' => '2026-11-27T06:59:30+00:00', 'agent_version' => '0.3.0', 'snoozed_until' => null,
        'values' => ['observed_at' => '2026-11-27T06:59:30+00:00', 'cpu_cores' => 2, 'cpu_used_percent' => 20, 'load_5' => 3, 'memory_total_bytes' => 400,
            'memory_available_bytes' => 100, 'memory_used_percent' => 75, 'filesystems' => [['mount' => '/', 'total_bytes' => 1000, 'free_bytes' => 100, 'used_percent' => 90]]]]);
    $list->assertJsonPath('data.1.state', 'no_report')->assertJsonPath('data.1.snoozed_until', '2026-11-27T08:00:00+00:00')
        ->assertJsonPath('data.2.state', 'awaiting')->assertJsonPath('data.2.values', null);
    expect($list->getContent())->not->toContain('token')->not->toContain($web->token_hash)->not->toContain($web->public_id);

    serversApi('GET', '/api/servers?filter[state]=attention', $token)->assertOk()->assertJsonPath('data.*.name', ['web-1']);
    serversApi('GET', '/api/servers?filter[state]=no_report', $token)->assertOk()->assertJsonPath('data.*.name', ['quiet-db']);
    serversApi('GET', '/api/servers?per_page=2&page=2', $token)->assertOk()->assertJsonPath('data.*.name', ['new-box'])->assertJsonPath('meta.last_page', 2);
    serversApi('GET', '/api/servers?filter[state]=down', $token)->assertUnprocessable()->assertJsonValidationErrors('filter.state');
});

it('reads one server with its thresholds, facts, linked sites and open issues', function (): void {
    $web = apiServer('web-1');
    $site = Site::factory()->create(['friendly_name' => 'Shop', 'url' => 'https://shop.example.com']);
    DB::table('server_sites')->insert(['site_id' => $site->id, 'server_id' => $web->id, 'created_at' => now()]);
    $disk = apiIssue($web, ServerIssueKind::DiskFull, '2026-11-27T06:40:00Z', scope: '/');
    apiIssue($web, ServerIssueKind::MemoryLow, '2026-11-26T06:00:00Z', '2026-11-26T07:00:00Z', ['value' => 40.0]);

    serversApi('GET', "/api/servers/{$web->id}", apiToken(apiUser('Owner'), ['servers:read']))->assertOk()
        ->assertJsonPath('thresholds.memory_percent', 80)->assertJsonPath('thresholds.disk_percent', 90)->assertJsonPath('ignored_mounts', ['/boot/efi'])
        ->assertJsonPath('facts', ['os' => 'linux', 'arch' => null, 'distro' => 'Debian 13', 'kernel' => null, 'uptime_seconds' => 3600, 'reboot_required' => true,
            'updates_pending' => 4, 'updates_security' => 1])
        ->assertJsonPath('sites', [['id' => $site->id, 'name' => 'Shop', 'url' => 'https://shop.example.com']])
        ->assertJsonPath('issues.*.id', [$disk->id])->assertJsonPath('issues.0.resolved_values', null)
        // Stored as jsonb, which keeps no key order.
        ->assertJson(['issues' => [['kind' => 'disk_full', 'scope' => '/', 'opened_at' => '2026-11-27T06:40:00+00:00', 'resolved_at' => null,
            'opened_values' => ['value' => 91, 'threshold' => 90, 'minutes' => 15]]]]);
    serversApi('GET', '/api/servers/999999', apiToken(apiUser('Owner'), ['servers:read']))->assertNotFound();
});

it('pages a server\'s issues, open and resolved, newest opening first, and only that server\'s', function (): void {
    $web = apiServer('web-1');
    $other = apiServer('db-1');
    $old = apiIssue($web, ServerIssueKind::NoReport, '2026-11-20T06:00:00Z', '2026-11-20T06:05:00Z', ['reported_at' => '2026-11-20T06:05:00+00:00']);
    $open = apiIssue($web, ServerIssueKind::DiskFull, '2026-11-27T06:40:00Z', scope: '/');
    $resolved = apiIssue($web, ServerIssueKind::MemoryLow, '2026-11-26T06:00:00Z', '2026-11-26T07:00:00Z', ['value' => 40.0]);
    apiIssue($other, ServerIssueKind::LoadHigh, '2026-11-27T06:50:00Z');
    $token = apiToken(apiUser('Owner'), ['servers:read']);

    serversApi('GET', "/api/servers/{$web->id}/issues", $token)->assertOk()->assertJsonPath('data.*.id', [$open->id, $resolved->id, $old->id])
        ->assertJsonPath('data.1.resolved_at', '2026-11-26T07:00:00+00:00')->assertJsonPath('data.1.resolved_values', ['value' => 40])
        ->assertJsonPath('data.0.resolved_values', null)->assertJsonPath('meta.total', 3);
    serversApi('GET', "/api/servers/{$web->id}/issues?page[number]=2&page[size]=2", $token)->assertOk()->assertJsonPath('data.*.id', [$old->id]);
});

it('snoozes and unsnoozes a server with the server page\'s choices and validation', function (): void {
    $web = apiServer('web-1');
    $token = apiToken(apiUser('Admin'), ['servers:read', 'servers:snooze']);

    serversApi('POST', "/api/servers/{$web->id}/snooze", $token, ['snooze_until' => '2026-11-27T08:30:00Z'])->assertOk()
        ->assertExactJson(['id' => $web->id, 'snoozed_until' => '2026-11-27T08:30:00+00:00']);
    expect($web->refresh()->snoozed_until?->toIso8601String())->toBe('2026-11-27T08:30:00+00:00');
    serversApi('POST', "/api/servers/{$web->id}/snooze", $token, ['minutes' => '45'])->assertOk()->assertJsonPath('snoozed_until', '2026-11-27T07:45:00+00:00');
    serversApi('POST', "/api/servers/{$web->id}/snooze", $token, ['snooze_until' => '2026-11-27T08:30:00'])->assertUnprocessable()->assertJsonValidationErrors('snooze_until');
    serversApi('POST', "/api/servers/{$web->id}/snooze", $token, ['snooze_until' => '2026-11-27T06:30:00Z'])->assertUnprocessable()->assertJsonValidationErrors('snooze_until');
    serversApi('POST', "/api/servers/{$web->id}/snooze", $token, ['minutes' => 525601])->assertUnprocessable()->assertJsonValidationErrors('minutes');
    serversApi('POST', "/api/servers/{$web->id}/snooze", $token)->assertUnprocessable()->assertJsonValidationErrors('minutes');
    expect($web->refresh()->snoozed_until?->toIso8601String())->toBe('2026-11-27T07:45:00+00:00');

    serversApi('POST', "/api/servers/{$web->id}/unsnooze", $token)->assertOk()->assertExactJson(['id' => $web->id, 'snoozed_until' => null]);
    serversApi('POST', "/api/servers/{$web->id}/unsnooze", $token)->assertOk()->assertExactJson(['id' => $web->id, 'snoozed_until' => null]);
    expect($web->refresh()->snoozed_until)->toBeNull();
});

it('lets a token with only servers:snooze snooze, and read nothing but the receipt', function (): void {
    $web = apiServer('web-1');
    $token = apiToken(apiUser('Owner'), ['servers:snooze']);

    $snoozed = serversApi('POST', "/api/servers/{$web->id}/snooze", $token, ['minutes' => 30])->assertOk();
    expect($snoozed->json())->toBe(['id' => $web->id, 'snoozed_until' => '2026-11-27T07:30:00+00:00']);
    expect(serversApi('POST', "/api/servers/{$web->id}/unsnooze", $token)->assertOk()->json())->toBe(['id' => $web->id, 'snoozed_until' => null]);
    foreach (['/api/servers', "/api/servers/{$web->id}", "/api/servers/{$web->id}/issues"] as $uri) {
        serversApi('GET', $uri, $token)->assertForbidden();
    }
});

it('needs servers:read to read and servers:snooze to snooze', function (): void {
    $web = apiServer('web-1');
    $owner = apiUser('Owner');
    $reader = apiToken($owner, ['servers:read']);
    $other = apiToken($owner, ['monitors:read', 'checks:snooze']);

    foreach (['/api/servers', "/api/servers/{$web->id}", "/api/servers/{$web->id}/issues"] as $uri) {
        serversApi('GET', $uri, $other)->assertForbidden();
    }
    serversApi('POST', "/api/servers/{$web->id}/snooze", $reader, ['minutes' => 30])->assertForbidden();
    serversApi('POST', "/api/servers/{$web->id}/unsnooze", $other)->assertForbidden();
    expect($web->refresh()->snoozed_until)->toBeNull();
});

it('denies a token limited to one client and a Member, even with a wildcard token, like the server pages', function (string $who): void {
    $web = apiServer('web-1');
    $token = match ($who) {
        'Owner limited to one client' => storedClientToken(apiUser('Owner'), ['servers:read', 'servers:snooze'], Client::factory()->create()->id),
        'Member with a wildcard token' => apiUser('Member')->createToken('legacy', ['*'])->plainTextToken,
    };

    foreach (['/api/servers', "/api/servers/{$web->id}", "/api/servers/{$web->id}/issues"] as $uri) {
        serversApi('GET', $uri, $token)->assertForbidden()->assertJsonMissingPath('data');
    }
    serversApi('POST', "/api/servers/{$web->id}/snooze", $token, ['minutes' => 30])->assertForbidden();
    expect($web->refresh()->snoozed_until)->toBeNull();
})->with(['Owner limited to one client', 'Member with a wildcard token']);

it('offers the servers abilities to Owners and Admins only while servers are on, and keeps them stored on tokens while off', function (): void {
    $owner = apiUser('Owner');
    $member = apiUser('Member');
    $create = app(CreateApiToken::class);
    $token = apiToken($owner, ['servers:read']);
    $server = apiServer('web-1');

    expect($create->allowedAbilities($owner))->toContain('servers:read', 'servers:snooze')
        ->and($create->startingPoints($owner)['read-everything'])->toContain('servers:read')->not->toContain('servers:snooze')
        ->and($create->startingPoints($owner)['full-access'])->toContain('servers:read', 'servers:snooze')
        ->and($create->allowedAbilities($member))->not->toContain('servers:read')
        ->and($create->presets($owner)['read'])->not->toContain('servers:read');
    expect($this->actingAs($owner)->getJson('/settings/api-tokens/data')->assertOk()->json('abilities'))->toContain('servers:read', 'servers:snooze');
    $this->actingAs($member)->postJson('/settings/api-tokens', ['name' => 'x', 'abilities' => ['servers:read']])->assertUnprocessable();

    apiSwitch(false);
    expect($create->allowedAbilities($owner))->not->toContain('servers:read')->not->toContain('servers:snooze')
        ->and($create->startingPoints($owner)['read-everything'])->not->toContain('servers:read')
        ->and($create->startingPoints($owner)['full-access'])->not->toContain('servers:snooze');
    expect($this->actingAs($owner)->getJson('/settings/api-tokens/data')->assertOk()->json('abilities'))->not->toContain('servers:read');
    $this->actingAs($owner)->postJson('/settings/api-tokens', ['name' => 'x', 'abilities' => ['servers:read']])->assertUnprocessable();
    expect(DB::table('personal_access_tokens')->where('name', 'agent')->value('abilities'))->toBe('["servers:read"]');

    apiSwitch(true);
    serversApi('GET', "/api/servers/{$server->id}", $token)->assertOk();
});

it('answers every server operation while switched off exactly like an unknown API route, before the token is checked', function (string $switch): void {
    config(['app.debug' => false]);
    $web = apiServer('web-1');
    $token = apiToken(apiUser('Owner'), ['servers:read', 'servers:snooze']);
    $switch === 'environment' ? config(['heptapod.servers.enabled_by_server' => false]) : apiSwitch(false);
    // Status, body (its path written as {path}) and header names, as a client sees them.
    $seen = static function (string $method, string $uri, ?string $bearer): array {
        $response = serversApi($method, $uri, $bearer, $method === 'POST' ? ['minutes' => 30] : []);
        $headers = array_diff(array_keys($response->headers->all()), ['date']);
        sort($headers);

        return [$response->status(), str_replace(ltrim($uri, '/'), '{path}', (string) $response->getContent()), $headers];
    };

    foreach ([$token, null] as $bearer) {
        $operations = [['GET', '/api/servers'], ['GET', "/api/servers/{$web->id}"], ['GET', "/api/servers/{$web->id}/issues"],
            ['POST', "/api/servers/{$web->id}/snooze"], ['POST', "/api/servers/{$web->id}/unsnooze"]];
        foreach ($operations as [$method, $uri]) {
            expect($seen($method, $uri, $bearer))->toBe($seen($method, '/api/no-such-route', $bearer))
                ->and($seen($method, $uri, $bearer)[0])->toBe(404);
        }
    }
    expect($web->refresh()->snoozed_until)->toBeNull();
})->with(['environment', 'Owner toggle']);

it('never grants the servers abilities to a token limited to one client, and offers them only without a limit', function (): void {
    $owner = apiUser('Owner');
    $client = Client::factory()->create();
    $create = app(CreateApiToken::class);

    expect($create->allowedAbilities($owner, limitedToClient: true))->not->toContain('servers:read')->not->toContain('servers:snooze')
        ->and($create->allowedAbilities($owner))->toContain('servers:read', 'servers:snooze');
    $page = $this->actingAs($owner)->getJson('/settings/api-tokens/data')->assertOk();
    expect($page->json('abilities'))->toContain('servers:read')
        ->and($page->json('client_abilities'))->toBe(array_values(array_diff($page->json('abilities'), ['servers:read', 'servers:snooze'])));

    foreach ([['servers:read'], ['monitors:read', 'servers:snooze']] as $abilities) {
        $this->postJson('/settings/api-tokens', ['name' => 'limited', 'abilities' => $abilities, 'client_id' => $client->id])
            ->assertUnprocessable()->assertJsonValidationErrors('abilities.'.(count($abilities) - 1));
    }
    expect(DB::table('personal_access_tokens')->where('name', 'limited')->exists())->toBeFalse();
    $this->postJson('/settings/api-tokens', ['name' => 'limited', 'abilities' => ['monitors:read'], 'client_id' => $client->id])->assertCreated();
    $this->postJson('/settings/api-tokens', ['name' => 'all clients', 'abilities' => ['servers:read'], 'client_id' => null])->assertCreated();
});

it('serves the OpenAPI document with the server operations only while servers are on', function (): void {
    $on = $this->getJson('/api/openapi.json')->assertOk()->json();
    expect(array_keys(array_filter($on['paths'], static fn (string $path): bool => str_starts_with($path, '/servers'), ARRAY_FILTER_USE_KEY)))
        ->toBe(['/servers', '/servers/{server}', '/servers/{server}/issues', '/servers/{server}/snooze', '/servers/{server}/unsnooze'])
        ->and(array_column($on['tags'], 'name'))->toContain('Servers')
        ->and($on['components']['schemas'])->toHaveKeys(['ServerItem', 'ServerDetail', 'ServerIssue'])
        ->and($on['components']['schemas']['MonitorResource']['properties'])->toHaveKey('server')
        ->and($on['components']['securitySchemes']['bearerAuth']['description'])->toContain('`servers:read`: listServers, getServer, listServerIssues');

    apiSwitch(false);
    $raw = $this->get('/api/openapi.json')->assertOk()->getContent();
    $off = json_decode((string) $raw, true, flags: JSON_THROW_ON_ERROR);
    expect($raw)->not->toContain('servers:')->not->toContain('#/components/schemas/Server')
        ->and(array_filter(array_keys($off['paths']), static fn (string $path): bool => str_starts_with($path, '/servers')))->toBe([])
        ->and(array_column($off['tags'], 'name'))->not->toContain('Servers')
        ->and(array_filter(array_keys($off['components']['schemas']), static fn (string $name): bool => str_starts_with($name, 'Server')))->toBe([])
        ->and($off['components']['schemas']['MonitorResource']['properties'])->not->toHaveKey('server')
        // Everything else is the committed document unchanged, empty objects included (the getIncident example's `{}`).
        ->and($off['paths'])->toBe(array_filter($on['paths'], static fn (string $path): bool => ! str_starts_with($path, '/servers'), ARRAY_FILTER_USE_KEY))
        ->and(array_column($off['tags'], 'name'))->toBe(array_values(array_diff(array_column($on['tags'], 'name'), ['Servers'])))
        ->and($raw)->toContain('"unrecorded_reasons": {}');
});

it('makes caches revalidate the served OpenAPI document, which changes with the servers switch', function (): void {
    $on = $this->get('/api/openapi.json')->assertOk()->assertHeader('Cache-Control', 'no-cache, private');
    $etag = (string) $on->headers->get('ETag');
    expect($etag)->not->toBe('');
    $this->get('/api/openapi.json', ['If-None-Match' => $etag])->assertStatus(304)->assertContent('');

    apiSwitch(false);
    $off = $this->get('/api/openapi.json', ['If-None-Match' => $etag])->assertOk();
    expect($off->headers->get('ETag'))->not->toBe($etag)->and($off->getContent())->not->toContain('/servers');
});

it('names the server a monitor runs on only for Owner and Admin tokens with servers:read while servers are on', function (): void {
    $web = apiServer('web-1');
    $client = Client::factory()->create();
    $linked = Site::factory()->for($client)->create();
    $unlinked = Site::factory()->for($client)->create();
    DB::table('server_sites')->insert(['site_id' => $linked->id, 'server_id' => $web->id, 'created_at' => now()]);
    $owner = apiUser('Owner');
    $reader = apiToken($owner, ['monitors:read', 'servers:read']);
    $without = [apiToken($owner, ['monitors:read']), storedClientToken($owner, ['monitors:read', 'servers:read'], $client->id), apiUser('Member')->createToken('legacy', ['*'])->plainTextToken];
    $server = ['id' => $web->id, 'name' => 'web-1'];

    serversApi('GET', "/api/monitors/{$linked->id}", $reader)->assertOk()->assertJsonPath('server', $server);
    serversApi('GET', "/api/monitors/{$unlinked->id}", $reader)->assertOk()->assertJsonMissingPath('server');
    $list = serversApi('GET', '/api/monitors', $reader)->assertOk();
    expect(array_column($list->json('data'), 'server', 'id'))->toBe([$linked->id => $server]);

    foreach ($without as $token) {
        serversApi('GET', "/api/monitors/{$linked->id}", $token)->assertOk()->assertJsonMissingPath('server');
        expect(serversApi('GET', '/api/monitors', $token)->assertOk()->getContent())->not->toContain('web-1');
    }
    apiSwitch(false);
    serversApi('GET', "/api/monitors/{$linked->id}", $reader)->assertOk()->assertJsonMissingPath('server');
    expect(serversApi('GET', '/api/monitors', $reader)->assertOk()->getContent())->not->toContain('web-1');
});

it('finds servers in the command palette by name, host name and IP, for Owners and Admins only, in one query', function (): void {
    $web = apiServer('web-1', ['49.12.88.204']);
    apiIssue($web, ServerIssueKind::DiskFull, '2026-11-27T06:40:00Z', scope: '/');
    apiServer('db-1', ['78.47.11.23']);
    $this->actingAs(apiUser('Admin'));

    $this->getJson('/search?q=web-1')->assertOk()->assertExactJsonStructure(['sites', 'clients', 'incidents', 'servers' => [['id', 'name', 'hostname', 'ips', 'state', 'issues', 'snoozed_until']]])
        ->assertJsonPath('servers.0', ['id' => $web->id, 'name' => 'web-1', 'hostname' => 'web-1.example.com', 'ips' => ['49.12.88.204'], 'state' => 'reporting',
            'issues' => [['kind' => 'disk_full']], 'snoozed_until' => null]);
    $this->getJson('/search?q=db-1.example')->assertOk()->assertJsonPath('servers.*.name', ['db-1']);
    $this->getJson('/search?q=49.12.88')->assertOk()->assertJsonPath('servers.*.name', ['web-1']);
    $this->getJson('/search?q=1')->assertOk()->assertJsonPath('servers.*.name', ['web-1', 'db-1']);
    // At most 10, what needs attention first: the one bulk server with an open issue leads, the rest by name.
    foreach (range(1, 11) as $n) {
        $bulk = apiServer(sprintf('bulk-%02d', $n));
        if ($n === 11) {
            apiIssue($bulk, ServerIssueKind::MemoryLow, '2026-11-27T06:00:00Z');
        }
    }
    $this->getJson('/search?q=bulk')->assertOk()->assertJsonPath('servers.*.name', ['bulk-11', ...array_map(static fn (int $n): string => sprintf('bulk-%02d', $n), range(1, 9))]);

    $serverQueries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/search?q=web')->assertOk();
        $queries = array_filter(DB::getQueryLog(), static fn (array $query): bool => preg_match('/\b(servers|server_samples|server_issues)\b/', $query['query']) === 1);
        DB::disableQueryLog();
        // The palette never reads samples.
        expect(implode(' ', array_column($queries, 'query')))->not->toContain('server_samples');

        return count($queries);
    };
    expect($serverQueries())->toBe(1);

    $this->actingAs(apiUser('Member'));
    $this->getJson('/search?q=web-1')->assertOk()->assertJsonPath('servers', []);
    expect($serverQueries())->toBe(0);
    $this->actingAs(apiUser('Owner'));
    apiSwitch(false);
    $this->getJson('/search?q=web-1')->assertOk()->assertJsonPath('servers', []);
    expect($serverQueries())->toBe(0);
});
