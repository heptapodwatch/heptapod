<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\RunResult;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\NotificationDestination;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;

pest()->use(DatabaseTruncation::class);

beforeAll(function (): void {
    RefreshDatabaseState::$migrated = false;
});

afterEach(function (): void {
    // Forward-only upgrade migrations cannot roll back. Drop this test schema, then let the next test rebuild it.
    $this->artisan('db:wipe', ['--force' => true, '--no-interaction' => true])->assertSuccessful();
    expect(DB::getSchemaBuilder()->getTables())->toBe([]);
    RefreshDatabaseState::$migrated = false;
});

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05T12:00:00Z'));
    expect(DB::transactionLevel())->toBe(0)
        ->and(Check::query()->count())->toBe(0)
        ->and(Event::query()->count())->toBe(0)
        ->and(Delivery::query()->count())->toBe(0);
});

/** @return array{check: Check, run: CheckRun, incident: Incident, snapshot: int, finding: string} */
function legacyLinkUpgrade(bool $stillBroken = false): array
{
    $check = Check::factory()->create(['type' => CheckKey::BrokenLinks, 'latest_run_result' => CheckResult::Warning]);
    $run = CheckRun::factory()->for($check)->create(['result' => RunResult::Warning, 'summary' => 'broken_links.warning',
        'metrics' => ['broken_internal' => 0, 'broken_external' => $stillBroken ? 2 : 1], 'evidence' => ['crawl_truncated' => false, 'comparison_limit_reached' => false]]);
    $check->update(['latest_run_id' => $run->id, 'latest_run_ended_at' => $run->ended_at]);
    $incident = Incident::factory()->for($check)->create();
    $url = 'https://external.example.test/refused';
    $finding = hash('sha256', $url);
    $ids = $stillBroken ? [$finding, hash('sha256', 'https://external.example.test/missing')] : [$finding];
    $snapshot = DB::table('crawl_snapshots')->insertGetId(['site_id' => $check->site_id, 'check_id' => $check->id, 'run_id' => $run->id,
        'claim_token' => '00000000-0000-4000-8000-000000000001', 'run_ended_at' => $run->ended_at, 'collected_at' => now(),
        'settings' => '{}', 'truncated' => false, 'observed_urls' => '[]', 'observed_pages' => '[]', 'discovered_urls' => '[]',
        'discovery_complete' => true, 'evaluated_finding_ids' => json_encode($ids, JSON_THROW_ON_ERROR),
        'site_url' => $check->site->url, 'target_fingerprint' => str_repeat('a', 64), 'classification_policy_version' => null]);
    DB::table('crawl_findings')->insert(['snapshot_id' => $snapshot, 'crawled_url' => $url, 'relative_crawled_url' => $url,
        'status_code' => 403, 'type' => 'link', 'error_message' => 'request_failed', 'internal' => false]);
    if ($stillBroken) {
        DB::table('crawl_findings')->insert(['snapshot_id' => $snapshot, 'crawled_url' => 'https://external.example.test/missing', 'relative_crawled_url' => '/missing',
            'status_code' => 404, 'type' => 'link', 'error_message' => 'request_failed', 'internal' => false]);
    }

    return ['check' => $check, 'run' => $run, 'incident' => $incident, 'snapshot' => $snapshot, 'finding' => $finding];
}

/** @param array{check: Check, run: CheckRun, incident: Incident, snapshot: int, finding: string} $legacy */
function legacyLinkAlert(array $legacy, array $payload = [], string $type = 'broken_links.new_broken_found', bool $routed = false): Event
{
    return Event::factory()->for($legacy['check'])->for($legacy['incident'])->create(['type' => $type, 'to_result' => CheckResult::Warning,
        'notification_routed_at' => $routed ? now() : null,
        'payload' => ['run_id' => $legacy['run']->id, 'finding_ids' => [$legacy['finding']], 'truncated' => false, ...$payload]]);
}

function legacyLinkDelivery(Event $event, string $state = 'pending'): Delivery
{
    return Delivery::query()->create(['event_id' => $event->id, 'destination_id' => NotificationDestination::factory()->create()->id,
        'state' => $state, 'next_attempt_at' => now(), 'suppression_evaluated_at' => now(),
        // Production leases use PostgreSQL's clock. Fixed distant dates make live/expired states independent of the host date.
        'lease_until' => $state === 'sending' ? '2100-01-01T00:00:00Z' : null, 'sent_at' => $state === 'sent' ? now() : null]);
}

/** @return array<string, list<array<string, mixed>>> */
function linkUpgradeSnapshot(): array
{
    $snapshot = [];
    foreach (['checks', 'check_runs', 'incidents', 'crawl_snapshots', 'crawl_findings', 'events', 'deliveries'] as $table) {
        $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
    }

    return $snapshot;
}

