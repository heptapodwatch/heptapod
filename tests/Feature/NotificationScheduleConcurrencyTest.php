<?php

declare(strict_types=1);

use Heptapod\Models\Client;
use Heptapod\Models\NotificationSchedule;
use Heptapod\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\Process;

it('serializes concurrent first-time schedule saves across two independent database sessions', function (): void {
    $roleIds = Role::query()->pluck('id')->all();
    $owner = User::factory()->owner()->create();
    $client = Client::factory()->create();
    $gate = random_int(100000, 900000000);
    $name = 'schedule_race_'.$gate;
    // Pause the first INSERT on this client so the second independent session arrives while the first transaction is open.
    DB::unprepared("CREATE FUNCTION {$name}() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN
        IF NEW.client_id = {$client->id} THEN PERFORM pg_advisory_xact_lock({$gate}); END IF; RETURN NEW; END \$\$;
        CREATE TRIGGER {$name} BEFORE INSERT ON notification_schedules FOR EACH ROW EXECUTE FUNCTION {$name}()");
    DB::select('SELECT pg_advisory_lock(?)', [$gate]);
    $locked = true;
    DB::commit();

    $script = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        Illuminate\Support\Facades\DB::statement("SET application_name = '".getenv('RACE_CONN')."'");
        $actor = Heptapod\Models\User::query()->findOrFail((int) getenv('RACE_USER'));
        $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
        $request = Illuminate\Http\Request::create(
            '/notification-schedules/client/'.getenv('RACE_CLIENT'),
            'PATCH',
            content: (string) getenv('RACE_BODY'),
        );
        $request->headers->replace(['Accept' => 'application/json', 'Content-Type' => 'application/json']);
        Illuminate\Support\Facades\Auth::login($actor);
        $response = $kernel->handle($request);
        echo json_encode(['status' => $response->getStatusCode()], JSON_THROW_ON_ERROR);
        $kernel->terminate($request, $response);
        PHP;

    $processes = [];
    $payloads = [
        'first' => json_encode(['digest_time' => '08:00'], JSON_THROW_ON_ERROR),
        'second' => json_encode(['quiet_start' => '22:00', 'quiet_end' => '06:00'], JSON_THROW_ON_ERROR),
    ];
    try {
        foreach ($payloads as $suffix => $body) {
            $conn = $name.'_'.$suffix;
            $process = new Process([PHP_BINARY, '-r', $script], base_path(), [
                'APP_ENV' => 'testing',
                'DB_DATABASE' => config('database.connections.pgsql.database'),
                'DB_URL' => '',
                'CACHE_STORE' => 'array',
                'QUEUE_CONNECTION' => 'sync',
                'RACE_CONN' => $conn,
                'RACE_USER' => (string) $owner->id,
                'RACE_CLIENT' => (string) $client->id,
                'RACE_BODY' => $body,
            ], timeout: 30);
            $process->start();
            $processes[$conn] = $process;
            $deadline = hrtime(true) + 20_000_000_000;
            do {
                $wait = DB::scalar("SELECT wait_event FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock'", [$conn]);
                if ($wait !== null) {
                    break;
                }
                usleep(10000);
            } while (hrtime(true) < $deadline && $process->isRunning());
            expect($wait)->not->toBeNull($process->getOutput().$process->getErrorOutput());
        }

        DB::select('SELECT pg_advisory_unlock(?)', [$gate]);
        $locked = false;

        $statuses = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput());
            $statuses[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['status'];
        }

        expect($statuses)->toBe([200, 200]);
        $schedule = NotificationSchedule::query()->where('client_id', $client->id)->sole();
        expect($schedule->digest_time)->toBe('08:00')
            ->and($schedule->quiet_start)->toBe('22:00')
            ->and($schedule->quiet_end)->toBe('06:00');
    } finally {
        if ($locked) {
            DB::select('SELECT pg_advisory_unlock(?)', [$gate]);
        }
        foreach ($processes as $process) {
            $process->stop(1);
        }
        DB::unprepared("DROP TRIGGER IF EXISTS {$name} ON notification_schedules; DROP FUNCTION IF EXISTS {$name}()");
        NotificationSchedule::query()->where('client_id', $client->id)->delete();
        $client->delete();
        $owner->delete();
        Role::query()->whereNotIn('id', $roleIds)->delete();
        DB::beginTransaction();
    }
});
