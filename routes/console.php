<?php

declare(strict_types=1);

use Heptapod\Actions\RecordScheduledBackupExit;
use Heptapod\Actions\RecordSchedulerTick;
use Heptapod\Jobs\DispatchNotifications;
use Heptapod\Models\DispatcherHeartbeat;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Schedule;

// Overlap locks expire after a few cadences (Laravel's default is a day), so a hung run blocks only its next ticks; the
// background statement timeout bounds each query of a scheduled command (AppServiceProvider::configureDefaults).
Schedule::command('heptapod:maintain-check-data')->hourly()->withoutOverlapping(120);

Schedule::command('heptapod:generate-maintenance')->daily()->withoutOverlapping(720);
Schedule::command('heptapod:prune-api-idempotency')->hourly()->withoutOverlapping(120);
Schedule::command('model:prune', ['--model' => [DispatcherHeartbeat::class]])->hourly()->withoutOverlapping(120);
Schedule::command('queue:prune-failed', ['--hours' => config()->integer('monitoring.failed_job_retention_days') * 24])->daily()->withoutOverlapping(720);
Schedule::command('activitylog:clean', ['--days' => config()->integer('monitoring.history_retention_days'), '--force'])->daily()->withoutOverlapping(720);
Schedule::command('heptapod:record-database-size')->hourly()->withoutOverlapping(120);
Schedule::command('heptapod:client-messages:sweep')->daily()->withoutOverlapping(720);
Schedule::command('heptapod:resume-due-sites')->everyMinute()->withoutOverlapping(10);
// No overlap mutex: the claim's FOR UPDATE SKIP LOCKED already serializes concurrent ticks, and a killed run must not hold a mutex.
Schedule::command('heptapod:announce-maintenance-boundaries')->everyMinute();
Schedule::call(app(RecordSchedulerTick::class))->everyMinute();
// The dispatcher also runs this evaluation while the scheduler heartbeat is stale (DispatchChecksCommand).
Schedule::command('heptapod:evaluate-system-issues')->everyFiveMinutes()->withoutOverlapping(15);
Schedule::command('backup:clean')->dailyAt('02:00')->withoutOverlapping(720);
// In the background no schedule:run parent waits in memory beside the backup and the minute ticks (scheduler memory
// budget in docs/deploy/operations.md); schedule:finish then hands the real exit code, including an OOM kill, to onFailure.
Schedule::command('backup:run --isolated=1')->dailyAt('02:30')->withoutOverlapping(720)->runInBackground()
    ->onFailure(static function (Event $event): void {
        // The first due time after one day ago is the 02:30 at which this run started.
        app(RecordScheduledBackupExit::class)($event->exitCode ?? throw new LogicException('A finished scheduled event must have an exit code.'),
            $event->nextRunDate(now()->subDay())->toImmutable());
    });
Schedule::command('heptapod:refresh-site-icons')->dailyAt('04:30')->withoutOverlapping(720);
Schedule::command('heptapod:check-updates')->dailyAt('04:00')->withoutOverlapping(720);

if (config('instance.heartbeat_url') !== null && config('instance.heartbeat_url') !== '') {
    Schedule::command('instance:heartbeat')->everyMinute()->withoutOverlapping(10);
}

Schedule::job(new DispatchNotifications)->everyMinute()->withoutOverlapping(10);
