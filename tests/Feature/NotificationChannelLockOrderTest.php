<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Actions\ArchiveClient;
use Heptapod\Actions\ArchiveNotificationChannel;
use Heptapod\Actions\ClaimNotificationDelivery;
use Heptapod\Actions\CompleteNotificationDelivery;
use Heptapod\Actions\DeleteProfile;
use Heptapod\Actions\DeleteSite;
use Heptapod\Actions\EmitIncidentReminders;
use Heptapod\Actions\EmitMaintenanceCatchUps;
use Heptapod\Actions\EmitNotificationDigests;
use Heptapod\Actions\EnsureAccountChannel;
use Heptapod\Actions\GenerateMaintenancePeriods;
use Heptapod\Actions\HandleDeferredUptimeOpening;
use Heptapod\Actions\PlanNotificationEvent;
use Heptapod\Actions\RequestRoutingReconciliation;
use Heptapod\Actions\SendClientMessage;
use Heptapod\Addons\Cron\CronCheckType;
use Heptapod\Addons\Cron\CronDefinitions;
use Heptapod\Addons\Uptime\UptimeMetrics;
use Heptapod\Checks\Execution\CheckExecutor;
use Heptapod\Checks\Execution\ClaimService;
use Heptapod\Checks\Execution\RunOrigin;
use Heptapod\Checks\Recording\RecordableRun;
use Heptapod\Checks\Recording\RecordedRun;
use Heptapod\Checks\Recording\ResultRecorder;
use Heptapod\Contracts\AvailabilityFailure;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckOutcome;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\Notifications\DeliveryResult;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Contracts\RunPhase;
use Heptapod\Contracts\RunResult;
use Heptapod\Enums\IncidentCause;
use Heptapod\Enums\NotificationCategory;
use Heptapod\Enums\NotificationMode;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Jobs\DispatchNotifications;
use Heptapod\Jobs\RunCheck;
use Heptapod\Jobs\SendNotification;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\CheckRunAttempt;
use Heptapod\Models\Client;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\RecurringMaintenancePeriod;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Testing\ClaimedRun;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../Support/ClientMessageRace.php';

/*
 * One lock order for every path that touches a channel and its deliveries: channel first, then delivery. Archiving a
 * channel holds the channel and suppresses its deliveries; claim and completion therefore lock the channel before the
 * delivery. Each case runs the real action in a second process while this connection archives the same channel, and
 * starts the archive's delivery update only once the other process waits on a lock. Not a Gate test: subprocesses and
 * committed data (scripts/check-gate-tests.php). A deadlock surfaces as SQLSTATE 40P01 on either side.
 */

/** Runs $script in a PHP process against the test database; RACE_DELIVERY and RACE_TOKEN are passed through. */
function channelLockProcess(string $script, int $deliveryId, string $token = ''): Process
{
    $boot = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        Illuminate\Support\Facades\Queue::fake();
        Illuminate\Support\Facades\Mail::fake();
        try {
        PHP;
    $end = <<<'PHP'
        } catch (Illuminate\Database\QueryException $exception) {
            echo str_contains($exception->getMessage(), '40P01') ? 'deadlock' : 'error:'.$exception->getMessage();
        }
        PHP;

    return new Process([PHP_BINARY, '-r', $boot.$script.$end], base_path(), [
        'APP_ENV' => 'testing', 'DB_DATABASE' => config('database.connections.pgsql.database'), 'DB_URL' => '',
        'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'BROADCAST_CONNECTION' => 'null', 'FILESYSTEM_DISK' => 'local',
        'RACE_DELIVERY' => (string) $deliveryId, 'RACE_TOKEN' => $token,
    ], timeout: 30);
}

/**
 * Holds the channel row as the archive does, starts $process, waits until it blocks on a lock, then runs the real
 * archive in this transaction. Returns [what this side saw, the process output].
 *
 * @return array{string, string}
 */
function archiveWhile(NotificationDestination $channel, Process $process, bool $expectWait = true): array
{
    DB::beginTransaction();
    $archived = 'archived';
    try {
        DB::table('notification_destinations')->where('id', $channel->id)->lockForUpdate()->first();
        $process->start();
        $deadline = hrtime(true) + 20_000_000_000;
        do {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $waiting = (int) DB::scalar("SELECT count(*) FROM pg_stat_activity WHERE datname = current_database() AND pid <> pg_backend_pid() AND wait_event_type = 'Lock'");
            if ($waiting > 0 || ! $process->isRunning()) {
                break;
            }
            usleep(10000);
        } while (hrtime(true) < $deadline);
        if ($expectWait) {
            expect($waiting)->toBeGreaterThan(0, $process->getOutput().$process->getErrorOutput());
        } else {
            // A claim never waits on a busy channel: it gives up at once and leaves the delivery for the next pass.
            expect($process->isRunning())->toBeFalse($process->getOutput().$process->getErrorOutput());
        }
        try {
            app(ArchiveNotificationChannel::class)($channel, ArchiveNotificationChannel::Archived, null);
            DB::commit();
        } catch (QueryException $exception) {
            DB::rollBack();
            $archived = str_contains($exception->getMessage(), '40P01') ? 'deadlock' : throw $exception;
        }
    } catch (Throwable $exception) {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        throw $exception;
    }
    $process->wait();
    expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput());

    return [$archived, trim($process->getOutput())];
}

/** A one-off client message whose single attempt was claimed and whose lease has expired (the worker crashed). */
function channelLockOneOff(): array
{
    // The test runs queued jobs synchronously; the attempt under test must stay unsent until the scenario sets it up.
    Queue::fake();
    $fixture = clientMessageRaceFixture(alreadySent: 0);
    $sent = app(SendClientMessage::class)->send($fixture['owner'], $fixture['client'], [...$fixture['payload'], 'channel_ids' => [], 'one_off' => 'boss@race.test'], 'lock-order');
    DB::commit();
    $delivery = Delivery::query()->findOrFail($sent['delivery_ids'][0]);

    return [$fixture, $delivery, NotificationDestination::query()->findOrFail($delivery->destination_id)];
}

it('archives a one-off channel while its expired attempt is reclaimed, without a deadlock or a second attempt', function (): void {
    [$fixture, $delivery, $channel] = channelLockOneOff();
    try {
        DB::table('deliveries')->where('id', $delivery->id)->update(['state' => 'sending', 'attempts' => 1, 'claim_token' => (string) Str::uuid(),
            'lease_until' => CarbonImmutable::now()->subMinute()->format('Y-m-d H:i:s.uP')]);
        [$archived, $claimed] = archiveWhile($channel, channelLockProcess(
            'echo json_encode(app(Heptapod\Actions\ClaimNotificationDelivery::class)((int) getenv("RACE_DELIVERY"))?->id);', $delivery->id), expectWait: false);
        expect([$archived, $claimed])->toBe(['archived', 'null']);
        $delivery->refresh();
        // The archive suppressed the expired attempt; the skipped claim never took it, so there is no second attempt.
        expect([$delivery->suppressed, $delivery->attempts, $delivery->suppression_reason])->toBe([true, 1, 'destination_archived'])
            ->and($channel->refresh()->removed_reason)->toBe('archived');
    } finally {
        ($fixture['cleanup'])();
    }
});

it('archives a channel while a late send to it completes, without a deadlock', function (): void {
    [$fixture, $delivery, $channel] = channelLockOneOff();
    try {
        // The send outlived its lease: archiving suppresses the row while the late completion still holds its claim token.
        $token = (string) Str::uuid();
        DB::table('deliveries')->where('id', $delivery->id)->update(['state' => 'sending', 'attempts' => 1, 'claim_token' => $token,
            'lease_until' => CarbonImmutable::now()->subSecond()->format('Y-m-d H:i:s.uP')]);
        [$archived, $completed] = archiveWhile($channel, channelLockProcess(
            'echo json_encode(app(Heptapod\Actions\CompleteNotificationDelivery::class)((int) getenv("RACE_DELIVERY"), getenv("RACE_TOKEN"), new Heptapod\Contracts\Notifications\DeliveryResult(Heptapod\Contracts\Notifications\DeliveryState::Sent)));',
            $delivery->id, $token));
        // Whichever commits first wins; nothing deadlocks, and the delivery ends in one consistent state.
        expect($archived)->toBe('archived')->and($completed)->toBeIn(['true', 'false'])
            ->and($channel->refresh()->removed_reason)->toBe('archived');
    } finally {
        ($fixture['cleanup'])();
    }
});

it('archives a channel while a maintenance window holding its opening alert is deleted, without a deadlock', function (): void {
    $fixture = clientMessageRaceFixture(alreadySent: 0);
    $channel = NotificationDestination::factory()->create();
    try {
        $site = Site::query()->where('client_id', $fixture['client']->id)->firstOrFail();
        $check = Check::factory()->create(['site_id' => $site->id, 'type' => CheckKey::Uptime]);
        $incident = Incident::factory()->for($check)->create(['opened_at' => now()->subMinutes(30), 'first_failure_at' => now()->subMinutes(31),
            'alert_episode_started_at' => now()->subMinutes(31), 'alert_after_minutes' => 5, 'alert_recovery_checks' => 2]);
        $window = MaintenancePeriod::factory()->create(['site_id' => $site->id, 'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(),
            'mute_alerts' => true, 'catch_up_at_end' => true]);
        $opening = Event::query()->create(['type' => 'incident.opened', 'site_id' => $site->id, 'check_id' => $check->id, 'incident_id' => $incident->id,
            'occurred_at' => now()->subMinutes(30), 'to_result' => CheckResult::Failed, 'cause' => IncidentCause::Failed,
            'payload' => [], 'suppressed' => false, 'suppression_evaluated_at' => now(), 'notification_routed_at' => now()]);
        // The opening alert was deferred by this window and waits for its catch-up.
        $delivery = Delivery::query()->create(['event_id' => $opening->id, 'destination_id' => $channel->id, 'next_attempt_at' => now()->addHour(),
            'suppressed' => false, 'suppression_evaluated_at' => now(), 'deferred_maintenance_period_id' => $window->id, 'blocking_maintenance_period_id' => $window->id,
            'last_reason' => 'deferred_by_maintenance']);
        DB::commit();
        [$archived, $deleted] = archiveWhile($channel, channelLockProcess(
            'app(Heptapod\Actions\DeleteMaintenancePeriod::class)(Heptapod\Models\MaintenancePeriod::query()->findOrFail((int) getenv("RACE_DELIVERY"))); echo "deleted";', $window->id));
        expect([$archived, $deleted])->toBe(['archived', 'deleted'])
            ->and($delivery->refresh()->suppressed)->toBeTrue()
            ->and($channel->refresh()->removed_reason)->toBe('archived');
    } finally {
        $events = DB::table('events')->where('site_id', Site::query()->where('client_id', $fixture['client']->id)->value('id'))->pluck('id');
        DB::table('deliveries')->whereIn('event_id', $events)->delete();
        DB::table('maintenance_catch_up_incidents')->whereIn('event_id', $events)->delete();
        DB::table('events')->whereIn('id', $events)->delete();
        DB::table('maintenance_periods')->where('site_id', Site::query()->where('client_id', $fixture['client']->id)->value('id'))->delete();
        DB::table('notification_preferences')->where('destination_id', $channel->id)->delete();
        DB::table('notification_destinations')->where('id', $channel->id)->delete();
        DB::table('incidents')->whereIn('check_id', DB::table('checks')->where('site_id', Site::query()->where('client_id', $fixture['client']->id)->value('id'))->select('id'))->delete();
        ($fixture['cleanup'])();
    }
});

