<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Addons\Dns\DnsPersistence;
use Heptapod\Addons\Dns\DnsResolverStatusException;
use Heptapod\Addons\Dns\ResetDnsBaseline;
use Heptapod\Checks\Execution\ClaimService;
use Heptapod\Checks\Execution\RunOrigin;
use Heptapod\Checks\Recording\RecordableRun;
use Heptapod\Checks\Recording\TransactionalResultRecorder;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\DnsLookupResult;
use Heptapod\Contracts\DnsResolver;
use Heptapod\Contracts\ExternalNetworkException;
use Heptapod\Contracts\ExternalTimeoutException;
use Heptapod\Contracts\RunResult;
use Heptapod\Jobs\RunCheck;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\Event;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Testing\ClaimedRun;
use Heptapod\Testing\FakeDnsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as LaravelEvent;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class);

/** @param array<string, mixed> $config */
function dnsCheck(array $config = [], string $host = 'example.com'): Check
{
    $site = Site::factory()->create(['url' => 'https://'.$host]);
    $schema = app(CheckRegistry::class)->get(CheckKey::Dns)->definition()->configSchema;

    return Check::factory()->create(['site_id' => $site->id, 'type' => CheckKey::Dns, 'config' => $schema->validateConfig($config)]);
}

function dnsRun(Check $check): CheckRun
{
    Queue::fake();
    expect(app(ClaimService::class)->runNow($check->id, RunOrigin::System))->not->toBeNull();
    $definition = app(CheckRegistry::class)->get(CheckKey::Dns)->definition();
    $job = new RunCheck($check->id, $check->refresh()->claim_token, $definition->timeout, $definition->queue);
    app()->call($job->handle(...));
    app()->call($job->handle(...));

    return $check->runs()->orderByDesc('ended_at')->orderByDesc('id')->firstOrFail();
}

/**
 * The PATH dig fixture answers locally and never opens a socket, so these cases bypass the fixture-only host allowlist.
 * It models the OS resolver for queries without a server, so an ambient DNS_RESOLVER must not name one.
 */
function useDigFixtureProcess(): void
{
    app()->forgetExtenders(DnsResolver::class);
    app()->forgetInstance(DnsResolver::class);
    config(['dns.resolver' => null]);
}

/** @return array{host: string, ttl: int, class: string, type: string, ip: string} */
function dnsA(string $ip = '192.0.2.1', string $host = 'example.com', int $ttl = 300): array
{
    return ['host' => $host, 'ttl' => $ttl, 'class' => 'IN', 'type' => 'A', 'ip' => $ip];
}

