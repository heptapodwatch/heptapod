<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/** @param list<string> $arguments */
function statementTimeoutProbe(string $role, bool $console, array $arguments = []): Process
{
    /** @var array{host: string, port: string, database: string, username: string, password: string} $database */
    $database = config('database.connections.pgsql');
    $url = sprintf('postgres://%s:%s@%s:%s/%s', rawurlencode($database['username']), rawurlencode($database['password']), $database['host'], $database['port'], $database['database']);

    $process = new Process([PHP_BINARY, '-r', <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $console = $app->runningInConsole();
        $kernel = $app->make($console ? Illuminate\Contracts\Console\Kernel::class : Illuminate\Contracts\Http\Kernel::class);
        $kernel->bootstrap();
        $timeout = Illuminate\Support\Facades\DB::scalar('SHOW statement_timeout');
        if ($console) {
            $input = new Symfony\Component\Console\Input\ArgvInput();
            if ($input->getFirstArgument() === 'migrate') {
                $status = $kernel->handle(new Symfony\Component\Console\Input\ArgvInput([...$argv, '--pretend']), new Symfony\Component\Console\Output\BufferedOutput());
                if ($status !== 0) {
                    exit($status);
                }
            }
            Illuminate\Support\Facades\DB::select('SELECT pg_sleep(0.2)');
            echo json_encode(['timeout' => $timeout], JSON_THROW_ON_ERROR);
            exit;
        }
        Illuminate\Support\Facades\Route::get('/statement-timeout-probe', static function (): Illuminate\Http\Response {
            Illuminate\Support\Facades\DB::select('SELECT pg_sleep(11)');
            return response('Unexpected success');
        });
        $request = Illuminate\Http\Request::create('/statement-timeout-probe', server: ['HTTP_ACCEPT' => 'application/json']);
        $started = microtime(true);
        $response = $kernel->handle($request);
        echo json_encode(['timeout' => $timeout, 'status' => $response->getStatusCode(), 'body' => $response->getContent(), 'elapsed' => microtime(true) - $started], JSON_THROW_ON_ERROR);
        PHP, '--', ...$arguments], base_path(), [
        'APP_ENV' => 'testing',
        'APP_DEBUG' => 'true',
        'APP_RUNNING_IN_CONSOLE' => $console ? 'true' : 'false',
        'DB_URL' => $url,
        'HEPTAPOD_ROLE' => $role,
        'DB_TEST_STATEMENT_TIMEOUT' => null,
    ], timeout: 20);
    $process->run();

    return $process;
}

it('caps test database statements so stalled plans fail loudly', function (): void {
    expect(DB::scalar('SHOW statement_timeout'))->toBe('30s');
});

it('fails a slow web query loudly within ten seconds with DB_URL', function (): void {
    $process = statementTimeoutProbe('web', false);
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
    $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect($result['timeout'])->toBe('10s')
        ->and($result['status'])->toBe(500)
        ->and($result['body'])->toContain('statement timeout', '57014')
        ->and($result['elapsed'])->toBeGreaterThanOrEqual(9.0)->toBeLessThan(15.0);
});

it('limits only known background runtime commands and leaves administrative CLI unbounded', function (string $role, array $arguments, string $timeout): void {
    $process = statementTimeoutProbe($role, true, $arguments);
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
    expect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe(['timeout' => $timeout]);
})->with([
    'web container administrative CLI' => ['web', [], '0'],
    'worker container administrative CLI' => ['worker', [], '0'],
    'worker import' => ['worker', ['heptapod:import-ohdear'], '0'],
    'horizon administration' => ['worker', ['horizon:purge'], '0'],
    'horizon' => ['worker', ['horizon'], '2min'],
    'horizon supervisor' => ['worker', ['horizon:supervisor', 'default', 'redis'], '2min'],
    'horizon worker' => ['worker', ['horizon:work', 'redis'], '2min'],
    'queue worker with leading option' => ['worker', ['--no-interaction', 'queue:work'], '2min'],
    'dispatcher' => ['dispatcher', ['heptapod:dispatch'], '2min'],
    'schedule worker' => ['scheduler', ['schedule:work'], '2min'],
    'schedule runner' => ['scheduler', ['schedule:run'], '2min'],
    'all in one worker' => ['all-in-one-worker', ['horizon'], '2min'],
    'scheduled child without a reliable provenance marker' => ['scheduler', ['heptapod:maintain-check-data'], '0'],
    'migration with leading option' => ['worker', ['--no-interaction', 'migrate'], '0'],
    'migration with trailing option' => ['worker', ['migrate', '--force'], '0'],
]);
