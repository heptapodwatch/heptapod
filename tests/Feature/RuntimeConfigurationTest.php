<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Checks\Execution\ClaimService;
use Heptapod\Checks\Execution\ExecutionBudget;
use Heptapod\Checks\Execution\RunOrigin;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Jobs\RunCheck;
use Heptapod\Models\Check;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-28T12:00:00Z'));
});

it('keeps worker deadlines below reservation and grants queued claims a separate stale window', function (): void {
    Queue::fake();
    expect(config('queue.connections.redis.retry_after'))->toBe(1260);
    foreach (['compose.yaml', 'compose.prod.yaml'] as $file) {
        $compose = Yaml::parseFile(base_path($file));
        expect($compose['services']['horizon']['stop_grace_period'])->toBe(ExecutionBudget::StopGrace.'s');
        if ($file === 'compose.prod.yaml') {
            expect($compose['services']['horizon']['mem_limit'])->toBe('${HORIZON_MEMORY_LIMIT:-1990m}')
                ->and($compose['services']['horizon']['memswap_limit'])->toBe('${HORIZON_MEMORY_LIMIT:-1990m}');
        }
    }
    expect(ExecutionBudget::StopGrace)->toBe(1245)->toBeGreaterThan(ExecutionBudget::HorizonTimeout);
    foreach (config('horizon.defaults') as $name => $supervisor) {
        if ($name === 'broadcasts') {
            expect($supervisor['timeout'])->toBeLessThan(config('queue.connections.redis-broadcasts.retry_after'));
        } elseif ($name === 'icons') {
            expect($supervisor['timeout'])->toBe(65)->toBeLessThan(config('queue.connections.redis.retry_after'));
        } else {
            expect($supervisor['timeout'])->toBe(1230)->toBeLessThan(config('queue.connections.redis.retry_after'));
        }
    }
    foreach (app(CheckRegistry::class)->keys() as $key) {
        $definition = app(CheckRegistry::class)->get($key)->definition();
        expect($definition->timeout)->toBeLessThanOrEqual(1200);
        expect(config('horizon.waits.redis:'.$definition->queue))->toBe($definition->queue === 'checks-fast' ? 60 : 1500);
        $check = Check::factory()->create(['type' => $key]);
        expect(app(ClaimService::class)->runNow($check->id, RunOrigin::System))->not->toBeNull();
        $check->refresh();
        expect($check->lease_expires_at->diffInSeconds($check->dispatched_at, true))->toBe((float) ExecutionBudget::queuedLease($definition->timeout, $definition->queue));
        Queue::assertPushed(RunCheck::class, function (RunCheck $job) use ($check, $definition): bool {
            if ($job->checkId !== $check->id) {
                return false;
            }
            expect($job->timeout)->toBe($definition->timeout + 15)->toBeLessThan(ExecutionBudget::HorizonTimeout)
                ->and($job->executionTimeout)->toBe($definition->timeout);

            return $job->checkId === $check->id && $job->queue === $definition->queue;
        });
    }
    $maximumJob = new RunCheck(1, 'maximum-budget-claim', 1200, 'checks-crawl');
    expect($maximumJob->timeout)->toBe(1215)->toBeLessThan(ExecutionBudget::HorizonTimeout)
        ->and(ExecutionBudget::lease(1200))->toBe(1290)
        ->and(config('heptapod.crawl.timeout_seconds'))->toBe(1200)
        ->and(config('horizon.defaults.checks-heavy.minProcesses'))->toBe(1)
        ->and(config('horizon.defaults.checks-heavy.maxProcesses'))->toBe(config('horizon.defaults.checks-heavy.minProcesses'))
        ->and(ExecutionBudget::queuedStaleTimeout('checks-fast'))->toBe(600)
        ->and(ExecutionBudget::queuedStaleTimeout('checks-crawl'))->toBe(1500);
});

