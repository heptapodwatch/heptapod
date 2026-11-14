<?php

declare(strict_types=1);

use Heptapod\Actions\RecordScheduledBackupExit;
use Heptapod\Actions\RecordSchedulerTick;
use Heptapod\Jobs\DispatchNotifications;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Schedule;

Schedule::command('heptapod:maintain-check-data')->hourly()->withoutOverlapping();

Schedule::command('heptapod:generate-maintenance')->daily()->withoutOverlapping();
Schedule::command('heptapod:prune-api-idempotency')->hourly()->withoutOverlapping();
Schedule::command('heptapod:record-database-size')->hourly()->withoutOverlapping();
Schedule::command('heptapod:client-messages:sweep')->daily()->withoutOverlapping();
Schedule::command('heptapod:resume-due-sites')->everyMinute()->withoutOverlapping();
// No overlap mutex: the claim's FOR UPDATE SKIP LOCKED already serializes concurrent ticks, and a killed run must not hold a mutex.
Schedule::command('heptapod:announce-maintenance-boundaries')->everyMinute();
Schedule::call(app(RecordSchedulerTick::class))->everyMinute();
// The dispatcher also runs this evaluation while the scheduler heartbeat is stale (DispatchChecksCommand).
Schedule::command('heptapod:evaluate-system-issues')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('backup:clean')->dailyAt('02:00')->withoutOverlapping();
// In the background no schedule:run parent waits in memory beside the backup and the minute ticks (scheduler memory
// budget in docs/deploy/operations.md); schedule:finish then hands the real exit code, including an OOM kill, to onFailure.
Schedule::command('backup:run --isolated=1')->dailyAt('02:30')->withoutOverlapping()->runInBackground()
    ->onFailure(static function (Event $event): void {
        // The first due time after one day ago is the 02:30 at which this run started.
        app(RecordScheduledBackupExit::class)($event->exitCode ?? throw new LogicException('A finished scheduled event must have an exit code.'),
            $event->nextRunDate(now()->subDay())->toImmutable());
    });
Schedule::command('heptapod:refresh-site-icons')->dailyAt('04:30')->withoutOverlapping();
Schedule::command('heptapod:check-updates')->dailyAt('04:00')->withoutOverlapping();

if (config('instance.heartbeat_url') !== null && config('instance.heartbeat_url') !== '') {
    Schedule::command('instance:heartbeat')->everyMinute()->withoutOverlapping();
}

Schedule::job(new DispatchNotifications)->everyMinute()->withoutOverlapping();
