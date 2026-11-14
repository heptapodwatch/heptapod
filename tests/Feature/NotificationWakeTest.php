<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Checks\Recording\RecordableRun;
use Heptapod\Checks\Recording\ResultRecorder;
use Heptapod\Contracts\CheckError;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckOutcome;
use Heptapod\Contracts\Notifications\ChannelRegistry;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Contracts\RunResult;
use Heptapod\Jobs\DispatchNotifications;
use Heptapod\Jobs\SendNotification;
use Heptapod\Models\Check;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\NotificationDestination;
use Heptapod\Testing\ClaimedRun;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as FrameworkEvent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require_once __DIR__.'/../Support/NotificationChannels.php';

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-28T12:00:00Z'));
});

/** Isolate real Redis queues and unique locks from other tests and Horizon. */
function notificationWakeQueue(): void
{
    config([
        'database.redis.notification_wake' => [...config('database.redis.default'), 'prefix' => 'notification-wake-'.Str::uuid().':'],
        'queue.default' => 'redis', 'queue.connections.redis.connection' => 'notification_wake',
        'cache.default' => 'notification_wake',
        'cache.stores.notification_wake' => ['driver' => 'redis', 'connection' => 'notification_wake', 'lock_connection' => 'notification_wake'],
    ]);
}

it('delivers committed incidents with a queued wake-up or the scheduler safety scan for late arrivals', function (bool $lateArrival): void {
    notificationWakeQueue();
    Http::preventStrayRequests();
    Http::fake(['https://receiver.test/wake' => Http::response([], 200)]);
    $destination = NotificationDestination::factory()->create(['channel' => 'webhook',
        'config' => app(ChannelRegistry::class)->get('webhook')->configSchema()->validateConfig(['url' => 'https://receiver.test/wake', 'secret' => 'wake-secret'])]);
    notificationRule($destination, 'check.failing');
    $check = Check::factory()->create(['failure_threshold' => 1]);
    $check->site->update(['alert_after_minutes' => 1, 'alert_recovery_checks' => 1, 'alert_confirms_other_checks' => false, 'alert_reminders' => 'standard']);
    $context = ClaimedRun::start($check, CarbonImmutable::now('UTC')->subSecond());
    $clientId = $check->site->client_id;
    $run = new RecordableRun($check->id, $context->claimToken, $context->startedAt, CarbonImmutable::now('UTC'),
        new CheckOutcome(RunResult::Errored, 'checks.engine.errored', [], null, error: new CheckError(LogicException::class, 'Engine error')));
    DB::commit();
    // RefreshDatabase runs afterCommit before its wrapping transaction commits.
    // Use the production manager here to exercise real commit and rollback boundaries.
    DB::connection()->setTransactionManager(new DatabaseTransactionsManager);
    try {
        DB::beginTransaction();
        app(ResultRecorder::class)->record($run);
        DB::rollBack();
        expect(Event::query()->count())->toBe(0);
        expect(Queue::connection('redis')->size('notifications'))->toBe(0);
        DB::beginTransaction();
        app(ResultRecorder::class)->record($run);
        expect(Queue::connection('redis')->size('notifications'))->toBe(0);
        Http::assertNothingSent();
        DB::commit();
        expect(Queue::connection('redis')->size('notifications'))->toBe(1);
        $lateEventId = null;
        if ($lateArrival) {
            // Delivery enqueue happens after the scan's event snapshot, while its unique lock is held.
            FrameworkEvent::listen(JobQueued::class, function (JobQueued $queued) use ($check, $run, &$lateEventId): void {
                if (! $queued->job instanceof SendNotification || $lateEventId !== null) {
                    return;
                }
                $lateCheck = Check::factory()->create(['site_id' => $check->site_id, 'type' => CheckKey::Performance, 'failure_threshold' => 1]);
                $lateContext = ClaimedRun::start($lateCheck, $run->startedAt);
                app(ResultRecorder::class)->record(new RecordableRun($lateCheck->id, $lateContext->claimToken, $run->startedAt, $run->endedAt, $run->outcome));
                $lateEventId = Event::query()->where('check_id', $lateCheck->id)->where('type', 'incident.opened')->sole()->id;
            });
        }
        $this->artisan('queue:work', ['connection' => 'redis', '--queue' => 'notifications', '--once' => true])->assertSuccessful();
        expect(Queue::connection('redis')->size('notifications'))->toBe(1);
        $this->artisan('queue:work', ['connection' => 'redis', '--queue' => 'notifications', '--once' => true])->assertSuccessful();
        expect(Delivery::query()->sole()->state)->toBe(DeliveryState::Sent);
        Http::assertSentCount(1);
        if ($lateArrival) {
            expect($lateEventId)->not->toBeNull();
            expect(Event::query()->findOrFail($lateEventId)->notification_routed_at)->toBeNull();
            expect(Queue::connection('redis')->size('notifications'))->toBe(0);
            $safetyScan = collect(app(Schedule::class)->events())->sole(fn (ScheduledEvent $event): bool => $event->description === DispatchNotifications::class);
            $safetyScan->run(app());
            $this->artisan('queue:work', ['connection' => 'redis', '--queue' => 'notifications', '--once' => true])->assertSuccessful();
            $this->artisan('queue:work', ['connection' => 'redis', '--queue' => 'notifications', '--once' => true])->assertSuccessful();
            expect(Delivery::query()->where('event_id', $lateEventId)->sole()->state)->toBe(DeliveryState::Sent);
            Http::assertSentCount(2);
        }
    } finally {
        Queue::connection('redis')->clear('notifications');
        Cache::forget('laravel_unique_job:'.DispatchNotifications::class.':');
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DB::table('sites')->where('id', $check->site_id)->delete();
        DB::table('clients')->where('id', $clientId)->where('is_internal', false)->delete();
        DB::table('deliveries')->where('destination_id', $destination->id)->delete();
        DB::table('notification_preferences')->where('destination_id', $destination->id)->delete();
        DB::table('notification_destinations')->where('id', $destination->id)->delete();
        DB::connection()->setTransactionManager(app('db.transactions'));
        DB::beginTransaction();
    }
})->with(['no scheduler tick' => false, 'arrival during active scan' => true]);

it('coalesces notification scans until the queued scan completes then accepts another wake-up', function (): void {
    notificationWakeQueue();
    try {
        DispatchNotifications::dispatch();
        DispatchNotifications::dispatch();
        expect(Queue::connection('redis')->size('notifications'))->toBe(1);
        $this->artisan('queue:work', ['connection' => 'redis', '--queue' => 'notifications', '--once' => true])->assertSuccessful();
        DispatchNotifications::dispatch();
        expect(Queue::connection('redis')->size('notifications'))->toBe(1);
    } finally {
        Queue::connection('redis')->clear('notifications');
        Cache::forget('laravel_unique_job:'.DispatchNotifications::class.':');
    }
});