it('claims a delivery on another channel at once while one channel is held, then claims the busy one exactly once on the next pass', function (): void {
    $fixture = clientMessageRaceFixture(alreadySent: 0);
    [$busy, $free] = [NotificationDestination::factory()->create(), NotificationDestination::factory()->create()];
    try {
        Queue::fake();
        $owner = $fixture['owner'];
        $client = $fixture['client'];
        // Two pending manual sends, one per channel, as the dispatcher hands them to the single delivery worker in turn.
        $deliveryOn = function (NotificationDestination $channel, string $key) use ($owner, $client, $fixture): Delivery {
            $sent = app(SendClientMessage::class)->send($owner, $client, [...$fixture['payload'], 'channel_ids' => [], 'one_off' => $key.'@race.test'], $key);
            $delivery = Delivery::query()->findOrFail($sent['delivery_ids'][0]);
            DB::table('deliveries')->where('id', $delivery->id)->update(['destination_id' => $channel->id]);

            return $delivery;
        };
        $onBusy = $deliveryOn($busy, 'busy');
        $onFree = $deliveryOn($free, 'free');
        DB::commit();
        $process = channelLockProcess(<<<'PHP'
            $claim = app(Heptapod\Actions\ClaimNotificationDelivery::class);
            $started = hrtime(true);
            $first = $claim((int) getenv("RACE_DELIVERY"));
            $second = $claim((int) getenv("RACE_TOKEN"));
            echo json_encode(['busy' => $first?->id, 'free' => $second?->id, 'ms' => intdiv(hrtime(true) - $started, 1_000_000)]);
            PHP, $onBusy->id, (string) $onFree->id);
        DB::beginTransaction();
        DB::table('notification_destinations')->where('id', $busy->id)->lockForUpdate()->first();
        $process->start();
        // A blocking claim would wait here until this hold ends.
        $deadline = hrtime(true) + 5_000_000_000;
        while ($process->isRunning() && hrtime(true) < $deadline) {
            usleep(20000);
        }
        DB::rollBack();
        $process->wait();
        expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput());
        $result = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
        expect($result['busy'])->toBeNull()->and($result['free'])->toBe($onFree->id)->and($result['ms'])->toBeLessThan(2000)
            ->and($onBusy->refresh()->state)->toBe(DeliveryState::Pending)->and($onBusy->suppressed)->toBeFalse()
            ->and($onFree->refresh()->state)->toBe(DeliveryState::Sending);

        // The channel is free again. The next dispatch pass offers the skipped delivery once, and it is claimed exactly once.
        Queue::fake();
        (new DispatchNotifications)->handle(app(PlanNotificationEvent::class), app(EmitIncidentReminders::class),
            app(EmitNotificationDigests::class), app(EmitMaintenanceCatchUps::class));
        Queue::assertPushed(SendNotification::class, fn (SendNotification $job): bool => $job->deliveryId === $onBusy->id);
        expect(Queue::pushed(SendNotification::class, fn (SendNotification $job): bool => $job->deliveryId === $onBusy->id))->toHaveCount(1);
        $claim = app(ClaimNotificationDelivery::class);
        $claimed = $claim($onBusy->id);
        expect($claimed?->id)->toBe($onBusy->id)
            ->and($claim($onBusy->id))->toBeNull()
            ->and($onBusy->refresh()->state)->toBe(DeliveryState::Sending)->and($onBusy->attempts)->toBe(1);
        DB::commit();
    } finally {
        ($fixture['cleanup'])();
        DB::table('notification_preferences')->whereIn('destination_id', [$busy->id, $free->id])->delete();
        DB::table('notification_destinations')->whereIn('id', [$busy->id, $free->id])->delete();
    }
});

/** An uptime opening on $site deferred by $window for $channel, as planning leaves it during muted maintenance. */
function channelLockDeferredOpening(Site $site, MaintenancePeriod $window, NotificationDestination $channel): Delivery
{
    $check = Check::query()->where('site_id', $site->id)->where('type', CheckKey::Uptime->value)->first()
        ?? Check::factory()->create(['site_id' => $site->id, 'type' => CheckKey::Uptime]);
    $incident = Incident::query()->where('check_id', $check->id)->whereNull('resolved_at')->first()
        ?? Incident::factory()->for($check)->create(['opened_at' => now()->subMinutes(30), 'first_failure_at' => now()->subMinutes(31),
            'alert_episode_started_at' => now()->subMinutes(31), 'alert_after_minutes' => 5, 'alert_recovery_checks' => 2]);
    $opening = Event::query()->create(['type' => 'incident.opened', 'site_id' => $site->id, 'check_id' => $check->id, 'incident_id' => $incident->id,
        'occurred_at' => now()->subMinutes(30), 'to_result' => CheckResult::Failed, 'cause' => IncidentCause::Failed,
        'payload' => [], 'suppressed' => false, 'suppression_evaluated_at' => now(), 'notification_routed_at' => now()]);

    return Delivery::query()->create(['event_id' => $opening->id, 'destination_id' => $channel->id, 'next_attempt_at' => now()->addHour(),
        'suppressed' => false, 'suppression_evaluated_at' => now(), 'deferred_maintenance_period_id' => $window->id, 'blocking_maintenance_period_id' => $window->id,
        'last_reason' => 'deferred_by_maintenance']);
}

it('deletes a client-wide window over two sites while a send to the same channel completes, without a deadlock', function (): void {
    $fixture = clientMessageRaceFixture(alreadySent: 0);
    $channel = NotificationDestination::factory()->create();
    $client = $fixture['client'];
    $siteIds = [];
    try {
        $first = Site::query()->where('client_id', $client->id)->firstOrFail();
        $second = Site::factory()->create(['client_id' => $client->id, 'url' => 'https://second-'.$client->id.'.example.test']);
        $siteIds = [$first->id, $second->id];
        [$low, $high] = $first->id < $second->id ? [$first, $second] : [$second, $first];
        $window = MaintenancePeriod::factory()->create(['site_id' => null, 'target_type' => 'client', 'target_id' => $client->id,
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(), 'mute_alerts' => true, 'catch_up_at_end' => true]);
        channelLockDeferredOpening($low, $window, $channel);
        channelLockDeferredOpening($high, $window, $channel);
        // A send on the higher site's channel that is about to complete: completion locks its site, then the channel.
        $sending = channelLockDeferredOpening($high, $window, $channel);
        $token = (string) Str::uuid();
        DB::table('deliveries')->where('id', $sending->id)->update(['state' => 'sending', 'attempts' => 1, 'claim_token' => $token,
            'deferred_maintenance_period_id' => null, 'blocking_maintenance_period_id' => null, 'lease_until' => now()->addMinute()->format('Y-m-d H:i:s.uP')]);
        DB::commit();
        $deletion = channelLockProcess(
            'app(Heptapod\Actions\DeleteMaintenancePeriod::class)(Heptapod\Models\MaintenancePeriod::query()->findOrFail((int) getenv("RACE_DELIVERY"))); echo "deleted";', $window->id);
        // This connection is the completing worker: it holds the higher site (completion's first lock), lets the deletion
        // run into it, then completes for real, which needs the channel.
        DB::beginTransaction();
        DB::table('sites')->where('id', $high->id)->lock('FOR NO KEY UPDATE')->first();
        $deletion->start();
        $deadline = hrtime(true) + 20_000_000_000;
        do {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $waiting = (int) DB::scalar("SELECT count(*) FROM pg_stat_activity WHERE datname = current_database() AND pid <> pg_backend_pid() AND wait_event_type = 'Lock'");
            usleep(10000);
        } while ($waiting === 0 && $deletion->isRunning() && hrtime(true) < $deadline);
        expect($waiting)->toBeGreaterThan(0, $deletion->getOutput().$deletion->getErrorOutput());
        $completed = 'completed';
        try {
            app(CompleteNotificationDelivery::class)($sending->id, $token, new DeliveryResult(DeliveryState::Sent));
            DB::commit();
        } catch (QueryException $exception) {
            DB::rollBack();
            $completed = str_contains($exception->getMessage(), '40P01') ? 'deadlock' : throw $exception;
        }
        $deletion->wait();
        expect($deletion->isSuccessful())->toBeTrue($deletion->getOutput().$deletion->getErrorOutput())
            ->and([$completed, trim($deletion->getOutput())])->toBe(['completed', 'deleted'])
            ->and($sending->refresh()->state)->toBe(DeliveryState::Sent)
            ->and(MaintenancePeriod::query()->find($window->id))->toBeNull();
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        $events = DB::table('events')->whereIn('site_id', $siteIds)->pluck('id');
        DB::table('deliveries')->whereIn('event_id', $events)->delete();
        DB::table('maintenance_catch_up_incidents')->whereIn('event_id', $events)->delete();
        DB::table('events')->whereIn('id', $events)->delete();
        DB::table('maintenance_periods')->where('target_type', 'client')->where('target_id', $client->id)->delete();
        DB::table('notification_preferences')->where('destination_id', $channel->id)->delete();
        DB::table('notification_destinations')->where('id', $channel->id)->delete();
        DB::table('incidents')->whereIn('site_id', $siteIds)->delete();
        DB::table('checks')->whereIn('site_id', $siteIds)->where('type', CheckKey::Uptime->value)->delete();
        ($fixture['cleanup'])();
    }
});

/** Waits until another session of this test database waits on a lock, or $process ends; returns the waiting count. */
function channelLockAwaitWaiter(Process $process): int
{
    $deadline = hrtime(true) + 20_000_000_000;
    do {
        DB::select('SELECT pg_stat_clear_snapshot()');
        $waiting = (int) DB::scalar("SELECT count(*) FROM pg_stat_activity WHERE datname = current_database() AND pid <> pg_backend_pid() AND wait_event_type = 'Lock'");
        if ($waiting > 0 || ! $process->isRunning()) {
            return $waiting;
        }
        usleep(10000);
    } while (hrtime(true) < $deadline);

    return $waiting;
}

/**
 * A muted window that has ended with one open certificate incident opened in it, and a team channel that hears
 * Failures: the next catch-up pass inserts one catch-up event on that check and one delivery.
 *
 * @return array{check: Check, incident: Incident, window: MaintenancePeriod, channel: NotificationDestination, cleanup: Closure(): void}
 */
function channelLockCatchUpFixture(): array
{
    $fixture = clientMessageRaceFixture(alreadySent: 0);
    $site = Site::query()->where('client_id', $fixture['client']->id)->firstOrFail();
    $window = MaintenancePeriod::factory()->create(['site_id' => $site->id, 'starts_at' => now()->subHour(), 'ends_at' => now()->subMinute(),
        'mute_alerts' => true, 'catch_up_at_end' => true]);
    $check = Check::factory()->create(['site_id' => $site->id, 'type' => CheckKey::CertificateHealth, 'latest_run_result' => CheckResult::Failed]);
    $incident = Incident::factory()->for($check)->create(['opened_at' => now()->subMinutes(30), 'opened_during_maintenance_window_id' => $window->id]);
    Event::query()->create(['type' => 'incident.opened', 'site_id' => $site->id, 'check_id' => $check->id, 'incident_id' => $incident->id,
        'occurred_at' => now()->subMinutes(30), 'to_result' => CheckResult::Failed, 'cause' => IncidentCause::Failed, 'payload' => [],
        'suppressed' => true, 'suppression_reason' => 'maintenance', 'suppression_evaluated_at' => now(), 'notification_routed_at' => now()]);
    $channel = NotificationDestination::factory()->create();
    DB::table('notification_preferences')->where('destination_id', $channel->id)->where('category', NotificationCategory::Failure->value)
        ->update(['mode' => NotificationMode::Immediate->value]);
    DB::commit();

    return ['check' => $check, 'incident' => $incident, 'window' => $window, 'channel' => $channel, 'cleanup' => static function () use ($fixture, $site, $channel): void {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        $events = DB::table('events')->where('site_id', $site->id)->pluck('id');
        DB::table('deliveries')->whereIn('event_id', $events)->delete();
        DB::table('maintenance_catch_up_incidents')->whereIn('event_id', $events)->delete();
        DB::table('events')->whereIn('id', $events)->delete();
        DB::table('maintenance_catch_up_sites')->where('site_id', $site->id)->delete();
        DB::table('incidents')->where('site_id', $site->id)->delete();
        DB::table('maintenance_periods')->where('site_id', $site->id)->delete();
        DB::table('notification_preferences')->where('destination_id', $channel->id)->delete();
        DB::table('notification_destinations')->where('id', $channel->id)->delete();
        DB::table('checks')->where('site_id', $site->id)->where('type', '<>', CheckKey::BrokenLinks->value)->delete();
        ($fixture['cleanup'])();
    }];
}