/** @param list<array<string, mixed>> $records */
function dnsReplies(FakeDnsResolver $fake, array $records, string $host = 'example.com', ?string $nameserver = null): void
{
    $zone = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    $server = $nameserver ?? 'ns.fixture.example';
    if ($nameserver === null) {
        $zone = $host;
        while (str_contains($zone, '.')) {
            $zone = substr($zone, strpos($zone, '.') + 1);
            $fake->respondWith($zone, 'NS', new DnsLookupResult([], [], null));
        }
        // Baseline tests need an established authority, not recursive-only data.
        $fake->respondWith($zone, 'NS', new DnsLookupResult([
            ['host' => $zone, 'ttl' => 300, 'class' => 'IN', 'type' => 'NS', 'target' => $server],
        ], [], null));
    }
    $fake->respondWith($zone, 'SOA', new DnsLookupResult([
        ['host' => $zone, 'ttl' => 300, 'class' => 'IN', 'type' => 'SOA', 'serial' => 1],
    ], [], $server), $server);
    foreach (['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'CAA'] as $type) {
        $answer = array_values(array_filter($records, fn (array $record): bool => $record['type'] === $type));
        $fake->respondWith($host, $type, new DnsLookupResult($answer, [$host.' '.$type.' fixture'], $nameserver), $nameserver);
        if ($nameserver === null) {
            $fake->respondWith($host, $type, new DnsLookupResult($answer, [$host.' '.$type.' fixture'], $server), $server);
        }
    }
}

it('records DNS changes once from accepted baselines while order and TTL do not change records', function (): void {
    $registry = app(CheckRegistry::class);
    $registry->get(CheckKey::Dns);
    $fake = new FakeDnsResolver;
    app()->instance(DnsResolver::class, $fake);
    $check = dnsCheck();
    dnsReplies($fake, [dnsA(), dnsA('192.0.2.2')]);

    expect(dnsRun($check)->result)->toBe(RunResult::Succeeded);
    expect(DB::table('dns_history_items')->count())->toBe(1);
    expect(Event::query()->where('type', 'dns.records_changed')->count())->toBe(0);
    dnsReplies($fake, [dnsA('192.0.2.2', 'EXAMPLE.COM.', 1), dnsA('192.0.2.1', 'example.com', 999)]);
    expect(dnsRun($check)->result)->toBe(RunResult::Succeeded);
    expect(DB::table('dns_history_items')->count())->toBe(1);

    dnsReplies($fake, [dnsA('192.0.2.2'), dnsA('192.0.2.3')]);
    $changed = dnsRun($check);
    expect($changed->result)->toBe(RunResult::Warning);
    expect(DB::table('dns_history_items')->orderByDesc('id')->value('diff_summary'))->toBe('A: +1 −1');
    $event = Event::query()->where('type', 'dns.records_changed')->sole();
    // The recorder adds the run identity and summary to every addon event payload.
    expect($event->payload)->toEqual(['changes' => ['A' => ['added' => [['host' => 'example.com', 'class' => 'IN', 'type' => 'A', 'ip' => '192.0.2.3']], 'removed' => [['host' => 'example.com', 'class' => 'IN', 'type' => 'A', 'ip' => '192.0.2.1']]]],
        'summary' => 'dns.changed', 'summary_params' => [], 'run_id' => $changed->id, 'run_ended_at' => $changed->ended_at->format('Y-m-d\TH:i:s.uP')]);
    dnsReplies($fake, [dnsA('192.0.2.3'), dnsA('192.0.2.4')]);
    expect(dnsRun($check)->result)->toBe(RunResult::Warning);
    expect(dnsRun($check)->result)->toBe(RunResult::Warning);
    expect(Event::query()->where('type', 'dns.records_changed')->count())->toBe(1);
    expect(DB::table('dns_history_items')->count())->toBe(3);
    dnsReplies($fake, [dnsA(), dnsA('192.0.2.2')]);
    expect(dnsRun($check)->result)->toBe(RunResult::Succeeded);
    dnsReplies($fake, [dnsA('192.0.2.2'), dnsA('192.0.2.3')]);
    expect(dnsRun($check)->result)->toBe(RunResult::Warning);
    expect(Event::query()->where('type', 'dns.records_changed')->count())->toBe(2);
    app(DnsPersistence::class)->prune(CarbonImmutable::now('UTC')->addSecond());
    dnsReplies($fake, [dnsA('192.0.2.4'), dnsA('192.0.2.5')]);
    expect(dnsRun($check)->result)->toBe(RunResult::Warning);
    expect(Event::query()->where('type', 'dns.records_changed')->count())->toBe(2);
});

it('accepts the current DNS observation with an audited actor and queues a real recheck', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-04T00:30:00Z'));
    $fake = new FakeDnsResolver;
    app()->instance(DnsResolver::class, $fake);
    $check = dnsCheck();
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $member = User::factory()->create();
    $member->assignRole('Member');
    $url = '/sites/'.$check->site_id.'/checks/'.$check->id;
    dnsReplies($fake, [dnsA()]);
    $initial = dnsRun($check);
    expect(DB::table('dns_baselines')->where('check_id', $check->id)->value('run_id'))->toBe($initial->id)
        ->and(DB::table('dns_baselines')->where('check_id', $check->id)->value('accepted_at'))->toBeNull();
    dnsReplies($fake, [dnsA('192.0.2.2')]);
    $change = dnsRun($check);
    expect($change->result)->toBe(RunResult::Warning);
    expect(dnsRun($check)->result)->toBe(RunResult::Warning);
    dnsReplies($fake, [dnsA('192.0.2.3')]);
    $another = dnsRun($check);
    expect($another->result)->toBe(RunResult::Warning)
        ->and(DB::table('dns_baselines')->where('check_id', $check->id)->value('run_id'))->toBe($initial->id)
        ->and(DB::table('dns_history_items')->where('check_id', $check->id)->count())->toBe(3);
    $this->actingAs($member)->post($url.'/accept-dns', ['accepted_run_id' => $another->id])->assertForbidden();
    $this->actingAs($owner)->post('/sites/'.$check->site_id.'/checks/'.dnsCheck([], 'other.example.com')->id.'/accept-dns', ['accepted_run_id' => $another->id])->assertNotFound();
    $this->post($url.'/accept-dns')->assertSessionHasErrors('accepted_run_id');
    $this->post($url.'/accept-dns', ['accepted_run_id' => $change->id])->assertSessionHasErrors([
        'accepted_run_id' => __('There are newer DNS results — please review again.'),
    ]);
    expect(DB::table('dns_baselines')->where('check_id', $check->id)->value('run_id'))->toBe($initial->id)
        ->and(DB::table('activity_log')->where('event', 'dns.snapshot_accepted')->count())->toBe(0);
    $this->post($url.'/accept-dns', ['accepted_run_id' => $another->id])->assertRedirect($url);
    Queue::assertPushed(RunCheck::class);
    $pending = $this->get($url)->assertOk()->inertiaProps()['history'];
    expect($this->get($url)->inertiaProps()['check']['pending_recheck'])->toBeTrue()
        ->and($pending['accepted_run_id'])->toBe($another->id)
        ->and($pending['accepted_at'])->toBe(now('UTC')->toIso8601String())
        ->and($pending['timeline'][0]['observed_at'])->toBe($another->ended_at->toIso8601String())
        ->and($pending['accepted_by'])->toBe($owner->name)
        ->and($pending['changes'])->toBe([])
        ->and(DB::table('activity_log')->where('event', 'dns.snapshot_accepted')->where('causer_id', $owner->id)->value('properties'))->toContain((string) $another->id);
    $definition = app(CheckRegistry::class)->get(CheckKey::Dns)->definition();
    $job = new RunCheck($check->id, $check->refresh()->claim_token, $definition->timeout, $definition->queue);
    app()->call($job->handle(...));
    expect($check->refresh()->latest_run_result->value)->toBe('succeeded')
        ->and($this->get($url)->inertiaProps()['check']['pending_recheck'])->toBeFalse();

    dnsReplies($fake, [dnsA('192.0.2.4')]);
    $newChange = dnsRun($check);
    expect($newChange->result)->toBe(RunResult::Warning);
    $this->post($url.'/accept-dns', ['accepted_run_id' => $newChange->id])->assertRedirect($url);
    dnsReplies($fake, [dnsA('192.0.2.5')]);
    $job = new RunCheck($check->id, $check->refresh()->claim_token, $definition->timeout, $definition->queue);
    app()->call($job->handle(...));
    // Accepting starts a new warning episode, so a recheck that differs from the accepted records alerts again.
    expect($check->refresh()->latest_run_result->value)->toBe('warning')
        ->and(Event::query()->where('type', 'dns.records_changed')->count())->toBe(3);
});

