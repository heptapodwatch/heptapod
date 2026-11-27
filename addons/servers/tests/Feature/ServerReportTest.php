<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Addons\Servers\Server;
use Heptapod\Models\User;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Support\SessionKey;
use Tests\TestCase;

require_once __DIR__.'/../Pest.php';

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-27T07:00:00Z'));
    config(['heptapod.servers.enabled_by_server' => true]);
    $settings = app(InstanceSettings::class);
    $settings->servers_enabled = true;
    $settings->save();
});

/** Adds a server through "Add server" and returns it with the token the page shows once. */
function addReportingServer(TestCase $test, string $name = 'solitary-darkness'): array
{
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $test->actingAs($owner)->post('/servers', ['name' => $name])->assertRedirect();

    return [Server::query()->where('name', $name)->sole(), session(SessionKey::FLASH_DATA)['server_token']];
}

/** @param array<string, mixed> $overrides */
function serverSample(array $overrides = []): array
{
    return array_replace_recursive([
        'sample_id' => (string) Str::uuid(),
        'observed_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        'agent' => ['version' => '0.1.0', 'os' => 'linux', 'arch' => 'amd64', 'distro' => 'Debian 13', 'kernel' => '6.12.0-1-amd64'],
        'host' => ['hostname' => 'solitary-darkness', 'uptime_seconds' => 3542400, 'reboot_required' => true, 'ips' => ['49.12.88.204', '2a01:4f8::1']],
        'cpu' => ['cores' => 4, 'used_percent' => 23.4],
        'load' => ['1' => 1.8, '5' => 1.6, '15' => 1.4],
        'memory' => ['total_bytes' => 8589934592, 'available_bytes' => 1825361100],
        'filesystems' => [
            ['mount' => '/', 'total_bytes' => 81923145728, 'free_bytes' => 6549790720],
            ['mount' => '/var/lib/docker', 'total_bytes' => 163857235968, 'free_bytes' => 47552321536],
        ],
        'updates' => ['pending' => 7, 'security' => 3],
    ], $overrides);
}

/** As the agent sends it: a JSON body and the bearer token, no Accept header. */
function reportSample(TestCase $test, ?string $token, array $sample, string $method = 'POST', string $contentType = 'application/json'): TestResponse
{
    return $test->call($method, '/agent/v1/report', server: ['CONTENT_TYPE' => $contentType]
        + ($token === null ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$token]), content: json_encode($sample, JSON_THROW_ON_ERROR));
}

it('stores a valid sample, refreshes liveness and tells the agent its interval', function (): void {
    [$server, $token] = addReportingServer($this);
    $sample = serverSample(['unknown' => 'dropped', 'cpu' => ['vendor' => 'dropped']]);

    reportSample($this, $token, $sample)->assertStatus(202)->assertExactJson(['interval_seconds' => 60]);

    $stored = DB::table('server_samples')->where('server_id', $server->id)->sole();
    expect($stored->sample_id)->toBe($sample['sample_id'])
        ->and(CarbonImmutable::parse($stored->observed_at)->equalTo(now()))->toBeTrue()
        // Unknown keys are never stored; hostname, IPs and agent facts live on the server, the measurements in the sample.
        ->and(json_decode($stored->metrics, true))->toEqual([
            'cpu' => ['cores' => 4, 'used_percent' => 23.4], 'load' => ['1' => 1.8, '5' => 1.6, '15' => 1.4],
            'host' => ['uptime_seconds' => 3542400, 'reboot_required' => true],
            'memory' => ['total_bytes' => 8589934592, 'available_bytes' => 1825361100],
            'updates' => ['pending' => 7, 'security' => 3],
            'filesystems' => $sample['filesystems'],
        ]);
    $server->refresh();
    expect($server->last_report_at?->equalTo(now()))->toBeTrue()
        ->and([$server->agent_version, $server->agent_distro, $server->hostname, $server->ips])
        ->toBe(['0.1.0', 'Debian 13', 'solitary-darkness', ['49.12.88.204', '2a01:4f8::1']]);
});

it('answers a duplicate sample with 200 and does not count it as a new report', function (): void {
    [$server, $token] = addReportingServer($this);
    $sample = serverSample();
    reportSample($this, $token, $sample)->assertStatus(202);
    $this->travel(30)->seconds();

    reportSample($this, $token, $sample)->assertOk()->assertExactJson(['interval_seconds' => 60]);

    expect(DB::table('server_samples')->count())->toBe(1)
        ->and($server->refresh()->last_report_at?->equalTo(now()->subSeconds(30)))->toBeTrue();
});

