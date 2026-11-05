<?php

declare(strict_types=1);

use Heptapod\Models\Check;
use Heptapod\Models\Client;
use Heptapod\Models\Event;
use Heptapod\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../Support/ClientMessageRace.php';

/*
 * Real contention between separate database connections: two PHP processes send while this connection holds the
 * client row lock, so both pass every unlocked check before either may commit. Not a Gate test: it uses subprocesses
 * and committed data, which the Gate determinism guard forbids (scripts/check-gate-tests.php).
 */

function clientMessageRaceProcess(User $owner, Client $client, array $payload, string $key): Process
{
    $script = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        Illuminate\Support\Facades\Queue::fake();
        $owner = Heptapod\Models\User::findOrFail((int) getenv('RACE_OWNER'));
        $client = Heptapod\Models\Client::findOrFail((int) getenv('RACE_CLIENT'));
        try {
            $result = app(Heptapod\Actions\SendClientMessage::class)->send($owner, $client, json_decode(getenv('RACE_PAYLOAD'), true), getenv('RACE_KEY'));
            echo $result['replayed'] ? 'replayed:'.$result['event_id'] : 'created:'.$result['event_id'];
        } catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            echo 'http:'.$exception->getStatusCode();
        }
        PHP;

    return new Process([PHP_BINARY, '-r', $script], base_path(), [
        'APP_ENV' => 'testing', 'DB_DATABASE' => config('database.connections.pgsql.database'), 'DB_URL' => '',
        'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'BROADCAST_CONNECTION' => 'null', 'FILESYSTEM_DISK' => 'local',
        'RACE_OWNER' => (string) $owner->id, 'RACE_CLIENT' => (string) $client->id, 'RACE_PAYLOAD' => json_encode($payload, JSON_THROW_ON_ERROR), 'RACE_KEY' => $key,
    ], timeout: 30);
}

/** Holds the client row lock until both processes wait on it, then lets them run and returns their outputs. */
function clientMessageRace(Client $client, Process ...$processes): array
{
    $locked = false;
    try {
        DB::beginTransaction();
        DB::table('clients')->where('id', $client->id)->lockForUpdate()->first();
        $locked = true;
        foreach ($processes as $process) {
            $process->start();
        }
        $deadline = hrtime(true) + 20_000_000_000;
        do {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $waiting = (int) DB::scalar("SELECT count(*) FROM pg_stat_activity WHERE datname = current_database() AND pid <> pg_backend_pid() AND wait_event_type = 'Lock'");
            if ($waiting >= 1 && array_all($processes, static fn (Process $process): bool => $process->isRunning())) {
                // The first waits on the client row; the second on the sender lock the first holds, or on the same row.
                if ($waiting >= count($processes)) {
                    break;
                }
            }
            usleep(10000);
        } while (hrtime(true) < $deadline);
        expect($waiting)->toBeGreaterThanOrEqual(count($processes), implode("\n", array_map(static fn (Process $process): string => $process->getOutput().$process->getErrorOutput(), $processes)));
        DB::commit();
        $locked = false;
        $outputs = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput());
            $outputs[] = $process->getOutput();
        }
        sort($outputs);

        return $outputs;
    } finally {
        if ($locked) {
            DB::rollBack();
        }
        foreach ($processes as $process) {
            $process->stop(1);
        }
    }
}

it('lets only one of two concurrent sends with different keys take the last client quota slot', function (): void {
    $fixture = clientMessageRaceFixture(alreadySent: 9);
    try {
        $outputs = clientMessageRace($fixture['client'],
            clientMessageRaceProcess($fixture['owner'], $fixture['client'], $fixture['payload'], 'race-a'),
            clientMessageRaceProcess($fixture['owner'], $fixture['client'], $fixture['payload'], 'race-b'));
        expect($outputs[1])->toBe('http:429')->and($outputs[0])->toStartWith('created:')
            ->and(Event::query()->where('client_id', $fixture['client']->id)->count())->toBe(10);
    } finally {
        ($fixture['cleanup'])();
    }
});

it('turns two concurrent sends with the same key into one message and one replay', function (): void {
    $fixture = clientMessageRaceFixture(alreadySent: 0);
    try {
        $outputs = clientMessageRace($fixture['client'],
            clientMessageRaceProcess($fixture['owner'], $fixture['client'], $fixture['payload'], 'same-key'),
            clientMessageRaceProcess($fixture['owner'], $fixture['client'], $fixture['payload'], 'same-key'));
        $created = (int) substr($outputs[0], strlen('created:'));
        expect($outputs[0])->toStartWith('created:')->and($outputs[1])->toBe('replayed:'.$created)
            ->and(Event::query()->where('client_id', $fixture['client']->id)->count())->toBe(1)
            ->and(DB::table('deliveries')->where('event_id', $created)->count())->toBe(1);
    } finally {
        ($fixture['cleanup'])();
    }
});
