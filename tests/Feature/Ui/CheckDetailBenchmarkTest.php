<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckKey;
use Heptapod\Models\Check;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('benchmarks check detail projections at representative retained volumes', function (CheckKey $key): void {
    $this->withoutVite();
    $at = CarbonImmutable::parse('2026-10-04T00:30:00Z');
    $this->travelTo($at);
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $site = Site::factory()->create(['url' => 'https://benchmark.example']);
    $check = Check::factory()->for($site)->create(['type' => $key]);

    if ($key === CheckKey::Uptime) {
        foreach (range(0, 3) as $monthsAgo) {
            $start = $at->startOfMonth()->subMonths($monthsAgo);
            $name = 'check_runs_'.$start->format('Y_m');
            DB::statement("CREATE TABLE IF NOT EXISTS {$name} PARTITION OF check_runs FOR VALUES FROM ('{$start->toIso8601String()}') TO ('{$start->addMonth()->toIso8601String()}')");
        }
        DB::statement(<<<'SQL'
            INSERT INTO check_runs (check_id, check_type, result, started_at, ended_at, summary, metrics, evidence)
            SELECT ?, 'uptime', 'succeeded', observed_at - interval '1 second', observed_at,
                   'checks.uptime.succeeded', '{"total_time_in_seconds":0.1}'::jsonb, '{"status_code":200}'::jsonb
            FROM generate_series(?::timestamptz, ?::timestamptz - interval '1 minute', interval '1 minute') observed_at
            SQL, [$check->id, $at->subDays(90)->toIso8601String(), $at->toIso8601String()]);
    } elseif ($key === CheckKey::CertificateHealth) {
        DB::statement(<<<'SQL'
            INSERT INTO certificate_health_snapshots (site_id, check_id, run_id, claim_token, run_ended_at, snapshot, created_at)
            SELECT ?, ?, ordinal, gen_random_uuid(), observed_at,
                   '{"certificate_details":null,"certificate_checks":[],"certificate_chain_issuers":[]}'::jsonb, observed_at
            FROM generate_series(?::timestamptz, ?::timestamptz, interval '1 hour') WITH ORDINALITY AS observations(observed_at, ordinal)
            SQL, [$site->id, $check->id, $at->subDays(90)->toIso8601String(), $at->toIso8601String()]);
    } elseif ($key === CheckKey::Dns) {
        $snapshot = json_encode(['dns_records' => [], 'raw_dns_records' => [], 'normalized_records' => ['benchmark.example|A' => [['host' => 'benchmark.example', 'type' => 'A', 'class' => 'IN', 'ip' => '192.0.2.1']]], 'issues' => [], 'authoritative_nameservers' => []], JSON_THROW_ON_ERROR);
        DB::table('dns_baselines')->insert(['check_id' => $check->id, 'run_id' => 1, 'snapshot' => $snapshot, 'expected' => '[]']);
        DB::statement(<<<'SQL'
            INSERT INTO dns_observations (site_id, check_id, run_id, claim_token, run_ended_at, snapshot, expected, created_at)
            SELECT ?, ?, ordinal, gen_random_uuid(), observed_at, ?::jsonb, '[]'::jsonb, observed_at
            FROM generate_series(?::timestamptz, ?::timestamptz, interval '1 hour') WITH ORDINALITY AS observations(observed_at, ordinal)
            SQL, [$site->id, $check->id, $snapshot, $at->subDays(90)->toIso8601String(), $at->toIso8601String()]);
    } elseif ($key === CheckKey::BrokenLinks) {
        foreach ([[$at->subDays(8), 1], [$at, 2]] as [$observed, $runId]) {
            $snapshotId = DB::table('crawl_snapshots')->insertGetId([
                'site_id' => $site->id, 'check_id' => $check->id, 'run_id' => $runId,
                'claim_token' => (string) Str::uuid(), 'run_ended_at' => $observed, 'collected_at' => $observed,
                'settings' => '{"max_pages":2000}', 'truncated' => false, 'page_cap_reached' => false, 'time_limit_reached' => false,
                'observed_urls' => '[]', 'observed_pages' => '[]', 'discovered_urls' => '[]', 'discovery_complete' => true,
                'evaluated_finding_ids' => '[]', 'site_url' => $site->url, 'target_fingerprint' => str_repeat('a', 64),
            ]);
            DB::statement(<<<'SQL'
                INSERT INTO crawl_findings (snapshot_id, crawled_url, relative_crawled_url, status_code, found_on_url, relative_found_on_url, type, internal)
                SELECT ?, 'https://benchmark.example/broken/' || ordinal, '/broken/' || ordinal, 404,
                       'https://benchmark.example/', '/', 'link', true FROM generate_series(1, 2000) ordinal
                SQL, [$snapshotId]);
        }
    } elseif ($key === CheckKey::Lighthouse) {
        DB::statement(<<<'SQL'
            INSERT INTO check_run_rollups_daily (check_id, bucket_start, succeeded_count, metric_sums, metric_counts, computed_at)
            SELECT ?, observed_at, 1, '{"performance_score":80}'::jsonb, '{"performance_score":1}'::jsonb, observed_at
            FROM generate_series(?::timestamptz, ?::timestamptz, interval '1 day') observed_at
            SQL, [$check->id, $at->startOfDay()->subDays(364)->toIso8601String(), $at->startOfDay()->toIso8601String()]);
    } elseif ($key === CheckKey::Cron) {
        DB::statement(<<<'SQL'
            INSERT INTO cron_checks (check_id, uuid, name, type, frequency_in_minutes, next_due_at, created_at, updated_at)
            SELECT ?, gen_random_uuid(), 'Task ' || ordinal, 'simple', 60, ?::timestamptz, ?::timestamptz, ?::timestamptz
            FROM generate_series(1, 50) ordinal
            SQL, [$check->id, $at->toIso8601String(), $at->toIso8601String(), $at->toIso8601String()]);
        DB::statement(<<<'SQL'
            INSERT INTO cron_run_tasks (run_id, claim_token, check_id, cron_check_id, result, ping_revision, failure_revision, late_by_seconds, run_ended_at)
            SELECT day_index + 1, gen_random_uuid(), ?, tasks.id, 'succeeded', 1, 0, 0, ?::timestamptz - day_index * interval '1 day'
            FROM generate_series(0, 29) day_index CROSS JOIN cron_checks tasks WHERE tasks.check_id = ?
            SQL, [$check->id, $at->toIso8601String(), $check->id]);
    } elseif ($key === CheckKey::ApplicationHealth) {
        DB::statement(<<<'SQL'
            INSERT INTO application_health_components (check_id, source_key, active, snapshot, run_id, run_ended_at, detected_at)
            SELECT ?, md5(ordinal::text), true,
                   jsonb_build_object('name','component-' || ordinal,'label','Component','status','ok','shortSummary','Good','latest_result','succeeded'),
                   1, ?::timestamptz, ?::timestamptz FROM generate_series(1, 1000) ordinal
            SQL, [$check->id, $at->toIso8601String(), $at->toIso8601String()]);
    }

    $url = '/sites/'.$site->id.'/checks/'.$check->id;
    $this->actingAs($owner)->get($url)->assertOk();
    $elapsed = [];
    $queryCount = 0;
    for ($attempt = 0; $attempt < 3; $attempt++) {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $started = hrtime(true);
        $response = $this->get($url)->assertOk();
        $elapsed[] = round((hrtime(true) - $started) / 1000000, 1);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();
        expect($response->inertiaProps()['history']['check_type'])->toBe($key->value);
    }
    fwrite(STDERR, "{$key->value} detail: ".implode(', ', $elapsed)." ms; {$queryCount} queries\n");
    sort($elapsed);
    expect($elapsed[1])->toBeLessThan(300)->and($queryCount)->toBeLessThanOrEqual(45);
})->with([
    CheckKey::Uptime, CheckKey::CertificateHealth, CheckKey::Dns, CheckKey::BrokenLinks,
    CheckKey::Lighthouse, CheckKey::Cron, CheckKey::ApplicationHealth,
])->group('benchmark');
