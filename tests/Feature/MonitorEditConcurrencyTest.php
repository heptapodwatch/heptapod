<?php

declare(strict_types=1);

use Heptapod\Checks\Execution\ClaimService;
use Heptapod\Checks\Execution\RunOrigin;
use Heptapod\Models\Check;
use Heptapod\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;

it('serializes concurrent target edits with configuration edits or deletion without a deadlock', function (string $method): void {
    Queue::fake();
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $token = $owner->createToken('edit-race', ['*'])->plainTextToken;
    $check = Check::factory()->create();
    $site = $check->site;
    $site->update(['url' => 'https://edit-race.example/old']);
    expect(app(ClaimService::class)->runNow($check->id, RunOrigin::System))->not->toBeNull();
    $gate = random_int(200000, 900000000);
    $name = 'int1_edit_'.$gate;
    // Hold A after it locks a check. B then either waits for that check or takes the opposite site lock.
    DB::unprepared("CREATE FUNCTION {$name}_check() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN
        IF NEW.id = {$check->id} AND OLD.claim_token IS NOT NULL AND NEW.claim_token IS NULL THEN
            PERFORM pg_advisory_xact_lock({$gate});
        END IF; RETURN NEW; END \$\$;
        CREATE TRIGGER {$name}_check BEFORE UPDATE ON checks FOR EACH ROW EXECUTE FUNCTION {$name}_check();
        CREATE FUNCTION {$name}_site() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN
        IF TG_OP = 'DELETE' THEN
            IF OLD.id = {$site->id} THEN PERFORM pg_advisory_xact_lock(".($gate + 1)."); END IF;
            RETURN OLD;
        END IF;
        IF NEW.id = {$site->id} AND NEW.friendly_name = 'configuration-request' THEN
            PERFORM pg_advisory_xact_lock(".($gate + 1).");
        END IF; RETURN NEW; END \$\$;
        CREATE TRIGGER {$name}_site BEFORE UPDATE OR DELETE ON sites FOR EACH ROW EXECUTE FUNCTION {$name}_site()");
    DB::select('SELECT pg_advisory_lock(?), pg_advisory_lock(?)', [$gate, $gate + 1]);
    DB::commit();
    $locked = true;
    $processes = [];
    $script = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
        $kernel->bootstrap();
        Illuminate\Support\Facades\DB::statement("SET application_name = '".getenv('EDIT_CONNECTION')."'");
        $request = Illuminate\Http\Request::create('/api/monitors/'.getenv('EDIT_SITE'), getenv('EDIT_METHOD'), content: getenv('EDIT_BODY'));
        $request->headers->replace(['Authorization' => 'Bearer '.getenv('EDIT_TOKEN'), 'Accept' => 'application/json', 'Content-Type' => 'application/json']);
        $response = $kernel->handle($request);
        echo $response->getStatusCode();
        $kernel->terminate($request, $response);
        PHP;
    try {
        $bodies = [['url' => 'https://edit-race.example/new'], ['friendly_name' => 'configuration-request', 'uptime_check_settings' => ['http_client_headers' => [['name' => 'Authorization', 'value' => 'replacement-secret']]]]];
        // DELETE starts first and pauses before its cascade. PUT can then expose the opposite lock order.
        foreach ($method === 'DELETE' ? array_reverse($bodies, true) : $bodies as $index => $body) {
            $process = new Process([PHP_BINARY, '-r', $script], base_path(), [
                'APP_ENV' => 'testing', 'DB_DATABASE' => config('database.connections.pgsql.database'), 'DB_URL' => '',
                'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'EDIT_CONNECTION' => $name.'_'.$index,
                'EDIT_METHOD' => $index === 0 ? 'PUT' : $method, 'EDIT_SITE' => (string) $site->id, 'EDIT_TOKEN' => $token, 'EDIT_BODY' => json_encode($body, JSON_THROW_ON_ERROR),
            ], timeout: 30);
            $process->start();
            $processes[$index] = $process;
            $deadline = hrtime(true) + 20_000_000_000;
            do {
                $wait = DB::scalar("SELECT wait_event FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock'", [$name.'_'.$index]);
                if ($wait !== null) {
                    break;
                }
                usleep(10000);
            } while (hrtime(true) < $deadline && $process->isRunning());
            expect($wait)->not->toBeNull($process->getOutput().$process->getErrorOutput());
        }
        DB::select('SELECT pg_advisory_unlock(?), pg_advisory_unlock(?)', [$gate, $gate + 1]);
        $locked = false;
        foreach ($processes as $index => $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
            expect($process->getOutput())->toBe($method === 'DELETE' ? ($index === 1 ? '204' : '404') : '200');
        }
        if ($method === 'DELETE') {
            $this->assertModelMissing($site);
            $this->assertModelMissing($check);
        } else {
            expect($site->refresh()->url)->toBe('https://edit-race.example/new')
                ->and($site->friendly_name)->toBe('configuration-request')
                ->and($check->refresh()->executionConfig()['http_client_headers'][0]['value'])->toBe('replacement-secret');
        }
    } finally {
        if ($locked) {
            DB::select('SELECT pg_advisory_unlock(?), pg_advisory_unlock(?)', [$gate, $gate + 1]);
        }
        foreach ($processes as $process) {
            $process->stop(1);
        }
        DB::unprepared("DROP TRIGGER {$name}_check ON checks; DROP FUNCTION {$name}_check(); DROP TRIGGER {$name}_site ON sites; DROP FUNCTION {$name}_site()");
        $site->delete();
        $owner->tokens()->delete();
        $owner->delete();
        DB::beginTransaction();
    }
})->with(['PUT', 'DELETE']);