it('does not accept a DNS snapshot when no observation or recheck slot exists', function (): void {
    $fake = new FakeDnsResolver;
    app()->instance(DnsResolver::class, $fake);
    $check = dnsCheck();
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $url = '/sites/'.$check->site_id.'/checks/'.$check->id.'/accept-dns';
    $this->actingAs($owner)->post($url, ['accepted_run_id' => 1])->assertSessionHasErrors('check');
    expect(DB::table('dns_baselines')->where('check_id', $check->id)->count())->toBe(0);

    dnsReplies($fake, [dnsA()]);
    $initial = dnsRun($check);
    dnsReplies($fake, [dnsA('192.0.2.2')]);
    $change = dnsRun($check);
    $check->update(['enabled' => false]);
    $this->post($url, ['accepted_run_id' => $change->id])->assertSessionHasErrors('check');
    expect(DB::table('dns_baselines')->where('check_id', $check->id)->value('run_id'))->toBe($initial->id)
        ->and(DB::table('dns_baselines')->where('check_id', $check->id)->value('accepted_pending_recheck'))->toBeFalse()
        ->and(DB::table('activity_log')->where('event', 'dns.snapshot_accepted')->count())->toBe(0);
});

it('treats existing rolling baselines as accepted during schema upgrade', function (): void {
    $fake = new FakeDnsResolver;
    app()->instance(DnsResolver::class, $fake);
    $check = dnsCheck();
    dnsReplies($fake, [dnsA()]);
    $initial = dnsRun($check);
    $migration = require __DIR__.'/../../database/migrations/2026_10_04_120000_add_accepted_dns_baseline_metadata.php';
    $migration->down();
    $migration->up();

    expect(DB::table('dns_baselines')->where('check_id', $check->id)->value('run_id'))->toBe($initial->id)
        ->and(DB::table('dns_baselines')->where('check_id', $check->id)->value('accepted_pending_recheck'))->toBeFalse();
    expect(dnsRun($check)->result)->toBe(RunResult::Succeeded);
    dnsReplies($fake, [dnsA('192.0.2.2')]);
    expect(dnsRun($check)->result)->toBe(RunResult::Warning);
});

it('keeps missing types failed until reset or an accepted ignored-type edit in auto-accept mode', function (): void {
    $fake = new FakeDnsResolver;
    app()->instance(DnsResolver::class, $fake);
    $check = dnsCheck(['accept_changes_automatically' => true]);
    dnsReplies($fake, [dnsA()]);
    dnsRun($check);
    $mx = ['host' => 'example.com', 'ttl' => 300, 'class' => 'IN', 'type' => 'MX', 'pri' => 10, 'target' => 'mail.example.com'];
    dnsReplies($fake, [dnsA(), $mx]);
    expect(dnsRun($check)->result)->toBe(RunResult::Warning);
    dnsReplies($fake, [dnsA()]);
    expect(dnsRun($check)->result)->toBe(RunResult::Failed);
    expect(dnsRun($check)->result)->toBe(RunResult::Failed);
    expect(Event::query()->where('type', 'dns.records_changed')->count())->toBe(2);

    $schema = app(CheckRegistry::class)->get(CheckKey::Dns)->definition()->configSchema;
    $check->update(['config' => $schema->validateConfig(['ignored_record_types' => ['MX'], 'accept_changes_automatically' => true])]);
    expect(dnsRun($check)->result)->toBe(RunResult::Succeeded);
    $check->update(['config' => $schema->validateConfig(['accept_changes_automatically' => true])]);
    expect(dnsRun($check)->result)->toBe(RunResult::Succeeded);
    dnsReplies($fake, [dnsA(), $mx]);
    dnsRun($check);
    dnsReplies($fake, [dnsA()]);
    expect(dnsRun($check)->result)->toBe(RunResult::Failed);
    app(ResetDnsBaseline::class)($check->id);
    expect(dnsRun($check)->result)->toBe(RunResult::Succeeded);
});

it('applies baseline resets to the next poll even when a pre-reset observation finalizes later', function (): void {
    $fake = new FakeDnsResolver;
    app()->instance(DnsResolver::class, $fake);
    $check = dnsCheck();
    $mx = ['host' => 'example.com', 'ttl' => 300, 'class' => 'IN', 'type' => 'MX', 'pri' => 10, 'target' => 'mail.example.com'];
    dnsReplies($fake, [dnsA(), $mx]);
    dnsRun($check);
    dnsReplies($fake, [dnsA()]);
    $at = CarbonImmutable::now();
    $context = ClaimedRun::start($check, $at);
    $type = app(CheckRegistry::class)->get(CheckKey::Dns);
    $outcome = $type->execute($context);
    expect($outcome->result)->toBe(RunResult::Failed)->and($outcome->events)->toHaveCount(1);

    app(ResetDnsBaseline::class)($check->id);
    $accepted = app(TransactionalResultRecorder::class)->record(new RecordableRun($check->id, $context->claimToken, $at, CarbonImmutable::now(), $outcome));
    expect($accepted->accepted)->toBeTrue();
    // The in-flight outcome remains valid; reset affects the next evaluation, not this event.
    expect(Event::query()->where('type', 'dns.records_changed')->count())->toBe(1);
    $next = dnsRun($check);
    expect($next->result)->toBe(RunResult::Succeeded);
    expect(Event::query()->where('type', 'dns.records_changed')->count())->toBe(1);
    $history = DB::table('dns_history_items')->where('run_id', $next->id)->sole();
    expect($history->initial)->toBeTrue()->and(json_decode($history->diff, true, flags: JSON_THROW_ON_ERROR))->toBe([]);
    expect(dnsRun($check)->result)->toBe(RunResult::Succeeded);
    expect(DB::table('dns_history_items')->count())->toBe(2);
    expect(Event::query()->where('type', 'dns.records_changed')->count())->toBe(1);
});

