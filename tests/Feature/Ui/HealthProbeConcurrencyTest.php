<?php

declare(strict_types=1);

use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Models\Check;
use Heptapod\Models\User;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

it('limits concurrent health save requests before any outbound probe is dispatched', function (): void {
    $owner = User::factory()->create(['id' => random_int(10000000, 999999999)]);
    $owner->assignRole('Owner');
    $schema = app(CheckRegistry::class)->get(CheckKey::ApplicationHealth)->definition()->configSchema;
    $check = Check::factory()->create(['type' => CheckKey::ApplicationHealth, 'config' => $schema->validateConfig([
        'result_url' => 'https://health.example.test/old', 'secret' => 'stored-secret',
    ])]);
    $site = $check->site()->getQuery()->firstOrFail();
    $client = $site->client()->getQuery()->firstOrFail();
    $marker = 'health-probe-race:'.random_int(1000000, 999999999);
    $key = 'health-check-probe:'.$owner->id;
    $limiter = new RateLimiter(Cache::store('redis'));
    $limiter->clear($key);
    Cache::store('redis')->put($marker, 0, 60);
    DB::commit();
    $script = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
        $kernel->bootstrap();
        $fake = new Heptapod\Testing\FakeOutboundHttp;
        $fake->respondUsing(static function (Heptapod\Contracts\Http\FetchRequest $request): Heptapod\Contracts\Http\FetchResult {
            Illuminate\Support\Facades\Cache::increment(getenv('HEALTH_MARKER'));
            usleep(600000);
            return new Heptapod\Contracts\Http\FetchResult(200,
                body: json_encode(['finishedAt' => time(), 'checkResults' => []], JSON_THROW_ON_ERROR), effectiveUrl: $request->url);
        });
        $app->instance(Heptapod\Contracts\Http\OutboundHttp::class, $fake);
        $request = Illuminate\Http\Request::create('/sites/'.getenv('HEALTH_SITE').'/checks/'.getenv('HEALTH_CHECK'), 'PATCH',
            content: json_encode(['result_url' => 'https://health.example.test/new', 'secret' => 'new-secret'], JSON_THROW_ON_ERROR));
        $request->headers->replace(['Accept' => 'application/json', 'Content-Type' => 'application/json']);
        $app->instance('request', $request);
        Illuminate\Support\Facades\Auth::loginUsingId((int) getenv('HEALTH_USER'));
        $response = $kernel->handle($request);
        echo $response->getStatusCode();
        $kernel->terminate($request, $response);
        PHP;
    $processes = [];
    try {
        for ($index = 0; $index < 8; $index++) {
            $process = new Process([PHP_BINARY, '-r', $script], base_path(), [
                'APP_ENV' => 'testing', 'DB_DATABASE' => config('database.connections.pgsql.database'), 'DB_URL' => '',
                'CACHE_STORE' => 'redis', 'CACHE_PREFIX' => config('cache.prefix'), 'QUEUE_CONNECTION' => 'sync', 'HEALTH_USER' => (string) $owner->id,
                'HEALTH_SITE' => (string) $site->id, 'HEALTH_CHECK' => (string) $check->id, 'HEALTH_MARKER' => $marker,
            ], timeout: 30);
            $process->start();
            $processes[] = $process;
        }
        $statuses = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput());
            $statuses[] = (int) $process->getOutput();
        }
        sort($statuses);
        expect($statuses)->toContain(429)
            ->not->toContain(401, 403, 500)
            ->and((int) Cache::store('redis')->get($marker))->toBe(6);
    } finally {
        foreach ($processes as $process) {
            $process->stop(1);
        }
        $limiter->clear($key);
        Cache::store('redis')->forget($marker);
        $check->delete();
        $site->delete();
        $client->delete();
        $owner->tokens()->delete();
        $owner->delete();
        DB::beginTransaction();
    }
});
