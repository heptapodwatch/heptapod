<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Addons\Cron\CronDefinitions;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\RunReference;
use Heptapod\Contracts\RunResult;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\CheckRunRollupDaily;
use Heptapod\Models\Event;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\QueryPlanWork;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-04T00:30:00Z'));
    new Event()->isGuarded('payload');
});

it('projects only scoped, bounded and redacted uptime observations', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-04T00:30:00Z'));
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $site = Site::factory()->create();
    $check = Check::factory()->for($site)->create(['type' => CheckKey::Uptime, 'failure_threshold' => 3,
        'config_secrets' => ['header' => 'private-uptime-token']]);
    CheckRun::factory()->count(21)->for($check)->create(['evidence' => ['status_code' => 503, 'issues' => ['status', 'private-uptime-token']], 'metrics' => ['total_time_in_seconds' => 0.42]]);
    CheckRun::factory()->for($check)->create(['started_at' => now('UTC')->subHour()->subSecond(), 'ended_at' => now('UTC')->subHour(),
        'metrics' => ['dns_time_in_seconds' => 0.01]]);
    $failure = CheckRun::factory()->for($check)->create([
        'started_at' => now()->subDays(5)->subMinute(), 'ended_at' => now()->subDays(5), 'result' => RunResult::Failed,
        'evidence' => ['status_code' => 502, 'issues' => ['status']], 'metrics' => ['total_time_in_seconds' => 1.2],
    ]);
    $other = Check::factory()->create(['type' => CheckKey::Uptime]);
    CheckRun::factory()->for($other)->create(['evidence' => ['status_code' => 418, 'issues' => ['other']], 'metrics' => []]);

    $response = $this->actingAs($user)->get('/sites/'.$site->id.'/checks/'.$check->id)->assertOk();
    expect($response->inertiaProps()['history']['check_type'])->toBe('uptime')
        ->and($response->inertiaProps()['history']['runs'])->toHaveCount(20)
        ->and($response->inertiaProps()['history']['runs'][0]['status_code'])->toBe(503)
        ->and($response->inertiaProps()['history']['runs'][0]['total_time_ms'])->toEqual(420)
        ->and($response->inertiaProps()['history']['recent_failures'])->toHaveCount(1)
        ->and($response->inertiaProps()['history']['recent_failures'][0]['run_id'])->toBe($failure->id)
        ->and($response->inertiaProps()['response_times'])->toHaveCount(4)
        ->and($response->inertiaProps()['history']['recent_failures'][0]['ended_at'])->toBe($failure->ended_at->toIso8601String())
        ->and($response->inertiaProps()['failure_threshold'])->toBe(3)
        ->and(array_values(array_filter($response->inertiaProps()['phase_series'][0]['points'], static fn (array $point): bool => $point['value'] !== null)))
        ->toEqual([['time' => now('UTC')->subHour()->startOfMinute()->toIso8601String(), 'value' => 10,
            'failure_count' => 0, 'error_count' => 0, 'warning_count' => 0, 'failure_status' => null]])
        ->and(json_encode($response->inertiaProps(), JSON_THROW_ON_ERROR))->not->toContain('private-uptime-token', '418');
    $this->get('/sites/'.$other->site_id.'/checks/'.$other->id)->assertOk();
    $this->get('/sites/'.$site->id.'/checks/'.$other->id)->assertNotFound();
});