it('records resolver errors without removed events and retains the baseline across pruning', function (string $exception): void {
    $fake = new FakeDnsResolver;
    app()->instance(DnsResolver::class, $fake);
    $check = dnsCheck();
    dnsReplies($fake, [dnsA()]);
    $first = dnsRun($check);
    $fake->respondWith('example.com', 'A', new $exception('DNS unavailable', previous: new RuntimeException('transport cause')));
    $fake->respondWith('example.com', 'A', new $exception('DNS unavailable', previous: new RuntimeException('transport cause')), 'ns.fixture.example');
    $error = dnsRun($check);
    expect($error->result)->toBe(RunResult::Errored)->and($error->metrics)->toBeNull();
    expect(DB::table('dns_history_items')->count())->toBe(1);
    expect(Event::query()->where('type', 'dns.records_changed')->count())->toBe(0);
    $store = app(CheckRegistry::class)->get(CheckKey::Dns)->persistence();
    $store->prune($first->ended_at->addSecond());
    dnsReplies($fake, [dnsA()]);
    expect(dnsRun($check)->result)->toBe(RunResult::Succeeded);
    expect(DB::table('dns_history_items')->count())->toBe(0);
    $store->prune(CarbonImmutable::now()->addSecond());
    dnsReplies($fake, [dnsA('192.0.2.2')]);
    expect(dnsRun($check)->result)->toBe(RunResult::Warning);
    $history = DB::table('dns_history_items')->sole();
    expect($history->initial)->toBeFalse()
        ->and(json_decode($history->diff, true, flags: JSON_THROW_ON_ERROR)['A']['added'][0]['ip'])->toBe('192.0.2.2')
        ->and(Event::query()->where('type', 'dns.records_changed')->count())->toBe(1);
})->with([ExternalTimeoutException::class, ExternalNetworkException::class]);

it('always checks liveness even when all address record types are ignored', function (bool $resolves, RunResult $expected): void {
    $fake = new FakeDnsResolver;
    app()->instance(DnsResolver::class, $fake);
    $check = dnsCheck(['ignored_record_types' => ['A', 'AAAA', 'CNAME']]);
    dnsReplies($fake, $resolves ? [dnsA()] : []);
    expect(dnsRun($check)->result)->toBe($expected);
    expect(Event::query()->where('type', 'dns.records_changed')->count())->toBe(0);
})->with([[true, RunResult::Succeeded], [false, RunResult::Failed]]);

it('watches the URL hostname, PSL registrable domain and extra hostnames with the same types', function (): void {
    $fake = new FakeDnsResolver;
    app()->instance(DnsResolver::class, $fake);
    $check = dnsCheck(['monitor_main_domain' => true, 'extra_cnames' => ['other.example.co.uk']], 'www.example.co.uk');
    foreach (['www.example.co.uk', 'example.co.uk', 'other.example.co.uk'] as $host) {
        dnsReplies($fake, [dnsA(host: $host)], $host);
    }
    expect(dnsRun($check)->metrics['record_count'])->toBe(3);
    dnsReplies($fake, [dnsA(host: 'other.example.co.uk'), ['host' => 'other.example.co.uk', 'ttl' => 300, 'class' => 'IN', 'type' => 'TXT', 'txt' => 'new SPF']], 'other.example.co.uk');
    expect(dnsRun($check)->result)->toBe(RunResult::Warning);
    expect(Event::query()->where('type', 'dns.records_changed')->sole()->payload['changes']['TXT']['added'][0]['txt'])->toBe('new SPF');
});

it('compares stable records across authoritative nameservers rather than geo addresses', function (): void {
    $fake = new FakeDnsResolver;
    app()->instance(DnsResolver::class, $fake);
    $check = dnsCheck(['check_nameservers_in_sync' => true, 'accept_changes_automatically' => true], 'www.example.com');
    dnsReplies($fake, [dnsA(host: 'www.example.com')], 'www.example.com');
    $servers = array_map(fn (string $server): array => ['host' => 'example.com', 'ttl' => 300, 'class' => 'IN', 'type' => 'NS', 'target' => $server], ['ns1.example.com', 'ns2.example.com']);
    $fake->respondWith('example.com', 'NS', new DnsLookupResult($servers, [], null));
    dnsReplies($fake, [dnsA(host: 'www.example.com')], 'www.example.com', 'ns1.example.com');
    dnsReplies($fake, [dnsA(host: 'WWW.EXAMPLE.COM.', ttl: 2)], 'www.example.com', 'ns2.example.com');
    expect(dnsRun($check)->result)->toBe(RunResult::Succeeded);
    dnsReplies($fake, [dnsA('192.0.2.2', 'www.example.com')], 'www.example.com', 'ns2.example.com');
    expect(dnsRun($check)->result)->toBe(RunResult::Warning);
    $mx = ['host' => 'www.example.com', 'ttl' => 300, 'class' => 'IN', 'type' => 'MX', 'pri' => 10, 'target' => 'mail.example.com'];
    dnsReplies($fake, [dnsA('192.0.2.2', 'www.example.com'), $mx], 'www.example.com', 'ns2.example.com');
    expect(dnsRun($check)->result)->toBe(RunResult::Failed);
    expect(DB::table('dns_baselines')->value('snapshot'))->toContain('dns.issue.nameservers_out_of_sync');
    $servers[] = ['host' => 'example.com', 'ttl' => 300, 'class' => 'IN', 'type' => 'NS', 'target' => 'ns3.example.com'];
    $fake->respondWith('example.com', 'NS', new DnsLookupResult($servers, [], null));
    $fake->respondWith('www.example.com', 'A', new ExternalTimeoutException('third NS unavailable'), 'ns3.example.com');
    $run = dnsRun($check);
    expect($run->result)->toBe(RunResult::Failed);
    expect(array_column($run->evidence['issues'], 'type'))->toBe(['dns.issue.nameservers_out_of_sync', 'dns.issue.nameserver_unreachable']);
});