it('emits a maintenance catch-up while the recorder holds its check and writes its incident, without a deadlock', function (): void {
    $setup = channelLockCatchUpFixture();
    try {
        $catchUp = channelLockProcess('app(Heptapod\Actions\EmitMaintenanceCatchUps::class)((int) getenv("RACE_DELIVERY")); echo "emitted";', $setup['window']->id);
        // This connection is the recorder: it holds the check (its first lock), lets the catch-up run into it, then writes
        // the check's open incident as IncidentTransitions does.
        DB::beginTransaction();
        DB::table('checks')->where('id', $setup['check']->id)->lockForUpdate()->first();
        $catchUp->start();
        expect(channelLockAwaitWaiter($catchUp))->toBeGreaterThan(0, $catchUp->getOutput().$catchUp->getErrorOutput());
        $recorded = 'recorded';
        try {
            DB::table('incidents')->where('id', $setup['incident']->id)->update(['evidence' => json_encode(['status_code' => 500], JSON_THROW_ON_ERROR)]);
            DB::commit();
        } catch (QueryException $exception) {
            DB::rollBack();
            $recorded = str_contains($exception->getMessage(), '40P01') ? 'deadlock' : throw $exception;
        }
        $catchUp->wait();
        expect($catchUp->isSuccessful())->toBeTrue($catchUp->getOutput().$catchUp->getErrorOutput())
            ->and([$recorded, trim($catchUp->getOutput())])->toBe(['recorded', 'emitted'])
            ->and(Delivery::query()->where('destination_id', $setup['channel']->id)->count())->toBe(1)
            ->and($setup['window']->refresh()->catch_up_processed_at)->not->toBeNull();
    } finally {
        ($setup['cleanup'])();
    }
});

it('keeps an incident the recorder opens in a window waiting until that window\'s catch-up commits', function (): void {
    $fixture = clientMessageRaceFixture(alreadySent: 0);
    $site = Site::query()->where('client_id', $fixture['client']->id)->firstOrFail();
    try {
        $window = MaintenancePeriod::factory()->create(['site_id' => $site->id, 'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(),
            'mute_alerts' => true, 'catch_up_at_end' => true]);
        $check = Check::factory()->create(['site_id' => $site->id, 'type' => CheckKey::Uptime, 'failure_threshold' => 1]);
        DB::commit();
        $recorder = channelLockProcess(<<<'PHP'
            $check = Heptapod\Models\Check::query()->findOrFail((int) getenv("RACE_DELIVERY"));
            $at = Carbon\CarbonImmutable::now("UTC");
            $claim = Heptapod\Testing\ClaimedRun::start($check, $at->subSecond());
            $timings = array_fill_keys(["namelookup_time", "connect_time", "appconnect_time", "starttransfer_time", "pretransfer_time", "redirect_time", "total_time", "header_size", "size_download", "speed_download"], null);
            app(Heptapod\Checks\Recording\ResultRecorder::class)->record(new Heptapod\Checks\Recording\RecordableRun($check->id, $claim->claimToken, $claim->startedAt, $at,
                new Heptapod\Contracts\CheckOutcome(Heptapod\Contracts\RunResult::Failed, "uptime.failed", [], new Heptapod\Addons\Uptime\UptimeMetrics(null, null, null, null, null, 0.2, $timings, $at->toIso8601String()))));
            echo "recorded";
            PHP, $check->id);
        // This connection is a catch-up of the window: it holds the window as EmitMaintenanceCatchUps does.
        DB::beginTransaction();
        NotificationLocks::maintenanceWindows([$window->id], exclusive: true);
        $recorder->start();
        expect(channelLockAwaitWaiter($recorder))->toBeGreaterThan(0, $recorder->getOutput().$recorder->getErrorOutput())
            ->and(DB::table('incidents')->where('check_id', $check->id)->exists())->toBeFalse();
        DB::commit();
        $recorder->wait();
        expect($recorder->isSuccessful())->toBeTrue($recorder->getOutput().$recorder->getErrorOutput())
            ->and(trim($recorder->getOutput()))->toBe('recorded')
            ->and(Incident::query()->where('check_id', $check->id)->sole()->opened_during_maintenance_window_id)->toBe($window->id);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        $events = DB::table('events')->where('site_id', $site->id)->pluck('id');
        DB::table('deliveries')->whereIn('event_id', $events)->delete();
        DB::table('events')->whereIn('id', $events)->delete();
        DB::table('incidents')->where('site_id', $site->id)->delete();
        DB::table('maintenance_periods')->where('site_id', $site->id)->delete();
        DB::table('checks')->where('site_id', $site->id)->where('type', CheckKey::Uptime->value)->delete();
        ($fixture['cleanup'])();
    }
});

