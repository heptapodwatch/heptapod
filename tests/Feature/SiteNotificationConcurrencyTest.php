<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Actions\PlanNotificationEvent;
use Heptapod\Contracts\CheckResult;
use Heptapod\Models\Check;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\NotificationDestination;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

it('serializes two digest ticks on the same slot', function (string $scenario): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05T00:30:00Z'));
    $check = Check::factory()->create();
    $site = $check->site;
    $client = $site->client;
    $destination = NotificationDestination::factory()->create();
    DB::table('notification_preferences')->where('destination_id', $destination->id)->where('category', 'warning')->update(['mode' => 'digest']);
    $check->update(['latest_run_result' => CheckResult::Warning]);
    $event = Event::factory()->create(['site_id' => $site->id, 'check_id' => $check->id,
        'type' => 'check.result_changed', 'to_result' => CheckResult::Warning,
        'cause' => 'failed']);
    app(PlanNotificationEvent::class)($event->id);
    $delivery = Delivery::query()->where('event_id', $event->id)->sole();
    $gate = random_int(100000, 900000000);
    $name = 'notify_race_'.$gate;
    DB::unprepared("CREATE FUNCTION {$name}() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN
        IF NEW.type = 'notification.digest' AND NEW.payload->'delivery_ids' @> '[{$delivery->id}]'::jsonb THEN
            PERFORM pg_advisory_xact_lock({$gate}); END IF; RETURN NEW; END \$\$;
        CREATE TRIGGER {$name} BEFORE INSERT ON events FOR EACH ROW EXECUTE FUNCTION {$name}()");
    DB::select('SELECT pg_advisory_lock(?)', [$gate]);
    $locked = true;
    DB::commit();
    $script = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        Carbon\CarbonImmutable::setTestNow('2026-10-05T08:00:00Z');
        Illuminate\Support\Facades\DB::statement("SET application_name = '".getenv('NOTIFY_CONNECTION')."'");
        app(Heptapod\Actions\EmitNotificationDigests::class)();
        echo 'done';
        PHP;
    $processes = [];
    try {
        foreach (['first', 'second'] as $index) {
            $process = new Process([PHP_BINARY, '-r', $script], base_path(), [
                'APP_ENV' => 'testing', 'DB_DATABASE' => config('database.connections.pgsql.database'), 'DB_URL' => '',
                'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'NOTIFY_CONNECTION' => $name.'_'.$index,
            ], timeout: 30);
            $process->start();
            $processes[] = $process;
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
        DB::select('SELECT pg_advisory_unlock(?)', [$gate]);
        $locked = false;
        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput());
        }
        expect(Event::query()->where('type', 'notification.digest')->whereIn('id', DB::table('deliveries')->where('destination_id', $destination->id)->pluck('event_id'))->count())->toBe(1);
    } finally {
        if ($locked) {
            DB::select('SELECT pg_advisory_unlock(?)', [$gate]);
        }
        foreach ($processes as $process) {
            $process->stop(1);
        }
        DB::unprepared("DROP TRIGGER {$name} ON events; DROP FUNCTION {$name}()");
        DB::table('deliveries')->where('id', $delivery->id)->update(['digest_event_id' => null]);
        Event::query()->where('type', 'notification.digest')->whereIn('id', DB::table('deliveries')->where('destination_id', $destination->id)->pluck('event_id'))->delete();
        DB::table('deliveries')->where('destination_id', $destination->id)->delete();
        $event->delete();
        $check->delete();
        $site->delete();
        $client->delete();
        $destination->delete();
        DB::beginTransaction();
    }
})->with(['digest']);
