<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Models\SystemIssue;
use Heptapod\Models\User;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\BackupWasSuccessful;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-28T02:31:00Z'));
    config(['mail.default' => 'smtp']);
    Mail::fake();
    $owner = User::factory()->owner()->create();
});

/** The nightly backup event as routes/console.php registers it. */
function nightlyBackupEvent(): ScheduledEvent
{
    return collect(app(Schedule::class)->events())->sole(fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'backup:run'));
}

/** What the backgrounded shell runs after backup:run exits: `artisan schedule:finish "<mutex>" "$?"`. */
function finishNightlyBackup(int $exitCode): void
{
    test()->artisan('schedule:finish', ['id' => nightlyBackupEvent()->mutexName(), 'code' => $exitCode])->assertSuccessful();
}

it('opens backup_failed at the next evaluation when the nightly backup is killed and closes it after the next success', function (): void {
    // Yesterday's success is older than tonight's 02:30 run and must not count as its outcome.
    $this->travelTo(CarbonImmutable::parse('2026-10-27T02:40:00Z'));
    event(new BackupWasSuccessful('backups', 'Heptapod'));
    $this->travelTo(CarbonImmutable::parse('2026-10-28T02:31:00Z'));
    // No parent process may wait in the scheduler's memory beside the backup.
    expect(nightlyBackupEvent()->runInBackground)->toBeTrue()->and(nightlyBackupEvent()->expression)->toBe('30 2 * * *');

    finishNightlyBackup(137);

    expect(DB::table('backup_runs')->orderByDesc('id')->first(['state', 'requested_by', 'completed_at']))
        ->toEqual((object) ['state' => 'failed', 'requested_by' => null, 'completed_at' => '2026-10-28 02:31:00+00']);
    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();
    $issue = SystemIssue::open()->where('kind', 'backup_failed')->sole();
    expect($issue->details)->toBe(['disk' => null, 'failed_at' => '2026-10-28T02:31:00+00:00']);

    $this->travelTo(CarbonImmutable::parse('2026-10-28T03:10:00Z'));
    event(new BackupWasSuccessful('backups', 'Heptapod'));
    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();
    expect($issue->refresh()->resolved_at?->toIso8601String())->toBe('2026-10-28T03:10:00+00:00');
});

it('records a failure for exit 1 when backup:run could not record its own outcome', function (): void {
    finishNightlyBackup(1);

    expect(DB::table('backup_runs')->pluck('state')->all())->toBe(['failed']);
    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();
    expect(SystemIssue::open()->where('kind', 'backup_failed')->exists())->toBeTrue();
});

it('adds no row when tonight\'s run recorded its outcome itself', function (int $exitCode, string $recorded): void {
    if ($recorded === 'failed') {
        event(new BackupHasFailed(new RuntimeException('disk unreachable'), 'backups', 'Heptapod'));
    } else {
        event(new BackupWasSuccessful('backups', 'Heptapod'));
    }

    finishNightlyBackup($exitCode);

    expect(DB::table('backup_runs')->pluck('state')->all())->toBe([$recorded]);
})->with([
    'handled failure (exit 1)' => [1, 'failed'],
    'killed after recording success (exit 137)' => [137, 'complete'],
]);

it('adds no row for the isolation refusal while a requested backup runs', function (): void {
    DB::table('backup_runs')->insert(['requested_by' => User::query()->value('id'), 'state' => 'running',
        'requested_at' => now(), 'started_at' => now()]);

    finishNightlyBackup(1);

    expect(DB::table('backup_runs')->pluck('state')->all())->toBe(['running']);
});
