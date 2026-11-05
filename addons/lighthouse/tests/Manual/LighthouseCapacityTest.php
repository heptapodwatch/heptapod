<?php

declare(strict_types=1);

use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\RunResult;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../Pest.php';

it('completes concurrent browser checks through real Horizon workers and the single-audit service', function (string $mode): void {
    $checks = [];
    for ($index = 0; $index < 2; $index++) {
        $check = lighthouseCheck(['device_emulation' => 'desktop']);
        $check->site->update(['url' => 'http://fixtures.test/?capacity='.Str::uuid()]);
        $checks[] = $check;
    }
    if ($mode === 'combined') {
        for ($index = 0; $index < 2; $index++) {
            $site = Site::factory()->create(['url' => 'http://fixtures.test/crawl-addon/benchmark?token='.Str::uuid()]);
            $checks[] = Check::factory()->create(['site_id' => $site->id, 'type' => CheckKey::BrokenLinks,
                'config' => app(CheckRegistry::class)->get(CheckKey::BrokenLinks)->definition()->configSchema->validateConfig([
                    'max_depth' => 1, 'max_pages' => 6, 'respect_robots' => false,
                ]),
            ]);
        }
    }
    $ids = array_map(fn ($check): int => $check->id, $checks);
    $forwarder = new Process(['node', __DIR__.'/../Support/audit-forwarder.mjs'], timeout: 150);
    $forwarder->start();
    expect($forwarder->waitUntil(fn (string $type, string $output): bool => preg_match('/^\d+\n$/', $output) === 1))->toBeTrue();
    $port = (int) strtok($forwarder->getOutput(), "\n");
    $env = ['APP_ENV' => 'testing', 'DB_DATABASE' => DB::connection()->getDatabaseName(), 'DB_URL' => '',
        'REDIS_PREFIX' => 'lighthouse-capacity-'.Str::uuid().':', 'HEPTAPOD_BROWSER_ENABLED' => 'true',
        'HEPTAPOD_CRAWL_CONCURRENCY' => '2', 'BROADCAST_CONNECTION' => 'null',
        'LIGHTHOUSE_SERVICE_URL' => 'http://127.0.0.1:'.$port,
    ];
    $script = __DIR__.'/../Support/capacity-supervisors.php';
    $worker = new Process([PHP_BINARY, $script, $mode, ...array_map(strval(...), $ids)], base_path(), $env, timeout: 150);
    DB::commit();
    try {
        $worker->mustRun();
        expect($worker->getOutput())->toContain('workers-stopped');
        $runs = CheckRun::query()->whereIn('check_id', $ids)->orderBy('check_id')->get();
        expect($runs)->toHaveCount(count($checks));
        foreach ($runs as $run) {
            expect($run->result)->toBe(RunResult::Succeeded, ($run->error ?? '')."\n".$worker->getOutput().$forwarder->getOutput())->and($run->metrics)->not->toBeNull();
        }
        expect(DB::table('check_run_attempts')->whereIn('check_id', $ids)->where('phase', 'done')->count())->toBe(count($checks));
        expect(DB::table('check_run_attempts')->whereIn('check_id', $ids)->whereNull('check_run_id')->count())->toBe(0);
        if ($mode !== 'parallel') {
            expect($worker->getOutput())->toContain('"queues":"checks-browser","workers":1', '"queues":"checks-crawl","workers":2');
            expect($forwarder->getOutput())->not->toContain('audit-status:429');
            expect($runs[1]->started_at->greaterThanOrEqualTo($runs[0]->ended_at))->toBeTrue();
        } else {
            expect($forwarder->getOutput())->toContain('audit-status:429');
        }
        expect(substr_count($forwarder->getOutput(), 'audit-status:200'))->toBe(2);
        if ($mode === 'combined') {
            foreach ([$runs[2], $runs[3]] as $crawl) {
                expect($crawl->started_at->lessThan($runs[0]->ended_at) && $crawl->ended_at->greaterThan($runs[0]->started_at))->toBeTrue();
            }
        }
        fwrite(STDOUT, $mode.': '.$worker->getOutput().'audit200='.substr_count($forwarder->getOutput(), 'audit-status:200').', audit429='.substr_count($forwarder->getOutput(), 'audit-status:429').PHP_EOL);
    } finally {
        if ($worker->isRunning()) {
            $worker->signal(SIGTERM);
            $worker->wait();
        }
        $forwarder->signal(SIGTERM);
        $forwarder->wait();
        new Process([PHP_BINARY, $script, 'cleanup'], base_path(), $env)->mustRun();
        DB::table('lighthouse_reports')->whereIn('check_id', $ids)->delete();
        DB::table('lighthouse_baselines')->whereIn('check_id', $ids)->delete();
        DB::table('check_runs')->whereIn('check_id', $ids)->delete();
        foreach ($checks as $check) {
            $clientId = $check->site->client_id;
            DB::table('crawl_snapshots')->where('site_id', $check->site_id)->delete();
            DB::table('crawl_collections')->where('site_id', $check->site_id)->delete();
            DB::table('sites')->where('id', $check->site_id)->delete();
            DB::table('clients')->where('id', $clientId)->where('is_internal', false)->delete();
        }
        DB::beginTransaction();
    }
})->with(['configured', 'parallel', 'combined']);