it('boots Artisan without Reverb credentials', function (): void {
    $process = new Process([PHP_BINARY, 'artisan', 'list', '--raw', '--no-interaction'], base_path(), [
        'BROADCAST_CONNECTION' => 'reverb',
        'REVERB_APP_ID' => false,
        'REVERB_APP_KEY' => false,
        'REVERB_APP_SECRET' => false,
    ]);
    $process->mustRun();
    expect($process->getOutput())->toContain('package:discover');
});

it('runs the CI route and type generators without Reverb credentials', function (): void {
    $environment = [
        'APP_ENV' => 'local',
        'BROADCAST_CONNECTION' => 'reverb',
        'REVERB_APP_ID' => false,
        'REVERB_APP_KEY' => false,
        'REVERB_APP_SECRET' => false,
    ];
    foreach ([
        [PHP_BINARY, 'artisan', 'route:list', '--no-interaction'],
        [PHP_BINARY, 'artisan', 'wayfinder:generate', '--with-form', '--no-interaction'],
        [PHP_BINARY, 'artisan', 'typescript:transform', '--no-interaction'],
        [PHP_BINARY, 'artisan', 'boost:update', '--no-interaction'],
    ] as $command) {
        $process = new Process($command, base_path(), $environment)->mustRun();
        expect($process->getExitCode())->toBe(0);
    }
});

it('returns a configured-service error when broadcast authorization has no Reverb credentials', function (): void {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => null,
        'broadcasting.connections.reverb.secret' => null,
        'broadcasting.connections.reverb.app_id' => null,
    ]);
    $this->postJson('/broadcasting/auth', ['socket_id' => '1.1', 'channel_name' => 'presence-instance'])
        ->assertServiceUnavailable()->assertJsonPath('message', 'Live updates are not configured.');

    config(['broadcasting.default' => null]);
    $this->postJson('/broadcasting/auth', ['socket_id' => '1.1', 'channel_name' => 'presence-instance'])
        ->assertServiceUnavailable()->assertJsonPath('message', 'Live updates are not configured.');
});

it('restricts Reverb origins to the application host and explicitly trusted extras', function (): void {
    $script = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        echo json_encode(['origins' => config('reverb.apps.apps.0.allowed_origins'), 'limit' => config('reverb.apps.apps.0.max_connections')], JSON_THROW_ON_ERROR);
        PHP;
    $options = ['APP_URL' => 'https://origin.example.test:8443', 'REVERB_APP_ALLOWED_ORIGINS' => '', 'REVERB_APP_MAX_CONNECTIONS' => '500'];
    $default = new Process([PHP_BINARY, '-r', $script], base_path(), $options);
    $default->mustRun();
    expect(json_decode($default->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe(['origins' => ['origin.example.test'], 'limit' => 500]);

    $extra = new Process([PHP_BINARY, '-r', $script], base_path(), [...$options, 'REVERB_APP_ALLOWED_ORIGINS' => 'trusted.example.test, second.example.test']);
    $extra->mustRun();
    expect(json_decode($extra->getOutput(), true, flags: JSON_THROW_ON_ERROR)['origins'])->toBe(['origin.example.test', 'trusted.example.test', 'second.example.test']);

    $wildcard = new Process([PHP_BINARY, '-r', $script], base_path(), [...$options, 'REVERB_APP_ALLOWED_ORIGINS' => '*']);
    $wildcard->run();
    expect($wildcard->isSuccessful())->toBeFalse()->and($wildcard->getOutput().$wildcard->getErrorOutput())->toContain('Reverb allowed origins cannot contain *');
});

it('has no registration entry point in a separately booted production application', function (): void {
    $script = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
        foreach (['GET', 'POST'] as $method) {
            $request = Illuminate\Http\Request::create('/register', $method);
            $request->headers->set('Accept', 'application/json');
            $response = $kernel->handle($request);
            echo $method.':'.$response->getStatusCode()."\n";
            $kernel->terminate($request, $response);
        }
        PHP;
    $process = new Process([PHP_BINARY, '-r', $script], base_path(), ['APP_ENV' => 'production', 'APP_DEBUG' => 'false']);
    $process->mustRun();
    expect($process->getOutput())->toBe("GET:404\nPOST:404\n");
});