it('retains redacted last valid health components when the latest endpoint attempt fails', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-04T00:30:00Z'));
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $check = Check::factory()->create(['type' => CheckKey::ApplicationHealth, 'config_secrets' => ['secret' => 'private-health-secret']]);
    $good = CheckRun::factory()->for($check)->create([
        'started_at' => now()->subMinutes(6), 'ended_at' => now()->subMinutes(5),
        'evidence' => ['reason' => null, 'http_status' => null, 'redirect_host' => null, 'components' => []], 'metrics' => ['response_time_in_seconds' => 0.23],
    ]);
    DB::table('application_health_snapshots')->insert([
        'check_id' => $check->id, 'run_id' => $good->id, 'claim_token' => (string) Str::uuid(),
        'run_ended_at' => $good->ended_at, 'components' => '[]', 'created_at' => $good->ended_at,
    ]);
    DB::table('application_health_components')->insert([
        'check_id' => $check->id, 'source_key' => str_repeat('a', 64), 'active' => true,
        'snapshot' => json_encode([
            'name' => 'database', 'label' => 'DB private-health-secret', 'status' => 'ok',
            'shortSummary' => str_repeat('x', 180), 'latest_result' => 'succeeded',
        ]), 'run_id' => $good->id, 'run_ended_at' => $good->ended_at, 'detected_at' => $good->ended_at,
    ]);
    $failed = CheckRun::factory()->for($check)->create([
        'started_at' => now()->subMinutes(2), 'ended_at' => now()->subMinute(),
        'result' => RunResult::Errored, 'evidence' => ['reason' => 'http_status', 'http_status' => 401, 'redirect_host' => null, 'components' => []], 'metrics' => [],
    ]);
    $check->update(['latest_run_id' => $failed->id, 'latest_run_ended_at' => $failed->ended_at]);

    $props = $this->actingAs($user)->get('/sites/'.$check->site_id.'/checks/'.$check->id)->assertOk()->inertiaProps();
    expect($props['history']['last_valid_run_id'])->toBe($good->id)
        ->and($props['history']['last_valid_at'])->toBe($good->ended_at->toIso8601String())
        ->and($props['history']['stale'])->toBeTrue()
        ->and($props['history']['attempts'][0]['http_status'])->toBe(401)
        ->and($props['history']['components'][0]['label'])->toBe('DB [redacted]')
        ->and(mb_strlen($props['history']['components'][0]['short_summary']))->toBe(160)
        ->and(json_encode($props, JSON_THROW_ON_ERROR))->not->toContain('private-health-secret');
});

it('scopes cron capability URLs by role and invalidates the old ping URL when rotated', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-04T00:30:00Z'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $member = User::factory()->create();
    $member->assignRole('Member');
    $check = Check::factory()->create(['type' => CheckKey::Cron]);
    $task = app(CronDefinitions::class)->create($check->id, ['name' => 'Nightly backup', 'type' => 'simple', 'frequency_in_minutes' => 60, 'grace_time_in_minutes' => 5]);
    $oldUrl = route('cron.ping', ['uuid' => $task->uuid]);
    $show = '/sites/'.$check->site_id.'/checks/'.$check->id;
    $rotate = $show.'/tasks/'.$task->id.'/rotate-url';

    $this->actingAs($member)->get($show)->assertOk()->assertDontSee($task->uuid);
    $this->post($rotate)->assertForbidden();
    $memberProps = $this->get($show)->inertiaProps();
    expect($memberProps['history']['tasks'][0]['ping_url'])->toBe('********');
    $this->actingAs($owner)->get($show)->assertOk()->assertSee($task->uuid);
    $this->post($rotate)->assertRedirect($show);
    $this->postJson($oldUrl)->assertNotFound();
    $newUrl = $this->get($show)->inertiaProps()['history']['tasks'][0]['ping_url'];
    expect($newUrl)->not->toBe($oldUrl)
        ->and(DB::table('activity_log')->where('event', 'cron.ping_url_rotated')->where('causer_id', $owner->id)->count())->toBe(1);
    $this->postJson($newUrl)->assertNoContent();
    $this->withToken($owner->createToken('cron-read', ['checks:read'])->plainTextToken)
        ->getJson('/api/monitors/'.$check->site_id.'/cron-checks')->assertOk()->assertJsonPath('data.0.ping_url', $newUrl);
});