it('rejects a missing, wrong or rotated token with 401 and a removed server with 404', function (): void {
    [$server, $token] = addReportingServer($this);
    $wrong = substr($token, 0, -1).(str_ends_with($token, 'A') ? 'B' : 'A');

    reportSample($this, null, serverSample())->assertUnauthorized()->assertExactJson(['error' => 'unauthorized']);
    reportSample($this, 'not-a-token', serverSample())->assertUnauthorized();
    reportSample($this, $wrong, serverSample())->assertUnauthorized();

    $this->post("/servers/{$server->id}/token")->assertRedirect("/servers/{$server->id}");
    $rotated = session(SessionKey::FLASH_DATA)['server_token'];
    expect($rotated)->not->toBe($token);
    reportSample($this, $token, serverSample())->assertUnauthorized();
    reportSample($this, $rotated, serverSample())->assertStatus(202);

    $this->delete("/servers/{$server->id}")->assertRedirect('/servers');
    $this->travel(21)->seconds();
    reportSample($this, $rotated, serverSample())->assertNotFound()->assertExactJson(['error' => 'not_found']);
    expect(DB::table('server_samples')->count())->toBe(0);
});

it('refuses a report whose token was rotated or whose server was removed while it was in flight', function (string $change, int $status): void {
    [$server, $token] = addReportingServer($this);
    // The change commits after the report's first token check and before its write, as a concurrent request would.
    $changed = false;
    Server::retrieved(static function (Server $retrieved) use (&$changed, $change): void {
        if ($changed) {
            return;
        }
        $changed = true;
        // Behind the loaded model's back, so the controller's first check still sees the old state.
        $row = DB::table('servers')->where('id', $retrieved->id);
        $change === 'rotated' ? $row->update(['token_hash' => hash('sha256', 'rotated')]) : $row->delete();
    });

    reportSample($this, $token, serverSample())->assertStatus($status);

    expect(DB::table('server_samples')->count())->toBe(0)
        ->and(DB::table('servers')->where('id', $server->id)->value('last_report_at'))->toBeNull();
})->with(['rotated' => ['rotated', 401], 'removed' => ['removed', 404]]);

it('rejects an oversize body with 413', function (): void {
    [, $token] = addReportingServer($this);
    $sample = serverSample(['padding' => str_repeat('x', 16 * 1024)]);

    reportSample($this, $token, $sample)->assertStatus(413)->assertExactJson(['error' => 'too_large']);
    expect(DB::table('server_samples')->count())->toBe(0);
});

it('accepts only the media type application/json, with an optional UTF-8 charset', function (string $contentType, int $status): void {
    [, $token] = addReportingServer($this);

    reportSample($this, $token, serverSample(), contentType: $contentType)->assertStatus($status);
    expect(DB::table('server_samples')->count())->toBe($status === 202 ? 1 : 0);
})->with([
    'plain' => ['application/json', 202],
    'with charset' => ['application/json; charset=utf-8', 202],
    'text' => ['text/plain', 415],
    'another JSON type' => ['application/vnd.foo+json', 415],
    'another charset' => ['application/json; charset=latin1', 415],
]);

it('decodes the raw body itself, so surrounding control characters are refused, not trimmed', function (string $body): void {
    [, $token] = addReportingServer($this);

    $this->call('POST', '/agent/v1/report', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token], content: $body)
        ->assertUnprocessable()->assertExactJson(['error' => 'invalid_report']);
    expect(DB::table('server_samples')->count())->toBe(0);
})->with([
    'tab and newline around the hostname' => [fn (): string => json_encode(serverSample(['host' => ['hostname' => "\tsolitary-darkness\n"]]), JSON_THROW_ON_ERROR)],
    'empty agent version' => [fn (): string => json_encode(serverSample(['agent' => ['version' => '']]), JSON_THROW_ON_ERROR)],
    'not JSON' => ['{"sample_id":'],
    'a JSON list' => ['[1,2,3]'],
]);

it('rejects invalid fields with a generic 422 and stores nothing', function (array $overrides): void {
    [, $token] = addReportingServer($this);

    reportSample($this, $token, serverSample($overrides))->assertUnprocessable()->assertExactJson(['error' => 'invalid_report']);
    expect(DB::table('server_samples')->count())->toBe(0);
})->with([
    'NUL in the hostname' => [['host' => ['hostname' => "solitary\0darkness"]]],
    'line break in the kernel' => [['agent' => ['kernel' => "6.12\ninjected"]]],
    'control character in a mount' => [['filesystems' => [['mount' => "/data\x1b", 'total_bytes' => 1, 'free_bytes' => 1]]]],
    'sample id not a UUID' => [['sample_id' => 'sample-1']],
    'observed_at not UTC RFC 3339' => [['observed_at' => '2026-11-27 07:00:00']],
    'CPU over 100 %' => [['cpu' => ['used_percent' => 100.1]]],
    'negative load' => [['load' => ['5' => -0.1]]],
    'bytes as a string' => [['memory' => ['total_bytes' => '8589934592']]],
    'fractional bytes' => [['memory' => ['available_bytes' => 1.5]]],
    'relative mount' => [['filesystems' => [['mount' => 'var/lib/docker']]]],
    'too many filesystems' => [['filesystems' => array_fill(0, 17, ['mount' => '/', 'total_bytes' => 1, 'free_bytes' => 1])]],
    'not an IP' => [['host' => ['ips' => ['example.com']]]],
    'kernel too long' => [['agent' => ['kernel' => str_repeat('k', 129)]]],
    'missing updates' => [['updates' => null]],
    'reboot flag as a string' => [['host' => ['reboot_required' => 'yes']]],
]);

