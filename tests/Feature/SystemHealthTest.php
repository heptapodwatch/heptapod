<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Actions\RecordSchedulerTick;
use Heptapod\Enums\HealthStatus;
use Heptapod\Models\DispatcherHeartbeat;
use Heptapod\SystemHealth\PublishWorkerMemory;
use Heptapod\SystemHealth\ReadSystemHealth;
use Heptapod\SystemHealth\SystemHealthData;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Laravel\Horizon\Horizon;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-01T12:00:00Z'));
});

it('reports the worker cgroup memory headroom from the Horizon loop', function (): void {
    $directory = sys_get_temp_dir().'/worker-cgroup-'.Str::uuid();
    mkdir($directory);
    config(['heptapod.health.worker_cgroup_path' => $directory, 'heptapod.health.browser_urls' => []]);
    $cache = Cache::store('redis');
    $cache->forget(PublishWorkerMemory::Key);
    $cache->forget(PublishWorkerMemory::Key.':throttle');
    try {
        expect(app(ReadSystemHealth::class)()->workerMemory->status)->toBe(HealthStatus::NotConfigured);
        file_put_contents($directory.'/memory.stat', "anon 770\nfile 1200\nkernel 40\n");
        file_put_contents($directory.'/memory.max', '1000');
        app(PublishWorkerMemory::class)();
        expect(app(ReadSystemHealth::class)()->workerMemory->status)->toBe(HealthStatus::Warn)
            ->and(app(ReadSystemHealth::class)()->workerMemory->value)->toBe(81);
        $cache->forget(PublishWorkerMemory::Key.':throttle');
        file_put_contents($directory.'/memory.stat', "anon 750\nfile 1200\nkernel 40\n");
        app(PublishWorkerMemory::class)();
        expect(app(ReadSystemHealth::class)()->workerMemory->status)->toBe(HealthStatus::Ok);
        unlink($directory.'/memory.max');
        $cache->forget(PublishWorkerMemory::Key.':throttle');
        app(PublishWorkerMemory::class)();
        expect(app(ReadSystemHealth::class)()->workerMemory->status)->toBe(HealthStatus::NotConfigured);
    } finally {
        $cache->forget(PublishWorkerMemory::Key);
        $cache->forget(PublishWorkerMemory::Key.':throttle');
        unlink($directory.'/memory.stat');
        rmdir($directory);
    }
});

it('reports the private Reverb endpoint as healthy, down, or not configured', function (): void {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
        'broadcasting.connections.reverb.options.host' => config('database.redis.default.host'),
        'broadcasting.connections.reverb.options.port' => config('database.redis.default.port'),
    ]);

    expect(app(ReadSystemHealth::class)()->reverb->status)->toBe(HealthStatus::Ok);

    config(['broadcasting.connections.reverb.options.port' => 1]);
    expect(app(ReadSystemHealth::class)()->reverb->status)->toBe(HealthStatus::Down);

    config(['broadcasting.connections.reverb.options.port' => 'invalid']);
    expect(fn (): SystemHealthData => app(ReadSystemHealth::class)())->toThrow(UnexpectedValueException::class, 'Reverb port');

    config(['broadcasting.connections.reverb.options.host' => null, 'broadcasting.connections.reverb.options.port' => 8080]);
    expect(fn (): SystemHealthData => app(ReadSystemHealth::class)())->toThrow(UnexpectedValueException::class, 'Reverb publishing host');

    config(['broadcasting.default' => 'log']);
    expect(app(ReadSystemHealth::class)()->reverb->status)->toBe(HealthStatus::NotConfigured);

    config(['broadcasting.default' => null]);
    expect(app(ReadSystemHealth::class)()->reverb->status)->toBe(HealthStatus::NotConfigured);
});

/** @param array{lighthouse: string|null, gotenberg: string|null} $expectedUrls */
it('derives browser health URLs for boolean environment spellings and unset service URLs', function (string $enabled, bool $expected, ?string $lighthouseUrl, ?string $gotenbergUrl, array $expectedUrls): void {
    $previousServer = $_SERVER;
    $previousEnvironment = $_ENV;
    $previousProcess = [];
    foreach (['HEPTAPOD_BROWSER_ENABLED' => $enabled, 'LIGHTHOUSE_URL' => $lighthouseUrl, 'GOTENBERG_URL' => $gotenbergUrl] as $name => $value) {
        $previousProcess[$name] = getenv($name);
        if ($value === null) {
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($name);
        } else {
            $_SERVER[$name] = $_ENV[$name] = $value;
            putenv($name.'='.$value);
        }
    }
    try {
        $config = require base_path('config/heptapod.php');
        expect($config['browser']['enabled'])->toBe($expected)
            ->and($config['health']['browser_urls'])->toBe($expectedUrls);
    } finally {
        $_SERVER = $previousServer;
        $_ENV = $previousEnvironment;
        foreach ($previousProcess as $name => $value) {
            putenv($value === false ? $name : $name.'='.$value);
        }
    }
})->with([
    'enabled true' => ['true', true, 'http://lighthouse.test:3000/', 'http://gotenberg.test:3000/', ['lighthouse' => 'http://lighthouse.test:3000/health', 'gotenberg' => 'http://gotenberg.test:3000/health']],
    'enabled 1' => ['1', true, 'http://lighthouse.test:3000/', 'http://gotenberg.test:3000/', ['lighthouse' => 'http://lighthouse.test:3000/health', 'gotenberg' => 'http://gotenberg.test:3000/health']],
    'disabled false' => ['false', false, 'http://lighthouse.test:3000/', 'http://gotenberg.test:3000/', ['lighthouse' => null, 'gotenberg' => null]],
    'disabled 0' => ['0', false, 'http://lighthouse.test:3000/', 'http://gotenberg.test:3000/', ['lighthouse' => null, 'gotenberg' => null]],
    'default service URLs' => ['true', true, null, null, ['lighthouse' => 'http://lighthouse:3000/health', 'gotenberg' => 'http://gotenberg:3000/health']],
]);