it('bounds cron task list scans and detail queries as unrelated tasks grow', function (int $count): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-04T00:30:00Z'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $check = Check::factory()->create(['type' => CheckKey::Cron]);
    app(CronDefinitions::class)->create($check->id, ['name' => 'Visible task', 'type' => 'simple', 'frequency_in_minutes' => 60]);
    $other = Check::factory()->create(['type' => CheckKey::Cron]);
    DB::statement(<<<'SQL'
        INSERT INTO cron_checks (check_id, uuid, name, type, frequency_in_minutes, next_due_at, created_at, updated_at)
        SELECT ?, gen_random_uuid(), 'Unrelated ' || ordinal, 'simple', 60,
               ?::timestamptz, ?::timestamptz, ?::timestamptz FROM generate_series(1, ?) ordinal
        SQL, [$other->id, now()->toIso8601String(), now()->toIso8601String(), now()->toIso8601String(), $count]);
    DB::statement('ANALYZE cron_checks');

    DB::enableQueryLog();
    DB::flushQueryLog();
    $props = $this->actingAs($owner)->get('/sites/'.$check->site_id.'/checks/'.$check->id)->assertOk()->inertiaProps();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    $taskQuery = collect($queries)->first(static fn (array $query): bool => str_contains($query['query'], 'from "cron_checks"') && str_contains($query['query'], 'limit 51'));
    expect($props['history']['tasks'])->toHaveCount(1)
        // Upper budget: page shell and shell health (25458fc44/0a385128), the check with its presentations (e04c03b5d),
        // bounded charts (341b9a74a) and the visible task page. Growth with unrelated tasks is bounded by the plan scan below.
        ->and(count($queries))->toBeLessThanOrEqual(59, 'the cron check detail used '.count($queries).' queries, over its budget of 59')
        ->and($taskQuery)->not->toBeNull();
    expect(QueryPlanWork::scannedRows($taskQuery['query'], $taskQuery['bindings']))->toBeLessThan(100);
})->with([500, 5000]);

it('retains last measured Lighthouse scores after an errored run with 90 UTC score days', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-04T00:30:00Z'));
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $check = Check::factory()->create(['type' => CheckKey::Lighthouse]);
    $metrics = [
        'performance_score' => 80, 'accessibility_score' => 90, 'best_practices_score' => 95, 'seo_score' => 87,
        'progressive_web_app_score' => null, 'first_contentful_paint_in_ms' => 100.0,
        'speed_index_in_ms' => null, 'largest_contentful_paint_in_ms' => null,
        'time_to_interactive_in_ms' => null, 'total_blocking_time_in_ms' => null, 'cumulative_layout_shift' => null,
    ];
    $successful = CheckRun::factory()->for($check)->create([
        'started_at' => now()->subDays(2)->subMinute(), 'ended_at' => now()->subDays(2),
        'result' => RunResult::Succeeded, 'metrics' => $metrics, 'evidence' => ['issues' => [], 'score_drops' => [], 'device_emulation' => 'mobile'],
    ]);
    $failed = CheckRun::factory()->for($check)->create([
        'started_at' => now()->subMinute(), 'ended_at' => now(),
        'result' => RunResult::Errored, 'metrics' => [], 'evidence' => ['issues' => [], 'score_drops' => []],
    ]);
    $check->update(['latest_run_id' => $failed->id, 'latest_run_ended_at' => $failed->ended_at]);
    CheckRunRollupDaily::factory()->for($check)->create([
        'bucket_start' => now()->subDays(89)->startOfDay(),
        'metric_sums' => ['performance_score' => 80], 'metric_counts' => ['performance_score' => 1],
    ]);

    $props = $this->actingAs($user)->get('/sites/'.$check->site_id.'/checks/'.$check->id)->assertOk()->inertiaProps();
    expect($props['history']['last_successful_run_id'])->toBe($successful->id)
        ->and($props['history']['last_successful_at'])->toBe($successful->ended_at->toIso8601String())
        ->and($props['history']['last_successful_metrics']['performance_score'])->toBe(80)
        ->and($props['history']['last_successful_device'])->toBe('mobile')
        ->and($props['history']['scores'])->toHaveCount(90)
        ->and($props['history']['scores'][0]['day'])->toBe('2026-07-07')
        ->and($props['history']['scores'][0]['performance'])->toEqual(80);
});