it('rejects a stale DNS observation without publishing its baseline or change event', function (): void {
    $fake = new FakeDnsResolver;
    app()->instance(DnsResolver::class, $fake);
    $check = dnsCheck();
    dnsReplies($fake, [dnsA()]);
    dnsRun($check);
    $old = app(DnsPersistence::class)->baseline($check->id);
    dnsReplies($fake, [dnsA('192.0.2.2')]);
    $at = CarbonImmutable::now();
    $context = new CheckContext($check->site_id, $check->id, (int) DB::scalar("SELECT nextval('check_runs_id_seq')"), (string) Str::uuid(), $check->site->url, $at, $at->addSeconds(120), [], [], null);
    $type = app(CheckRegistry::class)->get(CheckKey::Dns);
    $outcome = $type->execute($context);
    $again = $type->execute($context);
    expect($outcome->events)->toHaveCount(1)->and($again->events[0]->dedupeKey)->toBe($outcome->events[0]->dedupeKey);
    $check->update(['claim_token' => (string) Str::uuid(), 'active_run_id' => $context->runId, 'run_started_at' => $context->startedAt]);
    $accepted = app(TransactionalResultRecorder::class)->record(new RecordableRun($check->id, $context->claimToken, $at, $at->addSecond(), $outcome));
    $type->persistence()->discard($context->runId, $context->claimToken);
    expect($accepted->accepted)->toBeFalse();
    expect(app(DnsPersistence::class)->baseline($check->id))->toBe($old);
    expect(DB::table('dns_history_items')->count())->toBe(1);
    expect(Event::query()->where('type', 'dns.records_changed')->count())->toBe(0);
});

it('normalizes IPv6 text and bounds public TXT event content', function (): void {
    $fake = new FakeDnsResolver;
    app()->instance(DnsResolver::class, $fake);
    $check = dnsCheck();
    $ipv6 = ['host' => 'example.com', 'ttl' => 300, 'class' => 'IN', 'type' => 'AAAA', 'ipv6' => '2001:db8::1'];
    $txt = ['host' => 'example.com', 'ttl' => 300, 'class' => 'IN', 'type' => 'TXT', 'txt' => 'v=spf1 -all'];
    dnsReplies($fake, [dnsA(), $ipv6, $txt]);
    dnsRun($check);
    dnsReplies($fake, [dnsA(), [...$ipv6, 'ipv6' => '2001:0DB8:0000:0000:0000:0000:0000:0001'], $txt]);
    expect(dnsRun($check)->result)->toBe(RunResult::Succeeded);
    dnsReplies($fake, [dnsA(), $ipv6, [...$txt, 'txt' => str_repeat('x', 300)]]);
    expect(dnsRun($check)->result)->toBe(RunResult::Warning);
    $value = Event::query()->where('type', 'dns.records_changed')->sole()->payload['changes']['TXT']['added'][0]['txt'];
    expect(mb_strlen($value))->toBe(255)->and($value)->toEndWith('...');
    $row = DB::table('dns_history_items')->orderByDesc('id')->first();
    expect($row->dns_records)->toContain(str_repeat('x', 300));
});

it('stops before the next query when the overall DNS deadline cannot cover its transport cap', function (): void {
    app()->instance(DnsResolver::class, new FakeDnsResolver);
    $check = dnsCheck();
    $at = CarbonImmutable::now();
    $context = new CheckContext($check->site_id, $check->id, 1, (string) Str::uuid(), $check->site->url, $at, $at->addSeconds(4), [], [], null);
    expect(fn () => app(CheckRegistry::class)->get(CheckKey::Dns)->execute($context))->toThrow(ExternalTimeoutException::class, 'DNS check deadline exhausted.');
    expect(DB::table('dns_observations')->count())->toBe(0);
    expect(DB::table('dns_baselines')->count())->toBe(0);
});

it('records malformed seam answers as errors rather than persisting partial removal', function (bool $authorities, string $source): void {
    $fake = new FakeDnsResolver;
    app()->instance(DnsResolver::class, $fake);
    $check = dnsCheck();
    $servers = $authorities ? array_map(fn (string $server): array => ['host' => 'example.com', 'ttl' => 300, 'class' => 'IN', 'type' => 'NS', 'target' => $server], ['ns1.example.com', 'ns2.example.com']) : [];
    dnsReplies($fake, [dnsA(), ...$servers]);
    foreach ($servers as $server) {
        dnsReplies($fake, [dnsA(), ...$servers], nameserver: $server['target']);
    }
    dnsRun($check);
    $baseline = app(DnsPersistence::class)->baseline($check->id);
    if (! $authorities) {
        $fake->respondWith('com', 'NS', new DnsLookupResult([], [], null));
    }
    $server = $source === 'discarded authority' ? $servers[0]['target'] : null;
    $fake->respondWith('example.com', 'A', new DnsLookupResult([dnsA('not an IP')], [], $server), $server);
    if ($source !== 'recursive') {
        $fake->respondWith('example.com', 'AAAA', new ExternalTimeoutException('DNS resolver did not respond.'), $server);
    }
    $run = dnsRun($check);
    expect($run->result)->toBe(RunResult::Errored);
    expect(json_decode($run->error, true, flags: JSON_THROW_ON_ERROR)['class'])->toBe(ValidationException::class);
    expect(app(DnsPersistence::class)->baseline($check->id))->toBe($baseline);
    expect(DB::table('dns_history_items')->count())->toBe(1);
    expect(Event::query()->where('type', 'dns.records_changed')->count())->toBe(0);
})->with([
    'no authorities' => [false, 'recursive'],
    'complete authorities' => [true, 'recursive'],
    'before recursive discard' => [true, 'discarded recursive'],
    'before authoritative discard' => [true, 'discarded authority'],
]);

it('rejects invalid DNS configuration at the schema boundary', function (array $config, string $field): void {
    try {
        dnsCheck($config);
        $this->fail('Invalid DNS config accepted');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('values.'.$field);
    }
})->with([
    [['ignored_record_types' => ['TYPO']], 'ignored_record_types.0'],
    [['extra_cnames' => ['https://example.com']], 'extra_cnames.0'],
    [['monitor_main_domain' => 'true'], 'monitor_main_domain'],
    [['check_nameservers_in_sync' => 1], 'check_nameservers_in_sync'],
]);