it('accepts unknown reboot and update counts as null', function (): void {
    [, $token] = addReportingServer($this);

    reportSample($this, $token, serverSample(['host' => ['reboot_required' => null], 'updates' => ['pending' => null, 'security' => null]]))
        ->assertStatus(202);
});

it('rejects observed_at older than 10 minutes or more than 2 minutes ahead', function (int $offsetSeconds, int $status): void {
    [, $token] = addReportingServer($this);

    reportSample($this, $token, serverSample(['observed_at' => now()->addSeconds($offsetSeconds)->utc()->format('Y-m-d\TH:i:s\Z')]))
        ->assertStatus($status);
})->with([
    'stale' => [-601, 422],
    'oldest accepted' => [-600, 202],
    'newest accepted' => [120, 202],
    'future' => [121, 422],
]);

it('limits each server to one sample per 20 seconds, across a token rotation, and wrong tokens never use it up', function (): void {
    [$server, $token] = addReportingServer($this);
    $wrong = substr($token, 0, -1).(str_ends_with($token, 'A') ? 'B' : 'A');
    reportSample($this, $wrong, serverSample())->assertUnauthorized();
    reportSample($this, $token, serverSample())->assertStatus(202);

    $this->post("/servers/{$server->id}/token");
    $rotated = session(SessionKey::FLASH_DATA)['server_token'];
    $this->travel(5)->seconds();
    reportSample($this, $rotated, serverSample())->assertTooManyRequests()->assertHeader('Retry-After', '15')
        ->assertExactJson(['error' => 'too_many_reports']);

    $this->travel(15)->seconds();
    reportSample($this, $rotated, serverSample(['observed_at' => now()->utc()->format('Y-m-d\TH:i:s\Z')]))->assertStatus(202);
});

it('refuses the second of two reports sent back to back', function (): void {
    [, $token] = addReportingServer($this);

    reportSample($this, $token, serverSample())->assertStatus(202);
    reportSample($this, $token, serverSample())->assertTooManyRequests()->assertHeader('Retry-After', '20');
    expect(DB::table('server_samples')->count())->toBe(1);
});

it('limits reports per IP address before any token check', function (): void {
    foreach (range(1, 300) as $attempt) {
        reportSample($this, null, [])->assertUnauthorized();
    }

    // The framework's throttle headers stay; only the body and Cache-Control are the agent's.
    reportSample($this, null, [])->assertTooManyRequests()->assertHeader('Retry-After')->assertHeader('X-RateLimit-Limit', '300')
        ->assertHeader('Cache-Control', 'no-store, private')->assertExactJson(['error' => 'too_many_reports']);
});

it('answers a wrong method or OPTIONS with the agent JSON 404, switched on or off', function (bool $on, string $method): void {
    [, $token] = addReportingServer($this);
    config(['heptapod.servers.enabled_by_server' => $on]);

    reportSample($this, $token, serverSample(), $method)->assertNotFound()->assertExactJson(['error' => 'not_found']);
    $this->call($method, '/servers/1/token')->assertNotFound();
})->with(['on' => true, 'off' => false])->with(['GET', 'PUT', 'OPTIONS']);

it('answers 404 while servers are switched off, by the environment or by the Owner, and keeps the data', function (): void {
    [$server, $token] = addReportingServer($this);
    reportSample($this, $token, serverSample())->assertStatus(202);

    config(['heptapod.servers.enabled_by_server' => false]);
    $this->travel(21)->seconds();
    reportSample($this, $token, serverSample())->assertNotFound()->assertExactJson(['error' => 'not_found']);

    config(['heptapod.servers.enabled_by_server' => true]);
    $settings = app(InstanceSettings::class);
    $settings->servers_enabled = false;
    $settings->save();
    reportSample($this, $token, serverSample())->assertNotFound();
    expect(DB::table('server_samples')->where('server_id', $server->id)->count())->toBe(1);

    $settings->servers_enabled = true;
    $settings->save();
    reportSample($this, $token, serverSample())->assertStatus(202);
    expect(DB::table('server_samples')->where('server_id', $server->id)->count())->toBe(2);
});