it('shows bounded crawl findings, incomplete reasons and exact weekly changes', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-04T00:30:00Z'));
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $check = Check::factory()->create(['type' => CheckKey::BrokenLinks]);
    $at = CarbonImmutable::parse('2026-10-04T00:30:00Z')->subHour();
    $snapshot = [
        'site_id' => $check->site_id, 'check_id' => $check->id, 'run_id' => 123,
        'claim_token' => (string) Str::uuid(), 'run_ended_at' => $at, 'collected_at' => $at,
        'settings' => json_encode(['max_pages' => 30]), 'truncated' => true,
        'page_cap_reached' => true, 'time_limit_reached' => true, 'resource_cap_reached' => true,
        'observed_urls' => '[]', 'observed_pages' => '[]', 'discovered_urls' => '[]',
        'discovery_complete' => false, 'evaluated_finding_ids' => '[]',
        'site_url' => 'https://sample.example', 'target_fingerprint' => str_repeat('a', 64),
    ];
    $id = DB::table('crawl_snapshots')->insertGetId($snapshot);
    $old = DB::table('crawl_snapshots')->insertGetId([
        ...$snapshot, 'run_id' => 122, 'claim_token' => (string) Str::uuid(),
        'run_ended_at' => $at->subDays(8), 'collected_at' => $at->subDays(8),
    ]);
    DB::table('crawl_findings')->insert([
        'snapshot_id' => $old, 'crawled_url' => 'https://sample.example/old', 'relative_crawled_url' => '/old',
        'status_code' => 404, 'found_on_url' => 'https://sample.example/', 'relative_found_on_url' => '/',
        'link_text' => null, 'type' => 'link', 'error_message' => null, 'internal' => true,
    ]);
    DB::table('crawl_findings')->insert([
        'snapshot_id' => $id, 'crawled_url' => 'https://sample.example/broken', 'relative_crawled_url' => '/broken',
        'status_code' => 404, 'found_on_url' => 'https://sample.example/', 'relative_found_on_url' => '/',
        'link_text' => null, 'type' => 'link', 'error_message' => null, 'internal' => true,
    ]);

    $overflow = CheckRun::factory()->for($check)->create([
        'started_at' => now()->subMinute(), 'ended_at' => now(), 'result' => RunResult::Warning,
        'evidence' => [
            'finding_ids' => [], 'truncated' => false, 'unchecked_count' => 0,
            'discovery_complete' => false, 'crawl_truncated' => true, 'page_cap' => 30,
            'observed_pages' => 30, 'observed_urls' => 25000, 'discovered_urls' => 25000,
            'page_cap_reached' => false, 'time_limit_reached' => false,
            'resource_cap_reached' => false, 'comparison_limit_reached' => true, 'notes' => [],
        ],
    ]);
    $check->update(['latest_run_id' => $overflow->id, 'latest_run_ended_at' => $overflow->ended_at]);

    $props = $this->actingAs($user)->get('/sites/'.$check->site_id.'/checks/'.$check->id)->assertOk()->inertiaProps();
    expect($props['history']['check_type'])->toBe('broken_links')
        ->and($props['history']['time_limit_reached'])->toBeTrue()
        ->and($props['history']['page_cap_reached'])->toBeTrue()
        ->and($props['history']['resource_cap_reached'])->toBeTrue()
        ->and($props['detail']['comparison_limit_reached'])->toBeTrue()
        ->and($props['detail']['resource_cap_reached'])->toBeFalse()
        ->and($props['detail']['run_ended_at'])->toBe($overflow->ended_at->toIso8601String())
        ->and($props['history']['page_cap'])->toBe(30)
        ->and($props['history']['findings'][0]['status_code'])->toBe(404)
        ->and($props['history']['observed_at'])->toBe($at->toIso8601String())
        ->and($props['history']['findings'][0]['first_seen_in_retained_history_at'])->toBe($at->toIso8601String())
        ->and($props['history']['findings'][0]['broken'])->toBeTrue()
        ->and($props['history']['weekly_change']['new'])->toBe(1)
        ->and($props['history']['weekly_change']['fixed'])->toBe(1);
});

