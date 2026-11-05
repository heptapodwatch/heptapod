<?php

declare(strict_types=1);

use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\Process;

it('returns a validated 422 when concurrent monitor inserts pass URL validation together', function (): void {
    $roleIds = Role::query()->pluck('id')->all();
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $token = $owner->createToken('race', ['*'])->plainTextToken;
    $key = random_int(100000, 2000000000);
    $trigger = 'wp7_race_'.$key;
    $url = 'https://race-'.$key.'.example';
    // A real database barrier proves both requests passed validation before either inserts.
    DB::unprepared("CREATE FUNCTION {$trigger}() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.url = '{$url}' THEN PERFORM pg_advisory_xact_lock({$key}); END IF; RETURN NEW; END \$\$;
        CREATE TRIGGER {$trigger} BEFORE INSERT ON sites FOR EACH ROW EXECUTE FUNCTION {$trigger}()");
    DB::select('SELECT pg_advisory_lock(?)', [$key]);
    $locked = true;
    DB::commit();
    $script = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
        $request = Illuminate\Http\Request::create('/api/monitors', 'POST', content: json_encode(['url' => getenv('RACE_URL'), 'team_id' => 1, 'type' => 'http', 'checks' => []], JSON_THROW_ON_ERROR));
        $request->headers->replace(['Authorization' => 'Bearer '.getenv('RACE_TOKEN'), 'Accept' => 'application/json', 'Content-Type' => 'application/json']);
        $response = $kernel->handle($request);
        app(Heptapod\Testing\ApiSpec::class)->validate('POST', '/api/monitors', $response);
        echo json_encode(['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)], JSON_THROW_ON_ERROR);
        $kernel->terminate($request, $response);
        PHP;
    $processes = [];
    try {
        for ($i = 0; $i < 2; $i++) {
            $process = new Process([PHP_BINARY, '-r', $script], base_path(), [
                'APP_ENV' => 'testing', 'DB_DATABASE' => config('database.connections.pgsql.database'), 'DB_URL' => '',
                'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'RACE_URL' => $url, 'RACE_TOKEN' => $token,
            ], timeout: 30);
            $process->start();
            $processes[] = $process;
        }
        $deadline = hrtime(true) + 20_000_000_000;
        do {
            $waiting = (int) DB::scalar("SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND objid = ? AND NOT granted AND database = (SELECT oid FROM pg_database WHERE datname = current_database())", [$key]);
            if ($waiting === 2) {
                break;
            }
            usleep(10000);
        } while (hrtime(true) < $deadline && $processes[0]->isRunning() && $processes[1]->isRunning());
        expect($waiting)->toBe(2, implode('\n', array_map(fn (Process $process): string => $process->getErrorOutput(), $processes)));
        DB::select('SELECT pg_advisory_unlock(?)', [$key]);
        $locked = false;
        $responses = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput());
            $responses[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }
        $statuses = array_column($responses, 'status');
        sort($statuses);
        expect($statuses)->toBe([201, 422])
            ->and(Site::query()->where('url', $url)->count())->toBe(1);
        $rejected = collect($responses)->firstWhere('status', 422);
        expect($rejected['body']['errors']['url'])->not->toBeEmpty();
    } finally {
        if ($locked) {
            DB::select('SELECT pg_advisory_unlock(?)', [$key]);
        }
        foreach ($processes as $process) {
            $process->stop(1);
        }
        DB::unprepared("DROP TRIGGER {$trigger} ON sites; DROP FUNCTION {$trigger}()");
        Site::query()->where('url', $url)->delete();
        $owner->tokens()->delete();
        $owner->delete();
        Role::query()->whereNotIn('id', $roleIds)->delete();
        DB::beginTransaction();
    }
});