it('surfaces a catch-up aborted by a deadlock as a failed dispatch and emits it exactly once on the next pass', function (): void {
    $setup = channelLockCatchUpFixture();
    try {
        // Postgres aborts the catch-up's event insert as it aborts a deadlock victim.
        DB::unprepared("CREATE FUNCTION nl_catch_up_deadlock() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN
            IF NEW.type = 'maintenance.catch_up' THEN RAISE EXCEPTION 'deadlock detected' USING ERRCODE = '40P01'; END IF; RETURN NEW; END \$\$");
        DB::unprepared('CREATE TRIGGER nl_catch_up_deadlock BEFORE INSERT ON events FOR EACH ROW EXECUTE FUNCTION nl_catch_up_deadlock()');
        DB::commit();
        Queue::fake();
        $dispatch = static fn () => (new DispatchNotifications)->handle(app(PlanNotificationEvent::class), app(EmitIncidentReminders::class),
            app(EmitNotificationDigests::class), app(EmitMaintenanceCatchUps::class));
        // The job fails (the delivery queue runs it once, so Horizon records the failure); nothing of the catch-up is kept.
        // Inside RefreshDatabase's outer transaction Laravel rethrows the 40P01 as a DeadlockException; at the top level a
        // worker sees the QueryException. Either way the job ends with the error, never swallowed.
        expect($dispatch)->toThrow(DeadlockException::class, '40P01');
        expect(DB::transactionLevel())->toBe(0)
            ->and($setup['window']->refresh()->catch_up_processed_at)->toBeNull()
            ->and(DB::table('maintenance_catch_up_sites')->where('maintenance_period_id', $setup['window']->id)->exists())->toBeFalse()
            ->and(Delivery::query()->where('destination_id', $setup['channel']->id)->exists())->toBeFalse();
        DB::unprepared('DROP TRIGGER nl_catch_up_deadlock ON events; DROP FUNCTION nl_catch_up_deadlock()');
        // The scheduler's next pass finds the window still due and emits its catch-up once; a further pass adds nothing.
        $dispatch();
        $dispatch();
        expect(Event::query()->where('type', 'maintenance.catch_up')->where('check_id', $setup['check']->id)->count())->toBe(1)
            ->and(Delivery::query()->where('destination_id', $setup['channel']->id)->count())->toBe(1)
            ->and($setup['window']->refresh()->catch_up_processed_at)->not->toBeNull();
    } finally {
        DB::unprepared('DROP TRIGGER IF EXISTS nl_catch_up_deadlock ON events; DROP FUNCTION IF EXISTS nl_catch_up_deadlock()');
        ($setup['cleanup'])();
    }
});

/**
 * An open uptime episode on the fixture client's site with its routed opening, and a team channel over every client
 * that $prepare sets up for the routing change under test.
 *
 * @param  Closure(NotificationDestination): void  $prepare
 * @return array{incident: Incident, opening: Event, channel: NotificationDestination, cleanup: Closure(): void}
 */
function channelLockEpisodeFixture(Closure $prepare): array
{
    $fixture = clientMessageRaceFixture(alreadySent: 0);
    $site = Site::query()->where('client_id', $fixture['client']->id)->firstOrFail();
    $check = Check::factory()->create(['site_id' => $site->id, 'type' => CheckKey::Uptime, 'latest_run_result' => CheckResult::Failed]);
    $incident = Incident::factory()->for($check)->create(['opened_at' => now()->subMinutes(30), 'first_failure_at' => now()->subMinutes(31),
        'alert_episode_started_at' => now()->subMinutes(31), 'alert_after_minutes' => 5, 'alert_recovery_checks' => 2]);
    $opening = Event::query()->create(['type' => 'incident.opened', 'site_id' => $site->id, 'check_id' => $check->id, 'incident_id' => $incident->id,
        'occurred_at' => now()->subMinutes(30), 'to_result' => CheckResult::Failed, 'cause' => IncidentCause::Failed,
        'payload' => [], 'suppressed' => false, 'suppression_evaluated_at' => now(), 'notification_routed_at' => now()]);
    $channel = NotificationDestination::factory()->create();
    $prepare($channel);
    DB::commit();

    return ['incident' => $incident, 'opening' => $opening, 'channel' => $channel, 'cleanup' => static function () use ($fixture, $site, $channel): void {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        $events = DB::table('events')->where('site_id', $site->id)->pluck('id');
        DB::table('deliveries')->whereIn('event_id', $events)->delete();
        DB::table('events')->whereIn('id', $events)->delete();
        DB::table('incidents')->where('site_id', $site->id)->delete();
        DB::table('activity_log')->where('subject_type', (new NotificationDestination)->getMorphClass())->where('subject_id', $channel->id)->delete();
        DB::table('notification_preferences')->where('destination_id', $channel->id)->delete();
        DB::table('notification_destinations')->where('id', $channel->id)->delete();
        DB::table('checks')->where('site_id', $site->id)->where('type', CheckKey::Uptime->value)->delete();
        ($fixture['cleanup'])();
    }];
}

it('applies a routing change that marks an open outage while planning holds that outage and adds a delivery on the channel, without a deadlock', function (string $change, Closure $prepare): void {
    $setup = channelLockEpisodeFixture($prepare);
    try {
        $routing = channelLockProcess(match ($change) {
            'preferences' => 'app(Heptapod\Actions\Notifications\UpdateNotificationPreferences::class)(Heptapod\Models\NotificationDestination::query()->findOrFail((int) getenv("RACE_DELIVERY")), ["preferences" => ["outage" => "immediate"]]); echo "changed";',
            'resume' => 'app(Heptapod\Actions\SetNotificationDestinationEnabled::class)(Heptapod\Models\NotificationDestination::query()->findOrFail((int) getenv("RACE_DELIVERY")), true); echo "changed";',
        }, $setup['channel']->id);
        // Both changes run their transaction twice on a deadlock (NotificationLocks::transaction), so one could be retried
        // away; the database's deadlock counter shows it anyway (an exiting process flushes its statistics).
        $deadlocks = static function (): int {
            DB::select('SELECT pg_stat_clear_snapshot()');

            return (int) DB::scalar('SELECT deadlocks FROM pg_stat_database WHERE datname = current_database()');
        };
        $before = $deadlocks();
        // This connection is planning or a catch-up: it holds the episode's incident (NotificationLocks level 7), lets the
        // routing change run into it, then inserts a delivery for the channel, which takes the channel's key.
        DB::beginTransaction();
        NotificationLocks::incidents([$setup['incident']->id]);
        $routing->start();
        expect(channelLockAwaitWaiter($routing))->toBeGreaterThan(0, $routing->getOutput().$routing->getErrorOutput());
        $planned = 'planned';
        try {
            DB::table('deliveries')->insert(['event_id' => $setup['opening']->id, 'destination_id' => $setup['channel']->id, 'state' => 'pending',
                'next_attempt_at' => now()->addHour()->format('Y-m-d H:i:s.uP'), 'suppressed' => false, 'suppression_evaluated_at' => now()->format('Y-m-d H:i:s.uP')]);
            DB::commit();
        } catch (QueryException $exception) {
            DB::rollBack();
            $planned = str_contains($exception->getMessage(), '40P01') ? 'deadlock' : throw $exception;
        }
        $routing->wait();
        expect($routing->isSuccessful())->toBeTrue($routing->getOutput().$routing->getErrorOutput())
            ->and([$planned, trim($routing->getOutput())])->toBe(['planned', 'changed'])
            ->and($deadlocks() - $before)->toBe(0)
            ->and($setup['incident']->refresh()->routing_reconcile_requested_at)->not->toBeNull();
    } finally {
        ($setup['cleanup'])();
    }
})->with([
    'an Outage cell turned on' => ['preferences', static function (NotificationDestination $channel): void {}],
    'a paused channel resumed' => ['resume', static function (NotificationDestination $channel): void {
        DB::table('notification_preferences')->where('destination_id', $channel->id)->where('category', NotificationCategory::Outage->value)
            ->update(['mode' => NotificationMode::Immediate->value]);
        $channel->update(['enabled' => false]);
    }],
]);

it('archives a client whose sites\' outages have crossed ids while routing reconciliation locks open outages, without a deadlock', function (): void {
    $fixture = clientMessageRaceFixture(alreadySent: 0);
    $channel = NotificationDestination::factory()->create();
    try {
        Queue::fake();
        $first = Site::query()->where('client_id', $fixture['client']->id)->firstOrFail();
        $second = Site::factory()->create(['client_id' => $fixture['client']->id]);
        $outage = static fn (Site $site): Incident => Incident::factory()
            ->for(Check::factory()->create(['site_id' => $site->id, 'type' => CheckKey::Uptime, 'latest_run_result' => CheckResult::Failed]))
            ->create(['opened_at' => now()->subMinutes(30), 'first_failure_at' => now()->subMinutes(31),
                'alert_episode_started_at' => now()->subMinutes(31), 'alert_after_minutes' => 5, 'alert_recovery_checks' => 2]);
        // The second site's outage has the lower id: pausing site after site would lock the higher id first.
        $low = $outage($second);
        $high = $outage($first);
        DB::commit();
        $archive = channelLockProcess('app(Heptapod\Actions\ArchiveClient::class)(Heptapod\Models\Client::query()->findOrFail((int) getenv("RACE_DELIVERY"))); echo "archived";',
            $fixture['client']->id);
        // This connection is a team channel's routing change: it holds the lower outage (the first row of its ascending
        // pass), lets the archive run into it, then takes every open outage in ascending id.
        DB::beginTransaction();
        NotificationLocks::incidents([$low->id]);
        $archive->start();
        expect(channelLockAwaitWaiter($archive))->toBeGreaterThan(0, $archive->getOutput().$archive->getErrorOutput());
        $routed = 'routed';
        try {
            app(RequestRoutingReconciliation::class)->lockEpisodes($channel);
            DB::commit();
        } catch (QueryException $exception) {
            DB::rollBack();
            $routed = str_contains($exception->getMessage(), '40P01') ? 'deadlock' : throw $exception;
        }
        $archive->wait();
        expect($archive->isSuccessful())->toBeTrue($archive->getOutput().$archive->getErrorOutput())
            ->and([$routed, trim($archive->getOutput())])->toBe(['routed', 'archived'])
            ->and($fixture['client']->refresh()->archived_at)->not->toBeNull()
            ->and([$low->refresh()->resolved_at !== null, $high->refresh()->resolved_at !== null])->toBe([true, true]);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DB::table('notification_preferences')->where('destination_id', $channel->id)->delete();
        DB::table('notification_destinations')->where('id', $channel->id)->delete();
        ($fixture['cleanup'])();
    }
});

it('scopes a team channel to a client while that client is archived, without a deadlock', function (): void {
    $setup = channelLockEpisodeFixture(static function (NotificationDestination $channel): void {});
    try {
        Queue::fake();
        $site = Site::query()->findOrFail($setup['incident']->site_id);
        $client = Client::query()->findOrFail($site->client_id);
        $edit = channelLockProcess(<<<'PHP'
            app(Heptapod\Actions\Notifications\SaveNotificationChannel::class)->edit(Heptapod\Models\NotificationDestination::query()->findOrFail((int) getenv("RACE_DELIVERY")),
                ['scope_client_id' => (int) getenv("RACE_TOKEN")]);
            echo "edited";
            PHP, $setup['channel']->id, (string) $client->id);
        // This connection is the client's archive: it holds the client (its first lock), lets the edit run into it, then
        // archives for real, which pauses the client's site and resolves the open outage the edit's scope change locks.
        DB::beginTransaction();
        NotificationLocks::clients([$client->id]);
        $edit->start();
        expect(channelLockAwaitWaiter($edit))->toBeGreaterThan(0, $edit->getOutput().$edit->getErrorOutput());
        $archived = 'archived';
        try {
            app(ArchiveClient::class)($client);
            DB::commit();
        } catch (QueryException|DeadlockException $exception) {
            DB::rollBack();
            $archived = str_contains($exception->getMessage(), '40P01') ? 'deadlock' : throw $exception;
        }
        $edit->wait();
        // The edit waited on the client before it held the outage, so the archive completed first.
        expect($edit->isSuccessful())->toBeTrue($edit->getOutput().$edit->getErrorOutput())
            ->and([$archived, trim($edit->getOutput())])->toBe(['archived', 'edited'])
            ->and($client->refresh()->archived_at)->not->toBeNull()
            ->and($setup['incident']->refresh()->resolved_at)->not->toBeNull()
            ->and($setup['channel']->refresh()->scope_client_id)->toBe($client->id);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DB::table('unmonitored_periods')->where('site_id', $setup['incident']->site_id)->delete();
        ($setup['cleanup'])();
    }
});

/**
 * A second Owner with an account channel and a note on one open incident of the fixture client's site, so DeleteProfile
 * may delete them; the cleanup also removes the rows a deleted user leaves behind.
 *
 * @return array{member: User, channel: NotificationDestination, incident: Incident, cleanup: Closure(): void}
 */
function channelLockNoteAuthorFixture(): array
{
    $fixture = clientMessageRaceFixture(alreadySent: 0);
    $site = Site::query()->where('client_id', $fixture['client']->id)->firstOrFail();
    $member = User::factory()->create();
    $member->assignRole('Owner');
    $channel = app(EnsureAccountChannel::class)($member);
    $check = Check::query()->where('site_id', $site->id)->firstOrFail();
    $incident = Incident::factory()->for($check)->create(['opened_at' => now()->subMinutes(10), 'notes' => 'Looking into it',
        'note_author_user_id' => $member->id, 'note_updated_at' => now()]);
    DB::commit();

    return ['member' => $member, 'channel' => $channel, 'incident' => $incident, 'cleanup' => static function () use ($fixture, $site, $member, $channel): void {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        $events = DB::table('events')->where('site_id', $site->id)->pluck('id');
        DB::table('deliveries')->whereIn('event_id', $events)->orWhere('destination_id', $channel->id)->delete();
        DB::table('events')->whereIn('id', $events)->delete();
        DB::table('incidents')->where('site_id', $site->id)->delete();
        DB::table('activity_log')->where('subject_type', (new NotificationDestination)->getMorphClass())->where('subject_id', $channel->id)->delete();
        DB::table('notification_preferences')->where('destination_id', $channel->id)->delete();
        DB::table('notification_destinations')->where('id', $channel->id)->delete();
        DB::table('users')->where('id', $member->id)->delete();
        ($fixture['cleanup'])();
    }];
}

it('deletes a profile while a catch-up holds an incident it noted and adds a delivery on its channel, without a deadlock', function (): void {
    $setup = channelLockNoteAuthorFixture();
    try {
        $opening = Event::query()->create(['type' => 'incident.opened', 'site_id' => $setup['incident']->site_id, 'check_id' => $setup['incident']->check_id,
            'incident_id' => $setup['incident']->id, 'occurred_at' => now()->subMinutes(10), 'to_result' => CheckResult::Failed, 'cause' => IncidentCause::Failed,
            'payload' => [], 'suppressed' => false, 'suppression_evaluated_at' => now(), 'notification_routed_at' => now()]);
        DB::commit();
        $deletion = channelLockProcess('app(Heptapod\Actions\DeleteProfile::class)(Heptapod\Models\User::query()->findOrFail((int) getenv("RACE_DELIVERY"))); echo "deleted";',
            $setup['member']->id);
        // This connection is a catch-up: it holds the incident (its level 7 lock), lets the deletion run into it, then inserts
        // the catch-up delivery on the member's channel, which takes the channel's key.
        DB::beginTransaction();
        NotificationLocks::incidents([$setup['incident']->id]);
        $deletion->start();
        expect(channelLockAwaitWaiter($deletion))->toBeGreaterThan(0, $deletion->getOutput().$deletion->getErrorOutput());
        $planned = 'planned';
        try {
            DB::table('deliveries')->insert(['event_id' => $opening->id, 'destination_id' => $setup['channel']->id, 'state' => 'pending',
                'next_attempt_at' => now()->addHour()->format('Y-m-d H:i:s.uP'), 'suppressed' => false, 'suppression_evaluated_at' => now()->format('Y-m-d H:i:s.uP')]);
            DB::commit();
        } catch (QueryException $exception) {
            DB::rollBack();
            $planned = str_contains($exception->getMessage(), '40P01') ? 'deadlock' : throw $exception;
        }
        $deletion->wait();
        // The deletion waited for the catch-up, then archived the channel: the new delivery is suppressed with it.
        expect($deletion->isSuccessful())->toBeTrue($deletion->getOutput().$deletion->getErrorOutput())
            ->and([$planned, trim($deletion->getOutput())])->toBe(['planned', 'deleted'])
            ->and(User::query()->find($setup['member']->id))->toBeNull()
            ->and($setup['incident']->refresh()->note_author_user_id)->toBeNull()
            ->and(Delivery::query()->where('destination_id', $setup['channel']->id)->sole()->suppression_reason)->toBe('recipient_deleted');
    } finally {
        ($setup['cleanup'])();
    }
});

it('deletes a site with a closed incident its member noted while that member\'s profile deletion runs, without a deadlock', function (): void {
    $setup = channelLockNoteAuthorFixture();
    try {
        $setup['incident']->update(['resolved_at' => now()->subMinutes(5)]);
        $site = Site::query()->findOrFail($setup['incident']->site_id);
        $office = NotificationDestination::query()->where('client_id', $site->client_id)->firstOrFail();
        // A sent message the member sent about this site: the site deletion redacts it, the profile deletion clears its actor.
        $event = Event::query()->create(['type' => 'incident.opened', 'site_id' => $site->id, 'check_id' => $setup['incident']->check_id,
            'incident_id' => $setup['incident']->id, 'occurred_at' => now()->subMinutes(10), 'to_result' => CheckResult::Failed, 'cause' => IncidentCause::Failed,
            'payload' => [], 'suppressed' => false, 'suppression_evaluated_at' => now(), 'notification_routed_at' => now()]);
        $actorDelivery = DB::table('deliveries')->insertGetId(['event_id' => $event->id, 'destination_id' => $office->id, 'state' => 'sent',
            'sent_at' => now()->format('Y-m-d H:i:s.uP'), 'actor_id' => $setup['member']->id, 'site_url' => $site->url, 'next_attempt_at' => now()->format('Y-m-d H:i:s.uP'),
            'suppressed' => false, 'suppression_evaluated_at' => now()->format('Y-m-d H:i:s.uP')]);
        DB::commit();
        $deletion = channelLockProcess('app(Heptapod\Actions\DeleteSite::class)(Heptapod\Models\Site::query()->findOrFail((int) getenv("RACE_DELIVERY"))); echo "deleted";', $site->id);
        // This connection is the member's profile deletion: it holds the user and the closed incident the member noted (its
        // first engine locks), lets the site deletion run into that incident, then deletes the profile for real, which needs
        // the member's actor delivery on the site's history.
        DB::beginTransaction();
        NotificationLocks::users([$setup['member']->id], exclusive: true);
        NotificationLocks::incidents([$setup['incident']->id]);
        $deletion->start();
        expect(channelLockAwaitWaiter($deletion))->toBeGreaterThan(0, $deletion->getOutput().$deletion->getErrorOutput());
        $deleted = 'deleted';
        try {
            app(DeleteProfile::class)($setup['member']);
            DB::commit();
        } catch (QueryException|DeadlockException $exception) {
            DB::rollBack();
            $deleted = str_contains($exception->getMessage(), '40P01') ? 'deadlock' : throw $exception;
        }
        $deletion->wait();
        // The site deletion waited on the incident holding no delivery, so the profile deletion completed first.
        expect($deletion->isSuccessful())->toBeTrue($deletion->getOutput().$deletion->getErrorOutput())
            ->and([$deleted, trim($deletion->getOutput())])->toBe(['deleted', 'deleted'])
            ->and(User::query()->find($setup['member']->id))->toBeNull()
            ->and(Site::query()->find($site->id))->toBeNull()
            ->and(DB::table('deliveries')->where('id', $actorDelivery)->value('actor_id'))->toBeNull();
    } finally {
        ($setup['cleanup'])();
    }
});

it('saves an incident note while its author\'s profile deletion runs, waiting on the author before it holds the incident', function (): void {
    $setup = channelLockNoteAuthorFixture();
    try {
        // A second open incident the author has not noted yet: the note's update takes the author's key (the column changes).
        $other = Incident::factory()->for(Check::factory()->create(['site_id' => $setup['incident']->site_id, 'type' => CheckKey::Uptime]))
            ->create(['opened_at' => now()->subMinutes(5)]);
        DB::commit();
        $note = channelLockProcess(<<<'PHP'
            [$incidentId, $actorId] = array_map(intval(...), explode(":", getenv("RACE_TOKEN")));
            app(Heptapod\Actions\SaveIncidentNote::class)(Heptapod\Models\Incident::query()->findOrFail($incidentId),
                Heptapod\Models\User::query()->findOrFail($actorId), "Fixed");
            echo "saved";
            PHP, 0, $other->id.':'.$setup['member']->id);
        // This connection is the author's profile deletion: it holds the user (its first engine lock) and lets the note run
        // into it. A note that already held its incident while waiting would close a cycle with any path that holds that
        // incident and waits on the deletion (catch-up, planning), so the incident must still be free.
        DB::beginTransaction();
        NotificationLocks::users([$setup['member']->id], exclusive: true);
        $note->start();
        expect(channelLockAwaitWaiter($note))->toBeGreaterThan(0, $note->getOutput().$note->getErrorOutput());
        $free = DB::table('incidents')->where('id', $other->id)->lock('FOR NO KEY UPDATE SKIP LOCKED')->value('id');
        app(DeleteProfile::class)($setup['member']);
        DB::commit();
        $note->wait();
        // The note waited for the deletion; its author is gone, so the save fails on the foreign key and writes nothing.
        expect($free)->toBe($other->id)
            ->and(str_contains($note->getOutput(), 'SQLSTATE[23503]') ? 'author gone' : trim($note->getOutput()))->toBe('author gone')
            ->and(User::query()->find($setup['member']->id))->toBeNull()
            ->and($other->refresh()->notes)->toBeNull()
            ->and($setup['incident']->refresh()->note_author_user_id)->toBeNull();
    } finally {
        ($setup['cleanup'])();
    }
});

it('sends a client message about an incident its sender noted while the sender\'s profile deletion runs, without a deadlock', function (): void {
    $setup = channelLockNoteAuthorFixture();
    try {
        $client = Site::query()->findOrFail($setup['incident']->site_id)->client_id;
        $office = NotificationDestination::query()->where('client_id', $client)->firstOrFail();
        $send = channelLockProcess(<<<'PHP'
            [$senderId, $clientId, $incidentId] = array_map(intval(...), explode(":", getenv("RACE_TOKEN")));
            try {
                app(Heptapod\Actions\SendClientMessage::class)->send(Heptapod\Models\User::query()->findOrFail($senderId), Heptapod\Models\Client::query()->findOrFail($clientId),
                    ['kind' => 'incident', 'design' => 'branded', 'incident_id' => $incidentId, 'channel_ids' => [(int) getenv("RACE_DELIVERY")]], 'lock-order');
                echo "sent";
            } catch (Illuminate\Database\QueryException $exception) {
                // 23503: the actor foreign key; the SQL text names actor_id in any error, so match the state.
                echo str_contains($exception->getMessage(), 'SQLSTATE[23503]') ? 'sender gone' : throw $exception;
            }
            PHP, $office->id, $setup['member']->id.':'.$client.':'.$setup['incident']->id);
        // This connection is the sender's profile deletion: it holds the user (its first engine lock), lets the send run into
        // it, then deletes the profile for real, which locks the incident the sender noted.
        DB::beginTransaction();
        NotificationLocks::users([$setup['member']->id], exclusive: true);
        $send->start();
        expect(channelLockAwaitWaiter($send))->toBeGreaterThan(0, $send->getOutput().$send->getErrorOutput());
        $deleted = 'deleted';
        try {
            app(DeleteProfile::class)($setup['member']);
            DB::commit();
        } catch (QueryException|DeadlockException $exception) {
            DB::rollBack();
            $deleted = str_contains($exception->getMessage(), '40P01') ? 'deadlock' : throw $exception;
        }
        $send->wait();
        // The send waited for the deletion; its sender is gone, so it fails on the actor key and sends nothing.
        expect($send->isSuccessful())->toBeTrue($send->getOutput().$send->getErrorOutput())
            ->and([$deleted, trim($send->getOutput())])->toBe(['deleted', 'sender gone'])
            ->and(Delivery::query()->where('destination_id', $office->id)->exists())->toBeFalse();
    } finally {
        ($setup['cleanup'])();
    }
});

it('mutes a site on a channel while its actor\'s profile deletion archives that channel, without a deadlock', function (): void {
    $fixture = clientMessageRaceFixture(alreadySent: 0);
    $channel = NotificationDestination::factory()->create();
    $site = Site::query()->where('client_id', $fixture['client']->id)->firstOrFail();
    try {
        DB::commit();
        $mute = channelLockProcess(<<<'PHP'
            [$siteId, $actorId] = array_map(intval(...), explode(":", getenv("RACE_TOKEN")));
            try {
                app(Heptapod\Actions\Notifications\SetNotificationSiteMute::class)(Heptapod\Models\NotificationDestination::query()->findOrFail((int) getenv("RACE_DELIVERY")),
                    Heptapod\Models\Site::query()->findOrFail($siteId), true, Heptapod\Models\User::query()->findOrFail($actorId));
                echo "muted";
            } catch (Illuminate\Validation\ValidationException) {
                echo "rejected";
            }
            PHP, $channel->id, $site->id.':'.$fixture['owner']->id);
        // This connection is the actor's profile deletion: it holds the user (its first engine lock), lets the mute run
        // into it, then archives the user's channel as DeleteProfile does.
        DB::beginTransaction();
        NotificationLocks::users([$fixture['owner']->id], exclusive: true);
        $mute->start();
        expect(channelLockAwaitWaiter($mute))->toBeGreaterThan(0, $mute->getOutput().$mute->getErrorOutput());
        $deleted = 'archived';
        try {
            app(ArchiveNotificationChannel::class)($channel, ArchiveNotificationChannel::RecipientDeleted, null);
            DB::commit();
        } catch (QueryException $exception) {
            DB::rollBack();
            $deleted = str_contains($exception->getMessage(), '40P01') ? 'deadlock' : throw $exception;
        }
        $mute->wait();
        // The mute waited for the deletion and then found its channel archived.
        expect($mute->isSuccessful())->toBeTrue($mute->getOutput().$mute->getErrorOutput())
            ->and([$deleted, trim($mute->getOutput())])->toBe(['archived', 'rejected'])
            ->and(DB::table('notification_site_mutes')->where('destination_id', $channel->id)->exists())->toBeFalse();
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DB::table('notification_site_mutes')->where('destination_id', $channel->id)->delete();
        DB::table('activity_log')->where('subject_type', (new NotificationDestination)->getMorphClass())->where('subject_id', $channel->id)->delete();
        DB::table('notification_preferences')->where('destination_id', $channel->id)->delete();
        DB::table('notification_destinations')->where('id', $channel->id)->delete();
        ($fixture['cleanup'])();
    }
});

it('records an uptime check error that releases a grouped dependent opening while that dependent check records, without a deadlock', function (): void {
    $fixture = clientMessageRaceFixture(alreadySent: 0);
    $site = Site::query()->where('client_id', $fixture['client']->id)->firstOrFail();
    try {
        $dependentCheck = Check::query()->where('site_id', $site->id)->where('type', CheckKey::BrokenLinks->value)->firstOrFail();
        $uptimeCheck = Check::factory()->create(['site_id' => $site->id, 'type' => CheckKey::Uptime, 'latest_run_result' => CheckResult::Failed,
            'consecutive_failures' => 3, 'failing_since' => now()->subMinutes(20)]);
        $outage = Incident::factory()->for($uptimeCheck)->create(['opened_at' => now()->subMinutes(15), 'first_failure_at' => now()->subMinutes(20)]);
        $dependent = Incident::factory()->for($dependentCheck)->create(['opened_at' => now()->subMinutes(10),
            'evidence' => ['status_code' => 503, 'uptime_parent_incident_id' => $outage->id]]);
        DB::commit();
        // The uptime recorder: a Check error now explains the outage, so the grouped broken-links incident gets its own opening.
        $recorder = channelLockProcess(<<<'PHP'
            $check = Heptapod\Models\Check::query()->findOrFail((int) getenv("RACE_DELIVERY"));
            $at = Carbon\CarbonImmutable::now("UTC");
            $claim = Heptapod\Testing\ClaimedRun::start($check, $at->subSecond());
            $timings = array_fill_keys(["namelookup_time", "connect_time", "appconnect_time", "starttransfer_time", "pretransfer_time", "redirect_time", "total_time", "header_size", "size_download", "speed_download"], null);
            app(Heptapod\Checks\Recording\ResultRecorder::class)->record(new Heptapod\Checks\Recording\RecordableRun($check->id, $claim->claimToken, $claim->startedAt, $at,
                new Heptapod\Contracts\CheckOutcome(Heptapod\Contracts\RunResult::Errored, "checks.engine.errored", [], new Heptapod\Addons\Uptime\UptimeMetrics(null, null, null, null, null, 0.2, $timings, $at->toIso8601String()),
                    error: new Heptapod\Contracts\CheckError(RuntimeException::class, "probe failed"))));
            echo "recorded";
            PHP, $uptimeCheck->id);
        // This connection is the broken-links recorder: it holds its check (its first lock), lets the uptime recorder run
        // into it, then takes the site as every recorder does.
        DB::beginTransaction();
        DB::table('checks')->where('id', $dependentCheck->id)->lockForUpdate()->first();
        $recorder->start();
        expect(channelLockAwaitWaiter($recorder))->toBeGreaterThan(0, $recorder->getOutput().$recorder->getErrorOutput());
        $recorded = 'recorded';
        try {
            NotificationLocks::sites([$site->id]);
            DB::commit();
        } catch (QueryException $exception) {
            DB::rollBack();
            $recorded = str_contains($exception->getMessage(), '40P01') ? 'deadlock' : throw $exception;
        }
        $recorder->wait();
        expect($recorder->isSuccessful())->toBeTrue($recorder->getOutput().$recorder->getErrorOutput())
            ->and([$recorded, trim($recorder->getOutput())])->toBe(['recorded', 'recorded'])
            ->and(Event::query()->where('check_id', $dependentCheck->id)->where('incident_id', $dependent->id)->where('type', 'incident.opened')->count())->toBe(1);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        $events = DB::table('events')->where('site_id', $site->id)->pluck('id');
        DB::table('deliveries')->whereIn('event_id', $events)->delete();
        DB::table('events')->whereIn('id', $events)->delete();
        DB::table('incidents')->where('site_id', $site->id)->delete();
        DB::table('checks')->where('site_id', $site->id)->where('type', CheckKey::Uptime->value)->delete();
        ($fixture['cleanup'])();
    }
});

it('wakes the openings a stopped window blocks while a claim re-defers one of them to another window of the site, without a deadlock', function (): void {
    $fixture = clientMessageRaceFixture(alreadySent: 0);
    $channel = NotificationDestination::factory()->create();
    $site = Site::query()->where('client_id', $fixture['client']->id)->firstOrFail();
    try {
        // Two muting windows on the site. The opening waits on the first; the second now owns the deferral (catch-up first).
        $first = MaintenancePeriod::factory()->create(['site_id' => $site->id, 'starts_at' => now()->subHour(), 'ends_at' => now()->addHours(2),
            'mute_alerts' => true, 'catch_up_at_end' => false]);
        $second = MaintenancePeriod::factory()->create(['site_id' => $site->id, 'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(),
            'mute_alerts' => true, 'catch_up_at_end' => true]);
        $wakeAt = CarbonImmutable::now('UTC');
        $delivery = channelLockDeferredOpening($site, $first, $channel);
        $delivery->update(['next_attempt_at' => $wakeAt->addMicrosecond()]);
        DB::commit();
        $claim = channelLockProcess('echo json_encode(app(Heptapod\Actions\ClaimNotificationDelivery::class)((int) getenv("RACE_DELIVERY"))?->id);', $delivery->id);
        // This connection is StopMaintenance: it holds the site's active windows in ascending id, lets the claim run into
        // them, then wakes the deliveries the first window blocks.
        DB::beginTransaction();
        NotificationLocks::maintenanceWindows([$first->id, $second->id], exclusive: true);
        $claim->start();
        expect(channelLockAwaitWaiter($claim))->toBeGreaterThan(0, $claim->getOutput().$claim->getErrorOutput());
        $woken = 'woken';
        try {
            HandleDeferredUptimeOpening::wakeWindow($first->id, $wakeAt);
            DB::commit();
        } catch (QueryException $exception) {
            DB::rollBack();
            $woken = str_contains($exception->getMessage(), '40P01') ? 'deadlock' : throw $exception;
        }
        $claim->wait();
        $delivery->refresh();
        // The claim waited for the wake, then deferred the opening again, now behind the second window.
        expect($claim->isSuccessful())->toBeTrue($claim->getOutput().$claim->getErrorOutput())
            ->and([$woken, trim($claim->getOutput())])->toBe(['woken', 'null'])
            ->and([$delivery->state, $delivery->blocking_maintenance_period_id, $delivery->last_reason])->toBe([DeliveryState::Pending, $second->id, 'deferred_by_maintenance']);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        $events = DB::table('events')->where('site_id', $site->id)->pluck('id');
        DB::table('deliveries')->whereIn('event_id', $events)->delete();
        DB::table('maintenance_catch_up_incidents')->whereIn('event_id', $events)->delete();
        DB::table('events')->whereIn('id', $events)->delete();
        DB::table('incidents')->where('site_id', $site->id)->delete();
        DB::table('maintenance_periods')->where('site_id', $site->id)->delete();
        DB::table('notification_preferences')->where('destination_id', $channel->id)->delete();
        DB::table('notification_destinations')->where('id', $channel->id)->delete();
        DB::table('checks')->where('site_id', $site->id)->where('type', CheckKey::Uptime->value)->delete();
        ($fixture['cleanup'])();
    }
});

it('deletes a client-wide window while a send of an outage opened in it completes on another site, without a deadlock', function (): void {
    $fixture = clientMessageRaceFixture(alreadySent: 0);
    $channel = NotificationDestination::factory()->create();
    $client = $fixture['client'];
    $siteIds = [];
    try {
        $deferredSite = Site::query()->where('client_id', $client->id)->firstOrFail();
        $sendingSite = Site::factory()->create(['client_id' => $client->id, 'url' => 'https://sending-'.$client->id.'.example.test']);
        $siteIds = [$deferredSite->id, $sendingSite->id];
        $window = MaintenancePeriod::factory()->create(['site_id' => null, 'target_type' => 'client', 'target_id' => $client->id,
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(), 'mute_alerts' => true, 'catch_up_at_end' => true]);
        // An opening deferred by the window on one site (so the deletion converts it and locks the channel) ...
        channelLockDeferredOpening($deferredSite, $window, $channel);
        // ... and on the other site an outage opened in the window whose opening is being sent: no delivery of it names the
        // window any more, but deleting the window SETs NULL on the incident, and completion writes that incident.
        $sending = channelLockDeferredOpening($sendingSite, $window, $channel);
        $outage = $sending->event()->firstOrFail()->incident()->firstOrFail();
        $outage->update(['opened_during_maintenance_window_id' => $window->id]);
        $token = (string) Str::uuid();
        DB::table('deliveries')->where('id', $sending->id)->update(['state' => 'sending', 'attempts' => 1, 'claim_token' => $token,
            'deferred_maintenance_period_id' => null, 'blocking_maintenance_period_id' => null, 'last_reason' => null,
            'lease_until' => now()->addMinute()->format('Y-m-d H:i:s.uP')]);
        DB::commit();
        $deletion = channelLockProcess(
            'app(Heptapod\Actions\DeleteMaintenancePeriod::class)(Heptapod\Models\MaintenancePeriod::query()->findOrFail((int) getenv("RACE_DELIVERY"))); echo "deleted";', $window->id);
        // This connection is the completing worker: it holds the sending site and the outage (completion's first locks),
        // lets the deletion run into the outage, then completes for real, which needs the channel.
        DB::beginTransaction();
        NotificationLocks::sites([$sendingSite->id]);
        NotificationLocks::incidents([$outage->id]);
        $deletion->start();
        expect(channelLockAwaitWaiter($deletion))->toBeGreaterThan(0, $deletion->getOutput().$deletion->getErrorOutput());
        $completed = 'completed';
        try {
            app(CompleteNotificationDelivery::class)($sending->id, $token, new DeliveryResult(DeliveryState::Sent));
            DB::commit();
        } catch (QueryException $exception) {
            DB::rollBack();
            $completed = str_contains($exception->getMessage(), '40P01') ? 'deadlock' : throw $exception;
        }
        $deletion->wait();
        expect($deletion->isSuccessful())->toBeTrue($deletion->getOutput().$deletion->getErrorOutput())
            ->and([$completed, trim($deletion->getOutput())])->toBe(['completed', 'deleted'])
            ->and($sending->refresh()->state)->toBe(DeliveryState::Sent)
            ->and($outage->refresh()->opened_during_maintenance_window_id)->toBeNull()
            ->and(MaintenancePeriod::query()->find($window->id))->toBeNull();
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        $events = DB::table('events')->whereIn('site_id', $siteIds)->pluck('id');
        DB::table('deliveries')->whereIn('event_id', $events)->delete();
        DB::table('maintenance_catch_up_incidents')->whereIn('event_id', $events)->delete();
        DB::table('events')->whereIn('id', $events)->delete();
        DB::table('maintenance_periods')->where('target_type', 'client')->where('target_id', $client->id)->delete();
        DB::table('notification_preferences')->where('destination_id', $channel->id)->delete();
        DB::table('notification_destinations')->where('id', $channel->id)->delete();
        DB::table('incidents')->whereIn('site_id', $siteIds)->delete();
        DB::table('checks')->whereIn('site_id', $siteIds)->where('type', CheckKey::Uptime->value)->delete();
        ($fixture['cleanup'])();
    }
});

/**
 * Two checks on $site, each with an open incident and a pending alert on $channel. The second check's alert has the lower
 * delivery id, so resolving check after check would lock the deliveries out of id order.
 *
 * @return array{low: Delivery, high: Delivery, checkIds: list<int>, events: list<int>}
 */
function channelLockSiteWithCrossedAlerts(Site $site, NotificationDestination $channel): array
{
    $first = Check::query()->where('site_id', $site->id)->orderBy('id')->firstOrFail();
    $second = Check::factory()->create(['site_id' => $site->id, 'type' => CheckKey::Uptime, 'latest_run_result' => CheckResult::Failed]);
    $alert = static function (Check $check) use ($site, $channel): Delivery {
        $incident = Incident::factory()->for($check)->create(['opened_at' => now()->subMinutes(10)]);
        $opening = Event::query()->create(['type' => 'incident.opened', 'site_id' => $site->id, 'check_id' => $check->id, 'incident_id' => $incident->id,
            'occurred_at' => now()->subMinutes(10), 'to_result' => CheckResult::Failed, 'cause' => IncidentCause::Failed,
            'payload' => [], 'suppressed' => false, 'suppression_evaluated_at' => now(), 'notification_routed_at' => now()]);

        return Delivery::query()->create(['event_id' => $opening->id, 'destination_id' => $channel->id, 'next_attempt_at' => now()->addHour(),
            'suppressed' => false, 'suppression_evaluated_at' => now()]);
    };
    $low = $alert($second);
    $high = $alert($first);

    /** @var list<int> $events */
    $events = Event::query()->where('site_id', $site->id)->pluck('id')->all();

    return ['low' => $low, 'high' => $high, 'checkIds' => [$first->id, $second->id], 'events' => $events];
}

/**
 * Cleanup for channelLockSiteWithCrossedAlerts: also the rows a deleted site leaves behind (events keep their history).
 *
 * @param  list<int>  $checkIds
 */
function channelLockSiteCleanup(Site $site, NotificationDestination $channel, array $checkIds): void
{
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    $events = DB::table('events')->where('site_id', $site->id)->orWhereIn('check_id', $checkIds)
        ->orWhereIn('id', DB::table('deliveries')->where('destination_id', $channel->id)->select('event_id'))->pluck('id');
    DB::table('deliveries')->whereIn('event_id', $events)->delete();
    DB::table('events')->whereIn('id', $events)->delete();
    DB::table('activity_log')->where('subject_type', (new NotificationDestination)->getMorphClass())->where('subject_id', $channel->id)->delete();
    DB::table('notification_preferences')->where('destination_id', $channel->id)->delete();
    DB::table('notification_destinations')->where('id', $channel->id)->delete();
    DB::table('maintenance_periods')->where('site_id', $site->id)->delete();
    DB::table('incidents')->where('site_id', $site->id)->delete();
    DB::table('checks')->where('site_id', $site->id)->where('type', CheckKey::Uptime->value)->delete();
}

it('deletes a site while one of its alert channels is archived, without a deadlock', function (): void {
    $fixture = clientMessageRaceFixture(alreadySent: 0);
    $channel = NotificationDestination::factory()->create();
    $site = Site::query()->where('client_id', $fixture['client']->id)->firstOrFail();
    $checkIds = [];
    try {
        $setup = channelLockSiteWithCrossedAlerts($site, $channel);
        $checkIds = $setup['checkIds'];
        DB::commit();
        $deletion = channelLockProcess('app(Heptapod\Actions\DeleteSite::class)(Heptapod\Models\Site::query()->findOrFail((int) getenv("RACE_DELIVERY"))); echo "deleted";', $site->id);
        // This connection is the archive: it holds the lower delivery (the first row of its ascending pass), lets the
        // deletion run into it, then archives the channel for real, which needs the higher delivery too.
        DB::beginTransaction();
        NotificationLocks::deliveries([$setup['low']->id]);
        $deletion->start();
        expect(channelLockAwaitWaiter($deletion))->toBeGreaterThan(0, $deletion->getOutput().$deletion->getErrorOutput());
        $archived = 'archived';
        try {
            app(ArchiveNotificationChannel::class)($channel, ArchiveNotificationChannel::Archived, null);
            DB::commit();
        } catch (QueryException $exception) {
            DB::rollBack();
            $archived = str_contains($exception->getMessage(), '40P01') ? 'deadlock' : throw $exception;
        }
        $deletion->wait();
        expect($deletion->isSuccessful())->toBeTrue($deletion->getOutput().$deletion->getErrorOutput())
            ->and([$archived, trim($deletion->getOutput())])->toBe(['archived', 'deleted'])
            ->and(Site::query()->find($site->id))->toBeNull()
            ->and([$setup['low']->refresh()->suppressed, $setup['high']->refresh()->suppressed])->toBe([true, true]);
    } finally {
        channelLockSiteCleanup($site, $channel, $checkIds);
        ($fixture['cleanup'])();
    }
});

it('archives a channel with an older recurring digest and a newer queued alert while the site is deleted, without a deadlock', function (): void {
    $fixture = clientMessageRaceFixture(alreadySent: 0);
    $channel = NotificationDestination::factory()->create();
    $site = Site::query()->where('client_id', $fixture['client']->id)->firstOrFail();
    $checkIds = [];
    try {
        $setup = channelLockSiteWithCrossedAlerts($site, $channel);
        $checkIds = $setup['checkIds'];
        // The lower delivery is a sent digest source whose recurrence the archive ends; the higher one is a queued alert it suppresses.
        DB::table('deliveries')->where('id', $setup['low']->id)->update(['digest' => true, 'state' => 'sent', 'sent_at' => now()->format('Y-m-d H:i:s.uP')]);
        DB::commit();
        // The deletion's after-commit planning would run inside this test's open transaction; it is not under test.
        Queue::fake();
        $archive = channelLockProcess('app(Heptapod\Actions\ArchiveNotificationChannel::class)(Heptapod\Models\NotificationDestination::query()->findOrFail((int) getenv("RACE_DELIVERY")), "archived", null); echo "archived";', $channel->id);
        // This connection is the site deletion: it holds the lower delivery (the first row of its ascending pass), lets the
        // archive run into it, then deletes the site for real, which needs the higher delivery too.
        DB::beginTransaction();
        NotificationLocks::deliveries([$setup['low']->id]);
        $archive->start();
        expect(channelLockAwaitWaiter($archive))->toBeGreaterThan(0, $archive->getOutput().$archive->getErrorOutput());
        $deleted = 'deleted';
        try {
            app(DeleteSite::class)($site);
            DB::commit();
        } catch (QueryException|DeadlockException $exception) {
            DB::rollBack();
            $deleted = str_contains($exception->getMessage(), '40P01') ? 'deadlock' : throw $exception;
        }
        $archive->wait();
        expect($archive->isSuccessful())->toBeTrue($archive->getOutput().$archive->getErrorOutput())
            ->and([$deleted, trim($archive->getOutput())])->toBe(['deleted', 'archived'])
            ->and(Site::query()->find($site->id))->toBeNull()
            ->and($setup['low']->refresh()->digest_recurrence_ended_at)->not->toBeNull()
            ->and($setup['high']->refresh()->suppressed)->toBeTrue();
    } finally {
        channelLockSiteCleanup($site, $channel, $checkIds);
        ($fixture['cleanup'])();
    }
});

it('deletes a site while its maintenance recurrence generates windows, without a deadlock', function (): void {
    $fixture = clientMessageRaceFixture(alreadySent: 0);
    $channel = NotificationDestination::factory()->create();
    $site = Site::query()->where('client_id', $fixture['client']->id)->firstOrFail();
    $checkIds = [];
    try {
        $setup = channelLockSiteWithCrossedAlerts($site, $channel);
        $checkIds = $setup['checkIds'];
        $recurrence = RecurringMaintenancePeriod::factory()->create(['site_id' => $site->id]);
        DB::commit();
        $deletion = channelLockProcess('app(Heptapod\Actions\DeleteSite::class)(Heptapod\Models\Site::query()->findOrFail((int) getenv("RACE_DELIVERY"))); echo "deleted";', $site->id);
        // This connection is the generation: it holds the recurrence (its first lock), lets the deletion run into it, then
        // generates for real, which inserts windows that take the site's key.
        DB::beginTransaction();
        NotificationLocks::maintenanceRecurrences([$recurrence->id], exclusive: true);
        $deletion->start();
        expect(channelLockAwaitWaiter($deletion))->toBeGreaterThan(0, $deletion->getOutput().$deletion->getErrorOutput());
        $generated = 'generated';
        try {
            app(GenerateMaintenancePeriods::class)($recurrence, now()->addDays(3)->toImmutable());
            DB::commit();
        } catch (QueryException|DeadlockException $exception) {
            DB::rollBack();
            $generated = str_contains($exception->getMessage(), '40P01') ? 'deadlock' : throw $exception;
        }
        $deletion->wait();
        // The deletion waited for the generation, then deleted the recurrence and the windows it had just generated.
        expect($deletion->isSuccessful())->toBeTrue($deletion->getOutput().$deletion->getErrorOutput())
            ->and([$generated, trim($deletion->getOutput())])->toBe(['generated', 'deleted'])
            ->and(Site::query()->find($site->id))->toBeNull()
            ->and(RecurringMaintenancePeriod::query()->find($recurrence->id))->toBeNull()
            ->and(MaintenancePeriod::query()->where('recurring_maintenance_period_id', $recurrence->id)->exists())->toBeFalse();
    } finally {
        DB::table('recurring_maintenance_periods')->where('site_id', $site->id)->delete();
        channelLockSiteCleanup($site, $channel, $checkIds);
        ($fixture['cleanup'])();
    }
});

it('rolls a site deletion aborted by a deadlock in its window cascade back completely, surfaces the error and completes on retry', function (): void {
    $fixture = clientMessageRaceFixture(alreadySent: 0);
    $channel = NotificationDestination::factory()->create();
    $site = Site::query()->where('client_id', $fixture['client']->id)->firstOrFail();
    $checkIds = [];
    try {
        $setup = channelLockSiteWithCrossedAlerts($site, $channel);
        $checkIds = $setup['checkIds'];
        // The site's own window: the cascade into it is the deletion's last lock and where a catch-up can cycle with it.
        $window = MaintenancePeriod::factory()->create(['site_id' => $site->id, 'starts_at' => now()->subHour(), 'ends_at' => now()->subMinute(),
            'mute_alerts' => true, 'catch_up_at_end' => true]);
        // Postgres aborts the cascade as it aborts a deadlock victim.
        DB::unprepared("CREATE FUNCTION nl_site_delete_deadlock() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN
            RAISE EXCEPTION 'deadlock detected' USING ERRCODE = '40P01'; END \$\$");
        DB::unprepared('CREATE TRIGGER nl_site_delete_deadlock BEFORE DELETE ON maintenance_periods FOR EACH ROW EXECUTE FUNCTION nl_site_delete_deadlock()');
        DB::commit();
        // The deletion's own transaction is the top level here, as in a request: it runs once more, and the second 40P01
        // surfaces as a QueryException (a 500).
        expect(fn () => app(DeleteSite::class)($site))->toThrow(QueryException::class, '40P01');
        expect(DB::transactionLevel())->toBe(0)
            ->and(Site::query()->find($site->id))->not->toBeNull()
            ->and(Check::query()->whereKey($setup['checkIds'])->count())->toBe(2)
            ->and(Incident::query()->where('site_id', $site->id)->whereNull('resolved_at')->count())->toBe(2)
            ->and(Event::query()->where('site_id', $site->id)->where('type', 'incident.resolved')->exists())->toBeFalse()
            ->and([$setup['low']->refresh()->suppressed, $setup['high']->refresh()->suppressed])->toBe([false, false])
            ->and(MaintenancePeriod::query()->find($window->id))->not->toBeNull();
        DB::unprepared('DROP TRIGGER nl_site_delete_deadlock ON maintenance_periods; DROP FUNCTION nl_site_delete_deadlock()');
        // The user retries; the deletion completes.
        app(DeleteSite::class)($site);
        expect(Site::query()->find($site->id))->toBeNull()
            ->and([$setup['low']->refresh()->suppressed, $setup['high']->refresh()->suppressed])->toBe([true, true]);
    } finally {
        DB::unprepared('DROP TRIGGER IF EXISTS nl_site_delete_deadlock ON maintenance_periods; DROP FUNCTION IF EXISTS nl_site_delete_deadlock()');
        channelLockSiteCleanup($site, $channel, $checkIds);
        ($fixture['cleanup'])();
    }
});

it('groups a dependent failure under a new uptime outage while the archive suppresses its alerts, without a deadlock', function (): void {
    $fixture = clientMessageRaceFixture(alreadySent: 0);
    $channel = NotificationDestination::factory()->create();
    $site = Site::query()->where('client_id', $fixture['client']->id)->firstOrFail();
    try {
        $dependentCheck = Check::query()->where('site_id', $site->id)->where('type', CheckKey::BrokenLinks->value)->firstOrFail();
        $uptimeCheck = Check::factory()->create(['site_id' => $site->id, 'type' => CheckKey::Uptime, 'latest_run_result' => CheckResult::Failed,
            'failure_threshold' => 1]);
        $dependent = Incident::factory()->for($dependentCheck)->create(['opened_at' => now()->subMinutes(10),
            'evidence' => ['status_code' => 503, 'availability_failure' => 'http_5xx']]);
        // Two queued alerts of the dependent incident on the channel; grouping suppresses both. The higher delivery comes first
        // in every scan an unordered UPDATE can use: its event has the lower id (event_id index) and the lower delivery's row
        // is rewritten after it (heap order).
        $events = collect(['incident.opened', 'check.result_changed'])->map(static fn (string $type): Event => Event::query()->create(['type' => $type,
            'site_id' => $site->id, 'check_id' => $dependentCheck->id, 'incident_id' => $dependent->id, 'occurred_at' => now()->subMinutes(9),
            'to_result' => CheckResult::Failed, 'cause' => IncidentCause::Failed, 'payload' => [], 'suppressed' => false,
            'suppression_evaluated_at' => now(), 'notification_routed_at' => now()]));
        $alert = static fn (Event $event): Delivery => Delivery::query()->create(['event_id' => $event->id, 'destination_id' => $channel->id,
            'next_attempt_at' => now()->addHour(), 'suppressed' => false, 'suppression_evaluated_at' => now()]);
        [$older, $newer] = $events->all();
        $low = $alert($newer);
        $high = $alert($older);
        DB::table('deliveries')->where('id', $low->id)->update(['next_attempt_at' => now()->addHours(2)->format('Y-m-d H:i:s.uP')]);
        DB::commit();
        // The uptime recorder: a 5xx opens the outage, which groups the dependent incident and suppresses its alerts.
        $recorder = channelLockProcess(<<<'PHP'
            $check = Heptapod\Models\Check::query()->findOrFail((int) getenv("RACE_DELIVERY"));
            $at = Carbon\CarbonImmutable::now("UTC");
            $claim = Heptapod\Testing\ClaimedRun::start($check, $at->subSecond());
            $timings = array_fill_keys(["namelookup_time", "connect_time", "appconnect_time", "starttransfer_time", "pretransfer_time", "redirect_time", "total_time", "header_size", "size_download", "speed_download"], null);
            app(Heptapod\Checks\Recording\ResultRecorder::class)->record(new Heptapod\Checks\Recording\RecordableRun($check->id, $claim->claimToken, $claim->startedAt, $at,
                new Heptapod\Contracts\CheckOutcome(Heptapod\Contracts\RunResult::Failed, "uptime.failed", [], new Heptapod\Addons\Uptime\UptimeMetrics(503, null, null, null, null, 0.2, $timings, $at->toIso8601String()),
                    availabilityFailure: Heptapod\Contracts\AvailabilityFailure::Http5xx)));
            echo "recorded";
            PHP, $uptimeCheck->id);
        // This connection is the archive's ascending pass: it holds the lower alert, lets the grouping run into it, then takes
        // the higher. Grouping locks the same rows ascending first, so it waits on the lower one holding nothing above it.
        DB::beginTransaction();
        NotificationLocks::deliveries([$low->id]);
        $recorder->start();
        expect(channelLockAwaitWaiter($recorder))->toBeGreaterThan(0, $recorder->getOutput().$recorder->getErrorOutput());
        $archived = 'archived';
        try {
            NotificationLocks::deliveries([$high->id]);
            DB::commit();
        } catch (QueryException $exception) {
            DB::rollBack();
            $archived = str_contains($exception->getMessage(), '40P01') ? 'deadlock' : throw $exception;
        }
        $recorder->wait();
        expect($recorder->isSuccessful())->toBeTrue($recorder->getOutput().$recorder->getErrorOutput())
            ->and([$archived, trim($recorder->getOutput())])->toBe(['archived', 'recorded'])
            ->and([$low->refresh()->suppression_reason, $high->refresh()->suppression_reason])->toBe(['uptime_dependency', 'uptime_dependency']);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        $events = DB::table('events')->where('site_id', $site->id)->pluck('id');
        DB::table('deliveries')->whereIn('event_id', $events)->delete();
        DB::table('events')->whereIn('id', $events)->delete();
        DB::table('incidents')->where('site_id', $site->id)->delete();
        DB::table('notification_preferences')->where('destination_id', $channel->id)->delete();
        DB::table('notification_destinations')->where('id', $channel->id)->delete();
        DB::table('checks')->where('site_id', $site->id)->where('type', CheckKey::Uptime->value)->delete();
        ($fixture['cleanup'])();
    }
});

/**
 * A committed uptime check on the fixture client's site, claimed and started, whose recording Postgres aborts as a deadlock
 * victim $deadlocks times (at its first event insert, after the run, the check and the incident are written).
 *
 * @return array{check: Check, claim: CheckContext, record: Closure(): RecordedRun, cleanup: Closure(): void}
 */
function channelLockRecorderDeadlockFixture(int $deadlocks): array
{
    $fixture = clientMessageRaceFixture(alreadySent: 0);
    $site = Site::query()->where('client_id', $fixture['client']->id)->firstOrFail();
    $check = Check::factory()->create(['site_id' => $site->id, 'type' => CheckKey::Uptime, 'failure_threshold' => 1]);
    $claim = ClaimedRun::start($check, now()->subSecond()->toImmutable());
    // A sequence counts the aborted attempts: it does not roll back with them.
    DB::unprepared('CREATE SEQUENCE nl_recorder_deadlocks');
    DB::unprepared("CREATE FUNCTION nl_recorder_deadlock() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN
        IF NEW.check_id = {$check->id} AND (SELECT CASE WHEN is_called THEN last_value ELSE 0 END FROM nl_recorder_deadlocks) < {$deadlocks} THEN
            PERFORM nextval('nl_recorder_deadlocks');
            RAISE EXCEPTION 'deadlock detected' USING ERRCODE = '40P01'; END IF; RETURN NEW; END \$\$");
    DB::unprepared('CREATE TRIGGER nl_recorder_deadlock BEFORE INSERT ON events FOR EACH ROW EXECUTE FUNCTION nl_recorder_deadlock()');
    DB::commit();
    $timings = array_fill_keys(['namelookup_time', 'connect_time', 'appconnect_time', 'starttransfer_time', 'pretransfer_time', 'redirect_time', 'total_time', 'header_size', 'size_download', 'speed_download'], null);
    $record = static fn (): RecordedRun => app(ResultRecorder::class)->record(
        new RecordableRun($check->id, $claim->claimToken, $claim->startedAt, now()->toImmutable(),
            new CheckOutcome(RunResult::Failed, 'uptime.failed', [], new UptimeMetrics(503, null, null, null, null, 0.2, $timings, now()->toIso8601String()),
                availabilityFailure: AvailabilityFailure::Http5xx)));

    return ['check' => $check, 'claim' => $claim, 'record' => $record, 'cleanup' => static function () use ($fixture, $site): void {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DB::unprepared('DROP TRIGGER IF EXISTS nl_recorder_deadlock ON events; DROP FUNCTION IF EXISTS nl_recorder_deadlock(); DROP SEQUENCE IF EXISTS nl_recorder_deadlocks');
        $events = DB::table('events')->where('site_id', $site->id)->pluck('id');
        DB::table('deliveries')->whereIn('event_id', $events)->delete();
        DB::table('events')->whereIn('id', $events)->delete();
        DB::table('incidents')->where('site_id', $site->id)->delete();
        DB::table('checks')->where('site_id', $site->id)->where('type', CheckKey::Uptime->value)->delete();
        ($fixture['cleanup'])();
    }];
}

it('records an uptime failure exactly once when its recording deadlocks once, and fences a repeated recording by the claim token', function (): void {
    $setup = channelLockRecorderDeadlockFixture(deadlocks: 1);
    try {
        Queue::fake();
        $check = $setup['check'];
        // The recorder's transaction is the top level here, as in a worker: the first attempt aborts and runs once more.
        $recorded = ($setup['record'])();
        expect($recorded->accepted)->toBeTrue()
            ->and(DB::scalar('SELECT last_value FROM nl_recorder_deadlocks'))->toBe(1)
            ->and(CheckRun::query()->where('check_id', $check->id)->pluck('result')->all())->toBe([RunResult::Failed])
            ->and(CheckRun::query()->where('check_id', $check->id)->sole()->id)->toBe($recorded->runId)
            ->and(Incident::query()->where('check_id', $check->id)->count())->toBe(1)
            ->and(Event::query()->where('check_id', $check->id)->where('type', 'check.result_changed')->count())->toBe(1)
            ->and($check->refresh()->claim_token)->toBeNull()
            ->and($check->latest_run_result)->toBe(CheckResult::Failed)
            ->and(CheckRunAttempt::query()->where('claim_token', $setup['claim']->claimToken)->sole()->phase)->toBe(RunPhase::Failed);
        // The committed recording cleared the claim token: recording the same claim again is discarded as stale.
        $events = Event::query()->where('check_id', $check->id)->count();
        expect(($setup['record'])()->accepted)->toBeFalse()
            ->and(CheckRun::query()->where('check_id', $check->id)->count())->toBe(1)
            ->and(Event::query()->where('check_id', $check->id)->count())->toBe($events);
    } finally {
        ($setup['cleanup'])();
    }
});

it('records a serialized cron evaluation exactly once when its recording deadlocks once inside the executor\'s transaction', function (): void {
    $fixture = clientMessageRaceFixture(alreadySent: 0);
    $site = Site::query()->where('client_id', $fixture['client']->id)->firstOrFail();
    $check = Check::factory()->create(['site_id' => $site->id, 'type' => CheckKey::Cron, 'failure_threshold' => 1]);
    try {
        // One overdue task fails the evaluation; a stale attempt before switched this check to the serialized evaluation.
        $task = app(CronDefinitions::class)->create($check->id, ['name' => 'Nightly backup', 'type' => 'simple', 'frequency_in_minutes' => 5, 'grace_time_in_minutes' => 2]);
        DB::table('cron_checks')->where('id', $task->id)->update(['next_due_at' => now()->subHour()->format('Y-m-d H:i:s.uP')]);
        DB::table('cron_definition_revisions')->where('check_id', $check->id)->update(['serialized_retry' => true]);
        Bus::fake([RunCheck::class]);
        $claimToken = app(ClaimService::class)->runNow($check->id, RunOrigin::System)?->claim_token ?? throw new LogicException('The cron check must be claimable.');
        DB::unprepared('CREATE SEQUENCE nl_recorder_deadlocks');
        DB::unprepared("CREATE FUNCTION nl_recorder_deadlock() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN
            IF NEW.check_id = {$check->id} AND (SELECT CASE WHEN is_called THEN last_value ELSE 0 END FROM nl_recorder_deadlocks) < 1 THEN
                PERFORM nextval('nl_recorder_deadlocks');
                RAISE EXCEPTION 'deadlock detected' USING ERRCODE = '40P01'; END IF; RETURN NEW; END \$\$");
        DB::unprepared('CREATE TRIGGER nl_recorder_deadlock BEFORE INSERT ON events FOR EACH ROW EXECUTE FUNCTION nl_recorder_deadlock()');
        DB::commit();
        Queue::fake();
        expect(app(CronCheckType::class)->requiresSerialization($check->id))->toBeTrue();
        // The worker's executor: its serialized transaction is the top level and the recorder's transaction is nested in it.
        app(CheckExecutor::class)->execute($check->id, $claimToken, 30);
        expect(DB::scalar('SELECT last_value FROM nl_recorder_deadlocks'))->toBe(1)
            ->and(CheckRun::query()->where('check_id', $check->id)->pluck('result')->all())->toBe([RunResult::Failed])
            ->and(Incident::query()->where('check_id', $check->id)->count())->toBe(1)
            ->and(Event::query()->where('check_id', $check->id)->where('type', 'check.result_changed')->count())->toBe(1)
            ->and($check->refresh()->claim_token)->toBeNull()
            ->and(CheckRunAttempt::query()->where('claim_token', $claimToken)->sole()->phase)->toBe(RunPhase::Failed);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DB::unprepared('DROP TRIGGER IF EXISTS nl_recorder_deadlock ON events; DROP FUNCTION IF EXISTS nl_recorder_deadlock(); DROP SEQUENCE IF EXISTS nl_recorder_deadlocks');
        $events = DB::table('events')->where('site_id', $site->id)->pluck('id');
        DB::table('deliveries')->whereIn('event_id', $events)->delete();
        DB::table('events')->whereIn('id', $events)->delete();
        DB::table('incidents')->where('site_id', $site->id)->delete();
        DB::table('checks')->where('id', $check->id)->delete();
        ($fixture['cleanup'])();
    }
});

it('fails a recording that deadlocks twice and records an errored run carrying the deadlock, as the failed job does', function (): void {
    $setup = channelLockRecorderDeadlockFixture(deadlocks: 2);
    try {
        Queue::fake();
        $check = $setup['check'];
        $deadlock = null;
        try {
            ($setup['record'])();
        } catch (QueryException $exception) {
            $deadlock = $exception;
        }
        expect($deadlock)->toBeInstanceOf(QueryException::class)
            ->and($deadlock?->getMessage())->toContain('40P01')
            ->and(DB::scalar('SELECT last_value FROM nl_recorder_deadlocks'))->toBe(2)
            ->and(DB::transactionLevel())->toBe(0)
            ->and(CheckRun::query()->where('check_id', $check->id)->exists())->toBeFalse()
            ->and(Incident::query()->where('check_id', $check->id)->exists())->toBeFalse()
            ->and($check->refresh()->claim_token)->toBe($setup['claim']->claimToken);
        // RunCheck has one try; its failed() hook records the failure through the executor.
        new RunCheck($check->id, $setup['claim']->claimToken, 30, 'checks-fast')->failed($deadlock);
        $run = CheckRun::query()->where('check_id', $check->id)->sole();
        expect($run->result)->toBe(RunResult::Errored)
            ->and((string) $run->error)->toContain('40P01')
            ->and($check->refresh()->claim_token)->toBeNull();
    } finally {
        ($setup['cleanup'])();
    }
});