it('keeps crawl finding lists, first-seen and weekly work scoped despite unrelated findings and long URLs', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-04T00:30:00Z'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $check = Check::factory()->create(['type' => CheckKey::BrokenLinks]);
    $other = Check::factory()->create(['type' => CheckKey::BrokenLinks]);
    $snapshot = [
        'site_id' => $check->site_id, 'check_id' => $check->id, 'run_id' => 1,
        'claim_token' => (string) Str::uuid(), 'run_ended_at' => now(), 'collected_at' => now(),
        'settings' => '{"max_pages":2000}', 'truncated' => false, 'page_cap_reached' => false, 'time_limit_reached' => false,
        'observed_urls' => '[]', 'observed_pages' => '[]', 'discovered_urls' => '[]',
        'discovery_complete' => true, 'evaluated_finding_ids' => '[]', 'site_url' => 'https://sample.example',
        'target_fingerprint' => str_repeat('a', 64),
    ];
    $latest = DB::table('crawl_snapshots')->insertGetId($snapshot);
    $old = DB::table('crawl_snapshots')->insertGetId([
        ...$snapshot, 'run_id' => 2, 'claim_token' => (string) Str::uuid(),
        'run_ended_at' => now()->subDays(8), 'collected_at' => now()->subDays(8),
    ]);
    $hidden = DB::table('crawl_snapshots')->insertGetId([
        ...$snapshot, 'site_id' => $other->site_id, 'check_id' => $other->id,
        'run_id' => 3, 'claim_token' => (string) Str::uuid(),
    ]);
    $middle = DB::table('crawl_snapshots')->insertGetId([
        ...$snapshot, 'run_id' => 6, 'claim_token' => (string) Str::uuid(),
        'run_ended_at' => now()->subDays(4), 'collected_at' => now()->subDays(4),
    ]);
    $longUrl = 'https://sample.example/'.str_repeat('x', 3100).'\\invalid';
    foreach ([$latest, $old] as $snapshotId) {
        DB::table('crawl_findings')->insert([
            'snapshot_id' => $snapshotId, 'crawled_url' => $longUrl, 'relative_crawled_url' => '/long',
            'status_code' => 404, 'found_on_url' => 'https://sample.example/', 'relative_found_on_url' => '/',
            'type' => 'link', 'internal' => true,
        ]);
    }
    DB::statement(<<<'SQL'
        INSERT INTO crawl_findings (snapshot_id, crawled_url, relative_crawled_url, status_code, found_on_url, relative_found_on_url, type, internal)
        SELECT ?, 'https://sample.example/other/' || ordinal, '/other/' || ordinal, 404,
               'https://sample.example/', '/', 'link', true FROM generate_series(1, 5000) ordinal
        SQL, [$middle]);
    DB::statement(<<<'SQL'
        INSERT INTO crawl_findings (snapshot_id, crawled_url, relative_crawled_url, status_code, found_on_url, relative_found_on_url, type, internal)
        SELECT ?, 'https://hidden.example/' || ordinal, '/' || ordinal, 404,
               'https://hidden.example/', '/', 'link', true FROM generate_series(1, 20000) ordinal
        SQL, [$hidden]);
    DB::statement('ANALYZE crawl_findings');

    DB::enableQueryLog();
    DB::flushQueryLog();
    $history = $this->actingAs($owner)->get('/sites/'.$check->site_id.'/checks/'.$check->id)->assertOk()->inertiaProps()['history'];
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($history['findings'][0]['url'])->toBe($longUrl)
        ->and($history['findings'][0]['first_seen_in_retained_history_at'])->toBe(now()->subDays(8)->toIso8601String())
        ->and($history['weekly_change']['new'])->toBe(0)
        ->and($history['weekly_change']['fixed'])->toBe(0);
    $findingQueries = collect($queries)->filter(static fn (array $entry): bool => str_contains($entry['query'], 'crawl_findings') && str_starts_with(strtolower(ltrim($entry['query'])), 'select'));
    expect($findingQueries->count())->toBeGreaterThanOrEqual(6);
    foreach ($findingQueries as $entry) {
        expect(QueryPlanWork::scannedRows($entry['query'], $entry['bindings']))->toBeLessThan(100);
    }

    $mixed = Check::factory()->for($check->site)->create(['type' => CheckKey::MixedContent]);
    $mixedLatest = DB::table('crawl_snapshots')->insertGetId([
        ...$snapshot, 'check_id' => $mixed->id, 'run_id' => 4, 'claim_token' => (string) Str::uuid(),
    ]);
    $mixedOld = DB::table('crawl_snapshots')->insertGetId([
        ...$snapshot, 'check_id' => $mixed->id, 'run_id' => 5, 'claim_token' => (string) Str::uuid(),
        'run_ended_at' => now()->subDays(8), 'collected_at' => now()->subDays(8),
    ]);
    foreach ([$mixedLatest, $mixedOld] as $snapshotId) {
        DB::table('mixed_content_findings')->insert([
            'snapshot_id' => $snapshotId, 'element_name' => 'img', 'mixed_content_url' => 'http://sample.example/picture',
            'source_url' => 'https://sample.example/', 'found_on_url' => 'https://sample.example/',
        ]);
    }
    DB::statement(<<<'SQL'
        INSERT INTO mixed_content_findings (snapshot_id, element_name, mixed_content_url, source_url, found_on_url)
        SELECT ?, 'img', 'http://hidden.example/' || ordinal, 'https://hidden.example/', 'https://hidden.example/'
        FROM generate_series(1, 5000) ordinal
        SQL, [$hidden]);
    DB::statement('ANALYZE mixed_content_findings');
    DB::enableQueryLog();
    DB::flushQueryLog();
    $mixedHistory = $this->get('/sites/'.$mixed->site_id.'/checks/'.$mixed->id)->assertOk()->inertiaProps()['history'];
    $mixedQueries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($mixedHistory['findings'])->toHaveCount(1);
    foreach ($mixedQueries as $entry) {
        if (str_contains($entry['query'], 'mixed_content_findings') && str_starts_with(strtolower(ltrim($entry['query'])), 'select')) {
            expect(QueryPlanWork::scannedRows($entry['query'], $entry['bindings']))->toBeLessThan(100);
        }
    }
});