it('previews the production upgrade command without writes and blocks live sending leases before any reclassification', function (): void {
    $legacy = legacyLinkUpgrade();
    $delivery = legacyLinkDelivery(legacyLinkAlert($legacy), 'sending');
    $before = linkUpgradeSnapshot();

    $this->artisan('heptapod:reclassify-links', ['--policy' => '1', '--dry-run' => true, '--no-interaction' => true])->assertFailed();
    expect(linkUpgradeSnapshot())->toBe($before);
    $this->artisan('heptapod:reclassify-links', ['--policy' => '1', '--no-interaction' => true])->assertFailed();
    expect(linkUpgradeSnapshot())->toBe($before);
    $delivery->update(['lease_until' => '2000-01-01T00:00:00Z']);
    $before = linkUpgradeSnapshot();

    $this->artisan('heptapod:reclassify-links', ['--policy' => '1', '--dry-run' => true, '--no-interaction' => true])->assertSuccessful();

    expect(linkUpgradeSnapshot())->toBe($before);
});

it('relabels legacy findings silently and suppresses only fully explained unsent alerts without resolving incidents', function (): void {
    $legacy = legacyLinkUpgrade();
    $obsolete = legacyLinkAlert($legacy);
    $warning = legacyLinkAlert($legacy, type: 'check.result_changed');
    $pending = legacyLinkDelivery($obsolete);
    $failed = legacyLinkDelivery($obsolete, 'failed');
    $expired = legacyLinkDelivery($obsolete, 'sending');
    $expired->update(['lease_until' => '2000-01-01T00:00:00Z']);
    $sentEvent = legacyLinkAlert($legacy, routed: true);
    $sent = legacyLinkDelivery($sentEvent, 'sent');
    $truncated = legacyLinkAlert($legacy, ['truncated' => true]);
    $foreignFinding = legacyLinkAlert($legacy, ['finding_ids' => ['unexplained-finding']]);
    $unknownRun = legacyLinkAlert($legacy, ['run_id' => 999999]);
    $mixed = legacyLinkUpgrade(stillBroken: true);
    $mixedWarning = legacyLinkAlert($mixed, type: 'check.result_changed');
    $mixedAlert = legacyLinkAlert($mixed, ['finding_ids' => [$mixed['finding'], hash('sha256', 'https://external.example.test/missing')]]);
    $incidentBefore = $legacy['incident']->refresh()->getAttributes();
    $sentBefore = $sent->refresh()->getAttributes();

    // This is the same entry point and policy argument docker/prod/entrypoint.sh runs before workers start.
    $this->artisan('heptapod:reclassify-links', ['--policy' => '1', '--no-interaction' => true])->assertSuccessful();

    expect(DB::table('crawl_snapshots')->where('id', $legacy['snapshot'])->value('classification_policy_version'))->toBe(1)
        ->and(DB::table('crawl_findings')->where('snapshot_id', $legacy['snapshot'])->value('error_message'))->toBe('access_refused')
        ->and($legacy['check']->refresh()->latest_run_result)->toBe(CheckResult::Succeeded)
        ->and($legacy['run']->refresh()->result)->toBe(RunResult::Succeeded)
        ->and($legacy['run']->evidence['not_verifiable_reasons'])->toBe(['access_refused' => 1])
        ->and($mixed['check']->refresh()->latest_run_result)->toBe(CheckResult::Warning)
        ->and($legacy['incident']->refresh()->getAttributes())->toBe($incidentBefore)
        ->and($sent->refresh()->getAttributes())->toBe($sentBefore);
    foreach ([$obsolete, $warning] as $event) {
        expect($event->refresh()->suppressed)->toBeTrue()->and($event->suppression_reason)->toBe('policy_reclassified');
    }
    foreach ([$pending, $failed, $expired] as $delivery) {
        expect($delivery->refresh()->suppressed)->toBeTrue()->and($delivery->suppression_reason)->toBe('policy_reclassified');
    }
    foreach ([$sentEvent, $truncated, $foreignFinding, $unknownRun, $mixedWarning, $mixedAlert] as $event) {
        expect($event->refresh()->suppressed)->toBeFalse();
    }
    expect(Event::query()->where('type', 'check.findings_reclassified')->count())->toBe(2)
        ->and(Event::query()->whereIn('type', ['incident.resolved', 'incident.opened'])->count())->toBe(0)
        ->and($mixed['incident']->refresh()->resolved_at)->toBeNull();
    $after = linkUpgradeSnapshot();

    $this->artisan('heptapod:reclassify-links', ['--policy' => '1', '--no-interaction' => true])->assertSuccessful();

    expect(linkUpgradeSnapshot())->toBe($after);
});

it('rejects an unsupported upgrade policy without changing stored data', function (): void {
    legacyLinkUpgrade();
    $before = linkUpgradeSnapshot();

    $this->artisan('heptapod:reclassify-links', ['--policy' => '999', '--no-interaction' => true])->assertFailed();

    expect(linkUpgradeSnapshot())->toBe($before);
});
