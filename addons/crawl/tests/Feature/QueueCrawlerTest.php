<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Models\Check;
use Heptapod\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

it('finishes accepted queue work and exits gracefully on SIGTERM during or after a crawl', function (string $when, array $expectedResults): void {
    $token = (string) Str::uuid();
    $fixture = 'http://fixtures.test/crawl-addon/worker-signal/';
    $site = Site::factory()->create(['url' => $fixture.'page?token='.$token]);
    $crawl = Check::factory()->create(['site_id' => $site->id, 'type' => CheckKey::BrokenLinks, 'enabled' => true,
        'config' => app(CheckRegistry::class)->get(CheckKey::BrokenLinks)->definition()->configSchema->validateConfig(['respect_robots' => false, 'max_depth' => 0]),
    ]);
    $sites = [$site];
    $checks = [$crawl->id];
    if ($when === 'after-crawl') {
        $nextSite = Site::factory()->create(['url' => 'http://fixtures.test/slow']);
        $next = Check::factory()->create(['site_id' => $nextSite->id, 'type' => CheckKey::Uptime, 'enabled' => true,
            'config' => app(CheckRegistry::class)->get(CheckKey::Uptime)->definition()->configSchema->validateConfig([]),
        ]);
        $sites[] = $nextSite;
        $checks[] = $next->id;
    }
    $client = new Client;
    $env = ['APP_ENV' => 'testing', 'DB_DATABASE' => DB::connection()->getDatabaseName(), 'DB_URL' => '',
        'REDIS_PREFIX' => 'queue-worker-signals-'.Str::uuid().':',
    ];
    $script = base_path('addons/crawl/tests/Fixtures/queue-worker-signals.php');
    $process = new Process([PHP_BINARY, $script, $when, ...array_map(strval(...), $checks)], base_path(), $env, timeout: 15);
    $waitFor = function (Closure $condition) use ($process): void {
        $deadline = microtime(true) + 10;
        do {
            $ready = $condition();
            if (! $ready && $process->isRunning()) {
                usleep(10000);
            }
        } while (! $ready && $process->isRunning() && microtime(true) < $deadline);
        expect($ready)->toBeTrue($process->getOutput().$process->getErrorOutput());
    };
    // Separate worker sessions must see only this test's committed fixture records.
    DB::commit();
    try {
        $process->start();
        $waitFor(fn (): bool => trim((string) $client->get($fixture.'state?token='.$token)->getBody()) === '1');
        if ($when === 'during-crawl') {
            $process->signal(SIGTERM);
            $client->get($fixture.'release?token='.$token);
        } else {
            $client->get($fixture.'release?token='.$token);
            $waitFor(fn (): bool => str_contains($process->getOutput(), 'next-running'));
            $process->signal(SIGTERM);
        }
        $process->wait();

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
        $lines = explode("\n", trim($process->getOutput()));
        $result = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
        expect($result['runs'])->toBe($expectedResults)
            ->and($result['attempts'])->toHaveCount(count($expectedResults))
            ->and($result['failed_jobs'])->toBe(0)
            ->and($result['reserved'])->toBe(0);
        foreach ($result['attempts'] as $attempt) {
            expect($attempt['phase'])->toBe('done')->and($attempt['check_run_id'])->not->toBeNull();
        }
    } finally {
        $client->get($fixture.'release?token='.$token);
        $process->stop();
        new Process([PHP_BINARY, $script, 'cleanup'], base_path(), $env)->mustRun();
        DB::table('check_runs')->whereIn('check_id', $checks)->delete();
        foreach ($sites as $ownedSite) {
            DB::table('crawl_snapshots')->where('site_id', $ownedSite->id)->delete();
            DB::table('crawl_collections')->where('site_id', $ownedSite->id)->delete();
            DB::table('sites')->where('id', $ownedSite->id)->delete();
            DB::table('clients')->where('id', $ownedSite->client_id)->where('is_internal', false)->delete();
        }
        DB::beginTransaction();
    }
})->with(['active crawler' => ['during-crawl', ['succeeded']], 'next check on the same worker' => ['after-crawl', ['succeeded', 'succeeded']]]);