it('treats native SERVFAIL as an error and NXDOMAIN as a negative observation', function (string $host, RunResult $expected): void {
    config(['dns.resolver' => getenv('DNS_FIXTURE_SERVER') ?: 'dns-fixtures']);
    $check = dnsCheck(host: $host);
    $run = dnsRun($check);
    expect($run->result)->toBe($expected);
    if ($expected === RunResult::Errored) {
        expect(DB::table('dns_baselines')->count())->toBe(0);
        expect($run->error)->toContain('SERVFAIL');
        expect(json_decode($run->error, true, flags: JSON_THROW_ON_ERROR)['class'])->toBe(DnsResolverStatusException::class);
    } else {
        expect(DB::table('dns_baselines')->count())->toBe(1);
    }
    expect(Event::query()->where('type', 'dns.records_changed')->count())->toBe(0);
})->with([
    'resolver failed' => ['servfail.dns.fixture.test', RunResult::Errored],
    'no such hostname' => ['missing.dns.fixture.test', RunResult::Failed],
]);

it('checks the enclosing zone authorities for a native cross-zone CNAME', function (): void {
    config(['dns.resolver' => getenv('DNS_FIXTURE_SERVER') ?: 'dns-fixtures']);
    $check = dnsCheck(['check_nameservers_in_sync' => true], 'www.example.test');
    $run = dnsRun($check);
    expect($run->result)->toBe(RunResult::Succeeded);
    $row = DB::table('dns_history_items')->sole();
    expect(json_decode($row->authoritative_nameservers, true, flags: JSON_THROW_ON_ERROR))->toBe(['ns.dns.fixture.test']);
    expect($row->dns_records)->toContain('provider.test', 'CNAME');
});

it('bounds dig output during transport and sanitizes process and parser diagnostics', function (string $scenario, string $reason): void {
    $environment = $_ENV;
    $path = getenv('PATH');
    $previousScenario = getenv('DNS_PROCESS_SCENARIO');
    $fixturePath = base_path('addons/dns/tests/Support').':'.$path;
    useDigFixtureProcess();
    putenv('PATH='.$fixturePath);
    putenv('DNS_PROCESS_SCENARIO='.$scenario);
    $_ENV['PATH'] = $fixturePath;
    $_ENV['DNS_PROCESS_SCENARIO'] = $scenario;
    $logs = [];
    LaravelEvent::listen(MessageLogged::class, function (MessageLogged $message) use (&$logs): void {
        $logs[] = [$message->message, $message->context];
    });
    try {
        $check = dnsCheck();
        $run = dnsRun($check);
        expect($run->result)->toBe(RunResult::Errored)->and($run->metrics)->toBeNull();
        expect(json_decode($run->error, true, flags: JSON_THROW_ON_ERROR)['message'])->toBe($reason);
        expect($run->error)->not->toContain('untrusted-dns-token', 'example.invalid');
        expect(json_encode($logs, JSON_THROW_ON_ERROR))->not->toContain('untrusted-dns-token', 'example.invalid');
        expect(DB::table('dns_observations')->count())->toBe(0);
        expect(DB::table('dns_baselines')->count())->toBe(0);
        expect(DB::table('dns_history_items')->count())->toBe(0);
    } finally {
        $_ENV = $environment;
        putenv($path === false ? 'PATH' : 'PATH='.$path);
        putenv($previousScenario === false ? 'DNS_PROCESS_SCENARIO' : 'DNS_PROCESS_SCENARIO='.$previousScenario);
    }
})->with([
    'stdout overflow' => ['oversized-stdout', 'DNS resolver output exceeded 128 KiB.'],
    'stderr overflow' => ['oversized-stderr', 'DNS resolver output exceeded 128 KiB.'],
    'unsafe process failure' => ['process-error', 'DNS query failed.'],
    'unsafe parser failure' => ['parser-error', 'Malformed DNS answer.'],
    'truncated SERVFAIL' => ['truncated-servfail', 'DNS response was truncated.'],
]);

it('rejects a truncated native DNS reply instead of accepting partial records', function (): void {
    config(['dns.resolver' => getenv('DNS_FIXTURE_SERVER') ?: 'dns-fixtures']);
    $check = dnsCheck(host: 'oversize.dns.fixture.test');
    $run = dnsRun($check);
    expect($run->result)->toBe(RunResult::Errored);
    expect($run->error)->toContain('DNS response was truncated.');
    expect(DB::table('dns_baselines')->count())->toBe(0);
    expect(Event::query()->where('type', 'dns.records_changed')->count())->toBe(0);
});

