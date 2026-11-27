<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Addons\Servers\Server;
use Heptapod\Addons\Servers\ServerIssue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../Pest.php';

/*
 * Real contention between separate database connections: two PHP processes store a sample for the same server while
 * this connection holds its row, so both pass every unlocked step before either may evaluate. Not a Gate test: it uses
 * subprocesses and committed data (scripts/check-gate-tests.php).
 */

/** Stores one sample through RecordServerReport in its own process and connection. */
function serverRaceProcess(int $serverId, string $token, string $observedAt): Process
{
    $script = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        Illuminate\Support\Facades\Queue::fake();
        $observed = Carbon\CarbonImmutable::parse(getenv('RACE_OBSERVED'));
        $report = ['sample_id' => (string) Illuminate\Support\Str::uuid(),
            'agent' => ['version' => '0.1.0', 'os' => 'linux', 'arch' => 'amd64', 'distro' => 'Debian 13', 'kernel' => '6.12'],
            'host' => ['hostname' => 'race', 'uptime_seconds' => 60, 'reboot_required' => false, 'ips' => []],
            'cpu' => ['cores' => 4, 'used_percent' => 10], 'load' => [1 => 0.5, 5 => 0.5, 15 => 0.5],
            'memory' => ['total_bytes' => 1000, 'available_bytes' => 50], 'filesystems' => [], 'updates' => ['pending' => 0, 'security' => 0]];
        $outcome = app(Heptapod\Addons\Servers\Actions\RecordServerReport::class)(Heptapod\Addons\Servers\ServerToken::parse(getenv('RACE_TOKEN')),
            (int) getenv('RACE_SERVER'), $report, $observed, Carbon\CarbonImmutable::now('UTC'));
        echo $outcome->name;
        PHP;

    return new Process([PHP_BINARY, '-r', $script], base_path(), [
        'APP_ENV' => 'testing', 'DB_DATABASE' => config('database.connections.pgsql.database'), 'DB_URL' => '',
        'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'BROADCAST_CONNECTION' => 'null',
        'RACE_SERVER' => (string) $serverId, 'RACE_TOKEN' => $token, 'RACE_OBSERVED' => $observedAt,
    ], timeout: 30);
}

it('keeps one open issue when two samples complete the same streak at once', function (): void {
    $server = Server::named('race-'.Str::lower(Str::random(6)));
    $token = $server->issueToken()->plainText();
    $now = CarbonImmutable::now('UTC');
    // Ten minutes of RAM at 95 % used, one sample a minute: the next sample at or above 90 % completes the 10-minute streak.
    for ($minutes = 10; $minutes >= 1; $minutes--) {
        DB::table('server_samples')->insert(['server_id' => $server->id, 'sample_id' => (string) Str::uuid(),
            'observed_at' => $now->subMinutes($minutes), 'received_at' => $now->subMinutes($minutes), 'metrics' => json_encode(['cpu' => ['cores' => 4, 'used_percent' => 10],
                'load' => ['1' => 0.5, '5' => 0.5, '15' => 0.5], 'memory' => ['total_bytes' => 1000, 'available_bytes' => 50], 'filesystems' => [],
                'host' => ['uptime_seconds' => 60, 'reboot_required' => false], 'updates' => ['pending' => 0, 'security' => 0]], JSON_THROW_ON_ERROR)]);
    }
    DB::commit();
    $processes = [serverRaceProcess($server->id, $token, $now->toIso8601String()), serverRaceProcess($server->id, $token, $now->addSecond()->toIso8601String())];
    $locked = false;
    try {
        DB::beginTransaction();
        DB::table('servers')->where('id', $server->id)->lockForUpdate()->first();
        $locked = true;
        foreach ($processes as $process) {
            $process->start();
        }
        $deadline = hrtime(true) + 20_000_000_000;
        do {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $waiting = (int) DB::scalar("SELECT count(*) FROM pg_stat_activity WHERE datname = current_database() AND pid <> pg_backend_pid() AND wait_event_type = 'Lock'");
            usleep(10000);
        } while ($waiting < count($processes) && hrtime(true) < $deadline);
        expect($waiting)->toBe(count($processes));
        DB::commit();
        $locked = false;
        $outputs = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput());
            $outputs[] = $process->getOutput();
        }

        expect($outputs)->toBe(['Stored', 'Stored'])
            ->and(ServerIssue::query()->where('server_id', $server->id)->whereNull('resolved_at')->pluck('kind')->map->value->all())->toBe(['memory_low'])
            ->and(DB::table('events')->whereIn('server_issue_id', ServerIssue::query()->where('server_id', $server->id)->select('id'))->count())->toBe(1);
    } finally {
        if ($locked) {
            DB::rollBack();
        }
        foreach ($processes as $process) {
            $process->stop(1);
        }
        $issues = ServerIssue::query()->where('server_id', $server->id)->pluck('id');
        DB::table('deliveries')->whereIn('event_id', DB::table('events')->select('id')->whereIn('server_issue_id', $issues))->delete();
        DB::table('events')->whereIn('server_issue_id', $issues)->delete();
        DB::table('server_issues')->whereIn('id', $issues)->delete();
        $server->delete();
    }
});
