<?php

declare(strict_types=1);

use Heptapod\Addons\Crawl\CrawlPersistence;
use Heptapod\Contracts\CheckKey;
use Heptapod\Models\Check;
use Heptapod\Models\Site;
use Illuminate\Support\Facades\DB;

it('benchmarks reconstruction of 25000 accepted crawl findings', function (): void {
    $site = Site::factory()->create();
    $check = Check::factory()->create(['site_id' => $site->id, 'type' => CheckKey::BrokenLinks]);
    $snapshot = DB::table('crawl_snapshots')->insertGetId([
        'site_id' => $site->id, 'check_id' => $check->id, 'run_id' => 1,
        'claim_token' => '00000000-0000-0000-0000-000000000001', 'run_ended_at' => now(), 'collected_at' => now(),
        'truncated' => false, 'observed_urls' => '[]', 'observed_pages' => '[]', 'discovered_urls' => '[]',
        'discovery_complete' => true, 'evaluated_finding_ids' => '[]', 'settings' => '{}',
        'site_url' => 'https://fixtures.test/', 'target_fingerprint' => str_repeat('a', 64),
    ]);
    for ($offset = 0; $offset < 25000; $offset += 500) {
        $rows = [];
        for ($index = $offset; $index < $offset + 500; $index++) {
            $rows[] = [
                'snapshot_id' => $snapshot, 'crawled_url' => 'https://fixtures.test/finding-'.$index,
                'relative_crawled_url' => '/finding-'.$index, 'status_code' => 404,
                'found_on_url' => null, 'relative_found_on_url' => null, 'link_text' => null,
                'type' => 'link', 'error_message' => null, 'internal' => true,
            ];
        }
        DB::table('crawl_findings')->insert($rows);
    }
    unset($rows);
    gc_collect_cycles();
    $before = memory_get_usage(true);
    memory_reset_peak_usage();
    $baseline = app(CrawlPersistence::class)->baseline($check->id);
    $peak = memory_get_peak_usage(true) - $before;
    fwrite(STDERR, sprintf("Baseline reconstruction: %.1f MiB delta, %.1f MiB absolute peak\n", $peak / 1048576, memory_get_peak_usage(true) / 1048576));
    expect($baseline['artifact']->urls)->toHaveCount(25000)
        ->and($peak)->toBeLessThan(20 * 1048576);
    unset($baseline);
    gc_collect_cycles();
    $before = memory_get_usage(true);
    memory_reset_peak_usage();
    $count = 0;
    foreach (app(CrawlPersistence::class)->baselineForEvaluation($check->id)['urls'] as $finding) {
        $count += $finding->broken() ? 1 : 0;
    }
    $peak = memory_get_peak_usage(true) - $before;
    fwrite(STDERR, sprintf("Streamed evaluation: %.1f MiB delta, %.1f MiB absolute peak\n", $peak / 1048576, memory_get_peak_usage(true) / 1048576));
    expect($count)->toBe(25000)
        ->and($peak)->toBeLessThan(128 * 1048576);
})->group('benchmark');