it('fetches every supported DNS type from the offline authoritative server through real execution', function (): void {
    $server = getenv('DNS_FIXTURE_SERVER') ?: 'dns-fixtures';
    config(['dns.resolver' => $server]);
    $check = dnsCheck(['check_nameservers_in_sync' => true, 'extra_cnames' => ['alias.dns.fixture.test']], 'dns.fixture.test');
    $run = dnsRun($check);
    expect($run->result)->toBe(RunResult::Succeeded)->and($run->metrics['record_count'])->toBe(12);
    $row = DB::table('dns_history_items')->sole();
    $records = json_decode($row->dns_records, true, flags: JSON_THROW_ON_ERROR);
    expect(array_column($records, 'type'))->toContain('A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'CAA');
    expect($row->raw_dns_records)->toContain('v=spf1', 'issue', '2001:db8::1');
    expect(json_decode($row->authoritative_nameservers, true, flags: JSON_THROW_ON_ERROR))->toBe(['ns.dns.fixture.test']);
});

it('keeps real-shaped anycast authorities in sync and their address union stable across rotations', function (bool $sync): void {
    $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/parity/anycast.json'), true, flags: JSON_THROW_ON_ERROR);
    $fake = new FakeDnsResolver;
    app()->instance(DnsResolver::class, $fake);
    $check = dnsCheck(['check_nameservers_in_sync' => $sync], $fixture['host']);
    dnsReplies($fake, array_merge(...array_values($fixture['recursive'])), $fixture['host']);
    foreach ($fixture['authoritative'] as $source) {
        dnsReplies($fake, array_merge(...array_values($source['answers'])), $fixture['host'], $source['nameserver']);
        $fake->respondWith($fixture['zone'], 'SOA', new DnsLookupResult($source['soa'], [], $source['nameserver']), $source['nameserver']);
    }
    expect(dnsRun($check)->result)->toBe(RunResult::Succeeded);
    foreach ($fixture['authoritative'] as $index => $source) {
        foreach (['A', 'AAAA'] as $type) {
            $records = array_reverse($fixture['authoritative'][1 - $index]['answers'][$type]);
            foreach ($records as &$record) {
                $record['ttl'] = 1;
            }
            unset($record);
            $fake->respondWith($fixture['host'], $type, new DnsLookupResult($records, [], $source['nameserver']), $source['nameserver']);
        }
    }
    // The recursive answer also rotates; it must not own address-change identity.
    dnsReplies($fake, array_merge(...array_values($fixture['authoritative'][1]['answers'])), $fixture['host']);
    expect(dnsRun($check)->result)->toBe(RunResult::Succeeded);
    expect(Event::query()->where('type', 'dns.records_changed')->count())->toBe(0);
    expect(DB::table('dns_history_items')->count())->toBe(1);
    if ($sync) {
        $source = $fixture['authoritative'][1];
        $source['soa'][0]['serial']++;
        $fake->respondWith($fixture['zone'], 'SOA', new DnsLookupResult($source['soa'], [], $source['nameserver']), $source['nameserver']);
        expect(dnsRun($check)->result)->toBe(RunResult::Failed);
        expect(CheckRun::query()->orderByDesc('id')->firstOrFail()->evidence['issues'][0]['type'])->toBe('dns.issue.nameservers_out_of_sync');
    }
})->with([true, false]);

it('uses complete authorities for real-shaped recursive failures and fences degraded address baselines', function (string $reason): void {
    $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/parity/recursive-timeout.json'), true, flags: JSON_THROW_ON_ERROR);
    $fake = new FakeDnsResolver;
    app()->instance(DnsResolver::class, $fake);
    $check = dnsCheck(['check_nameservers_in_sync' => true], $fixture['host']);
    dnsReplies($fake, [], $fixture['host']);
    $failure = $reason === 'timeout' ? new ExternalTimeoutException('DNS resolver did not respond.') : new DnsResolverStatusException('SERVFAIL');
    foreach ($fixture['recursive'] as $type => $source) {
        $fake->respondWith($fixture['host'], $type, isset($source['error'])
            ? $failure : new DnsLookupResult($source, [], null));
    }
    $fake->respondWith($fixture['zone'], 'NS', new DnsLookupResult($fixture['zone_ns'], [], null));
    foreach ($fixture['authoritative'] as $source) {
        dnsReplies($fake, array_merge(...array_values($source['answers'])), $fixture['host'], $source['nameserver']);
        $fake->respondWith($fixture['zone'], 'SOA', new DnsLookupResult($source['soa'], [], $source['nameserver']), $source['nameserver']);
    }
    $run = dnsRun($check);
    expect($run->result)->toBe(RunResult::Succeeded);
    expect($run->evidence['diagnostics'])->toEqual([['type' => 'dns.resolver_unavailable', 'host' => $fixture['host'], 'reason' => $reason]]);
    $baseline = app(DnsPersistence::class)->baseline($check->id);
    $server = $fixture['authoritative'][0]['nameserver'];
    $fake->respondWith($fixture['host'], 'AAAA', $failure, $server);
    $partial = dnsRun($check);
    expect($partial->result)->toBe(RunResult::Warning);
    expect($partial->evidence['issues'])->toEqual([
        ['type' => 'dns.issue.nameserver_unreachable', 'host' => $fixture['host'], 'record_type' => null, 'nameserver' => $server, 'reason' => $reason],
    ]);
    expect(app(DnsPersistence::class)->baseline($check->id))->toBe($baseline);
    // Recovery must not manufacture additions after an unavailable NS's addresses disappear.
    $source = $fixture['authoritative'][0];
    $fake->respondWith($fixture['host'], 'AAAA', new DnsLookupResult($source['answers']['AAAA'], [], $server), $server);
    expect(dnsRun($check)->result)->toBe(RunResult::Succeeded);
    expect(Event::query()->where('type', 'dns.records_changed')->count())->toBe(0);
    $baseline = app(DnsPersistence::class)->baseline($check->id);
    dnsReplies($fake, [dnsA(host: $fixture['host'])], $fixture['host']);
    $fake->respondWith($fixture['zone'], 'NS', new DnsLookupResult($fixture['zone_ns'], [], null));
    foreach ($fixture['authoritative'] as $source) {
        $fake->respondWith($fixture['host'], 'AAAA', $failure, $source['nameserver']);
    }
    $unreachable = dnsRun($check);
    expect($unreachable->result)->toBe(RunResult::Warning);
    expect(array_column($unreachable->evidence['issues'], 'type'))->toBe(['dns.issue.nameservers_unreachable', 'dns.issue.nameservers_unreachable']);
    expect(app(DnsPersistence::class)->baseline($check->id))->toBe($baseline);
    expect(Event::query()->where('type', 'dns.records_changed')->count())->toBe(0);
    $fake->respondWith($fixture['host'], 'A', $failure);
    expect(dnsRun($check)->result)->toBe(RunResult::Errored);
    expect(app(DnsPersistence::class)->baseline($check->id))->toBe($baseline);
    expect(Event::query()->where('type', 'dns.records_changed')->count())->toBe(0);
})->with(['timeout', 'servfail']);

it('keeps refused malformed truncated and oversized replies loud even with another complete authority', function (string $failure, bool $authoritative): void {
    $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/parity/anycast.json'), true, flags: JSON_THROW_ON_ERROR);
    $fake = new FakeDnsResolver;
    app()->instance(DnsResolver::class, $fake);
    $check = dnsCheck(['check_nameservers_in_sync' => true], $fixture['host']);
    dnsReplies($fake, array_merge(...array_values($fixture['recursive'])), $fixture['host']);
    foreach ($fixture['authoritative'] as $source) {
        dnsReplies($fake, array_merge(...array_values($source['answers'])), $fixture['host'], $source['nameserver']);
    }
    $exception = $failure === 'REFUSED' ? new DnsResolverStatusException($failure) : new ExternalNetworkException($failure);
    $fake->respondWith($fixture['host'], 'A', $exception, $authoritative ? $fixture['authoritative'][0]['nameserver'] : null);

    expect(dnsRun($check)->result)->toBe(RunResult::Errored);
    expect(DB::table('dns_baselines')->count())->toBe(0);
    expect(DB::table('dns_observations')->count())->toBe(0);
    expect(Event::query()->where('type', 'dns.records_changed')->count())->toBe(0);
})->with(['REFUSED', 'Malformed DNS answer.', 'DNS response was truncated.', 'DNS resolver output exceeded 128 KiB.'])->with([true, false]);

it('preserves a delegated child baseline when discovery cannot establish authority', function (string $failure, RunResult $expected, ?string $message, bool $sync): void {
    $environment = $_ENV;
    $path = getenv('PATH');
    $scenario = getenv('DNS_PROCESS_SCENARIO');
    $mode = getenv('DNS_DELEGATION_MODE');
    $previousMarker = getenv('DNS_DISCOVERY_MARKER');
    $marker = sys_get_temp_dir().'/dns-discovery-'.Str::uuid();
    $fixturePath = base_path('addons/dns/tests/Support').':'.$path;
    useDigFixtureProcess();
    putenv('PATH='.$fixturePath);
    putenv('DNS_PROCESS_SCENARIO=delegated-child');
    putenv('DNS_DELEGATION_MODE=baseline');
    $_ENV['PATH'] = $fixturePath;
    $_ENV['DNS_PROCESS_SCENARIO'] = 'delegated-child';
    $_ENV['DNS_DELEGATION_MODE'] = 'baseline';
    putenv('DNS_DISCOVERY_MARKER='.$marker);
    $_ENV['DNS_DISCOVERY_MARKER'] = $marker;
    try {
        $check = dnsCheck(['check_nameservers_in_sync' => $sync], 'child.delegation.example');
        expect(dnsRun($check)->result)->toBe(RunResult::Succeeded);
        $baseline = app(DnsPersistence::class)->baseline($check->id);
        putenv('DNS_DELEGATION_MODE='.$failure);
        $_ENV['DNS_DELEGATION_MODE'] = $failure;

        $run = dnsRun($check);

        expect(Event::query()->where('type', 'dns.records_changed')->count())->toBe(0);
        expect(app(DnsPersistence::class)->baseline($check->id))->toBe($baseline);
        expect(DB::table('dns_history_items')->count())->toBe(1);
        expect($run->result)->toBe($expected);
        if ($message !== null) {
            expect(json_decode($run->error, true, flags: JSON_THROW_ON_ERROR)['message'])->toBe($message);
        } else {
            expect($run->error)->toBeNull();
            expect($run->metrics['changed'])->toBeFalse();
            expect(array_column($run->evidence['issues'], 'type'))->toBe($expected === RunResult::Failed
                ? ['dns.issue.nameservers_unreachable', 'dns.issue.resolves_to_nothing'] : ['dns.issue.nameservers_unreachable']);
        }
        // An AA-marked negative answer is real absence, not a referral.
        putenv('DNS_DELEGATION_MODE=negative');
        $_ENV['DNS_DELEGATION_MODE'] = 'negative';
        expect(dnsRun($check)->result)->toBe(RunResult::Failed);
        expect(Event::query()->where('type', 'dns.records_changed')->count())->toBe(1);
    } finally {
        $_ENV = $environment;
        putenv($path === false ? 'PATH' : 'PATH='.$path);
        putenv($scenario === false ? 'DNS_PROCESS_SCENARIO' : 'DNS_PROCESS_SCENARIO='.$scenario);
        putenv($mode === false ? 'DNS_DELEGATION_MODE' : 'DNS_DELEGATION_MODE='.$mode);
        putenv($previousMarker === false ? 'DNS_DISCOVERY_MARKER' : 'DNS_DISCOVERY_MARKER='.$previousMarker);
        if (is_file($marker)) {
            unlink($marker);
        }
    }
})->with([
    'parent referral' => ['referral', RunResult::Errored, 'DNS response is not authoritative.'],
    'discovery timeout and empty recursion' => ['unresolved', RunResult::Errored, 'DNS authoritative nameservers could not be determined.'],
    'discovery timeout and live recursion' => ['unresolved-live', RunResult::Warning, null],
    'discovery timeout and failed recursion' => ['unresolved-failed', RunResult::Errored, 'DNS resolver returned SERVFAIL.'],
    'empty discovery and empty recursion' => ['empty', RunResult::Failed, null],
    'empty discovery and live recursion' => ['empty-live', RunResult::Warning, null],
    'last discovery label timeout and live recursion' => ['empty-last-timeout-live', RunResult::Errored, 'DNS resolver did not respond.'],
    'last discovery label SERVFAIL and live recursion' => ['empty-last-servfail-live', RunResult::Errored, 'DNS resolver returned SERVFAIL.'],
])->with([false, true]);