it('shows certificate validity and issuer changes from accepted observations only', function (): void {
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $check = Check::factory()->create(['type' => CheckKey::CertificateHealth]);
    $store = app(CheckRegistry::class)->get(CheckKey::CertificateHealth)->persistence();
    $at = CarbonImmutable::parse('2026-10-04T00:30:00Z');
    foreach (['Issuer A', 'Issuer B'] as $index => $issuer) {
        $ended = $at->addHours($index);
        $run = CheckRun::factory()->for($check)->create(['started_at' => $ended->subMinute(), 'ended_at' => $ended]);
        $context = new CheckContext($check->site_id, $check->id, $run->id, (string) Str::uuid(), $check->site->url, $ended, $ended->addMinute(), [], [], null);
        $store->stage($context, 'snapshot', [['certificate_details' => [
            'fingerprint' => str_repeat((string) ($index + 1), 64), 'issuer' => $issuer, 'domain' => 'example.test',
            'additional_domains' => [], 'valid_from' => '2026-10-01T00:00:00Z', 'valid_until' => '2027-10-01T00:00:00Z',
            'signature_algorithm' => 'sha256', 'is_valid' => true,
        ], 'certificate_checks' => [['type' => 'trust', 'passed' => true, 'label' => 'certificate_health.check.trust']], 'certificate_chain_issuers' => []]]);
        $store->finalize(new RunReference($run->id, $ended), $context->claimToken);
    }

    $response = $this->actingAs($user)->get('/sites/'.$check->site_id.'/checks/'.$check->id)->assertOk();
    expect($response->inertiaProps()['history']['check_type'])->toBe('certificate_health')
        ->and($response->inertiaProps()['history']['observations'])->toHaveCount(2)
        ->and($response->inertiaProps()['history']['observations'][0]['issuer'])->toBe('Issuer B')
        ->and($response->inertiaProps()['history']['observations'][0]['observed_at'])->toBe($at->addHour()->toIso8601String())
        ->and($response->inertiaProps()['history']['observations'][0]['valid_from'])->toBe('2026-10-01T00:00:00+00:00')
        ->and($response->inertiaProps()['history']['observations'][0]['changed'])->toBeTrue()
        ->and($response->inertiaProps()['history']['observations'][1]['changed'])->toBeNull();
});