it('reads real Redis queue and Horizon state with operational thresholds and optional browser health', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00Z'));
    config(['database.redis.options.prefix' => 'health_'.Str::uuid().':', 'horizon.prefix' => 'health_'.Str::uuid().':']);
    Horizon::use(config('horizon.use'));
    Redis::purge('default');
    Redis::purge('cache');
    Redis::purge('horizon');
    config(['horizon.env' => 'workers', 'horizon.defaults' => ['worker-1' => ['connection' => 'redis', 'queue' => ['ignored-default']]], 'horizon.environments' => ['workers' => ['worker-1' => ['queue' => ['checks-fast']], 'worker-2' => ['connection' => 'redis', 'queue' => ['checks-fast']]], 'testing' => ['worker-1' => ['queue' => ['testing-only']]], 'production' => ['worker-1' => ['queue' => ['production-only']]]]]);
    Http::preventStrayRequests();
    Http::fake(['http://lighthouse.test/health' => Http::response('', 200), 'http://gotenberg.test/health' => Http::response('', 503)]);
    config(['heptapod.health.browser_urls' => ['lighthouse' => 'http://lighthouse.test/health', 'gotenberg' => 'http://gotenberg.test/health']]);
    $redis = Redis::connection('horizon');
    try {
        $redis->zadd('masters', now()->timestamp, 'test-master');
        $redis->hmset('master:test-master', ['name' => 'test-master', 'pid' => '1', 'status' => 'running', 'supervisors' => '["test-supervisor"]', 'environment' => 'workers']);
        $redis->zadd('supervisors', now()->timestamp, 'test-supervisor');
        $redis->hmset('supervisor:test-supervisor', ['name' => 'test-supervisor', 'master' => 'test-master', 'pid' => '2', 'status' => 'running', 'processes' => '{"redis:checks-fast":2}', 'options' => '{}']);
        for ($i = 0; $i < 100; $i++) {
            Redis::connection('default')->rpush('queues:checks-fast', '{}');
        }
        DispatcherHeartbeat::factory()->create(['last_seen_at' => now()->subSeconds(61)]);
        app(RecordSchedulerTick::class)();
        Cache::store('redis')->forever(config('heptapod.health.external_ping_cache_key'), '2026-10-01T11:59:00+00:00');
        DB::table('failed_jobs')->insert(['uuid' => Str::uuid(), 'connection' => 'redis', 'queue' => 'checks-fast', 'payload' => '{}', 'exception' => 'Old failure', 'failed_at' => now()->subDays(2)]);
        $data = app(ReadSystemHealth::class)();
        expect(array_keys($data->queues))->toBe(['redis:checks-fast']);
        expect($data->queues['redis:checks-fast']->status)->toBe(HealthStatus::Warn)->and($data->queues['redis:checks-fast']->value)->toBe(100)
            ->and($data->horizon->status)->toBe(HealthStatus::Ok)->and($data->horizon->value)->toBe(2)
            ->and($data->dispatcher->status)->toBe(HealthStatus::Warn)->and($data->scheduler->status)->toBe(HealthStatus::Ok)
            ->and($data->browserServices['lighthouse']->status)->toBe(HealthStatus::Ok)->and($data->browserServices['gotenberg']->status)->toBe(HealthStatus::Down)
            ->and($data->databaseBytes)->toBeGreaterThan(0)->and($data->failedJobsTotal)->toBe(1)->and($data->recentFailedJobs->status)->toBe(HealthStatus::Ok)
            ->and($data->lastExternalPing)->toBe('2026-10-01T11:59:00+00:00');
        for ($i = 0; $i < 900; $i++) {
            Redis::connection('default')->rpush('queues:checks-fast', '{}');
        }
        DB::table('failed_jobs')->insert(['uuid' => Str::uuid(), 'connection' => 'redis', 'queue' => 'checks-fast', 'payload' => '{}', 'exception' => 'Recent failure', 'failed_at' => now()]);
        $this->travel(91)->seconds();
        $data = app(ReadSystemHealth::class)();
        expect($data->queues['redis:checks-fast']->status)->toBe(HealthStatus::Down)->and($data->scheduler->status)->toBe(HealthStatus::Warn)
            ->and($data->recentFailedJobs->status)->toBe(HealthStatus::Warn)->and($data->recentFailedJobs->value)->toBe(1);
        $this->travel(90)->seconds();
        $redis->del('master:test-master', 'supervisor:test-supervisor');
        config(['heptapod.health.browser_urls' => ['lighthouse' => null]]);
        $data = app(ReadSystemHealth::class)();
        expect($data->horizon->status)->toBe(HealthStatus::Down)->and($data->dispatcher->status)->toBe(HealthStatus::Down)->and($data->scheduler->status)->toBe(HealthStatus::Down)
            ->and($data->browserServices['lighthouse']->status)->toBe(HealthStatus::NotConfigured);
    } finally {
        Queue::connection('redis')->clear('checks-fast');
        Cache::store('redis')->forget(config('heptapod.health.scheduler_cache_key'));
        Cache::store('redis')->forget(config('heptapod.health.external_ping_cache_key'));
        $redis->del('masters', 'supervisors', 'master:test-master', 'supervisor:test-supervisor');
    }
});
