<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Addons\Channels\TemplateMail;
use Heptapod\Contracts\DiskSpaceReader;
use Heptapod\Enums\HealthStatus;
use Heptapod\Models\SystemIssue;
use Heptapod\Models\User;
use Heptapod\SystemHealth\DiskReadException;
use Heptapod\SystemHealth\DiskReadFailure;
use Heptapod\SystemHealth\DiskSpaceIssues;
use Heptapod\SystemHealth\SystemIssueData;
use Heptapod\Testing\FakeDiskSpaceReader;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/../Support/SystemIssueRecipients.php';

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-20T12:00:00Z'));
    config(['mail.default' => 'smtp', 'backup.backup.destination.disks' => ['backups'],
        'filesystems.disks.backups.root' => '/fixture/local-backups',
        'backup.backup.temporary_directory' => storage_path('app'),
        'heptapod.system_issues.postgres_data_path' => '/fixture/postgres']);
});

it('tracks each filesystem and updates severity without duplicate opening mail before independent recovery', function (): void {
    Mail::fake();
    $owner = systemIssueRecipient('Owner', attributes: ['locale' => 'en']);
    $admin = systemIssueRecipient('Admin', true, ['locale' => 'de']);
    $excluded = systemIssueRecipient('Admin');
    $reader = new FakeDiskSpaceReader;
    $this->app->instance(DiskSpaceReader::class, $reader);
    foreach (app(DiskSpaceIssues::class)->paths() as $path) {
        $reader->samples[$path] = ['free' => 30, 'total' => 100];
    }
    $reader->samples['/backups'] = ['free' => 19, 'total' => 100];
    $reader->samples['/fixture/local-backups'] = ['free' => 9, 'total' => 100];
    $reader->samples['/fixture/postgres'] = new RuntimeException('Permission denied');

    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();
    $issues = SystemIssue::query()->where('kind', 'disk_space_low')->get()->keyBy('scope');
    expect($issues)->toHaveCount(3);
    expect($issues['/backups']->severity())->toBe(HealthStatus::Warn);
    expect($issues['/fixture/local-backups']->severity())->toBe(HealthStatus::Down);
    // A foreign read failure keeps its native text in the log only; details carry the typed reason for the translated UI and mail line.
    expect($issues['/fixture/postgres']->details)->toBe(['path' => '/fixture/postgres', 'reason' => 'read_failed', 'severity' => 'unreadable']);
    Mail::assertSent(TemplateMail::class, fn (TemplateMail $mail): bool => $mail->hasTo($admin->email)
        && str_contains($mail->render(), 'Der freie Speicherplatz von /fixture/postgres konnte nicht gelesen werden.') && ! str_contains($mail->render(), 'Permission denied'));
    foreach ($issues as $issue) {
        expect(systemIssueDeliveries($issue->id, 'open')->where('deliveries.state', 'sent')->count())->toBe(2);
    }
    Mail::assertSent(TemplateMail::class, fn (TemplateMail $mail): bool => $mail->hasTo($owner->email) && str_contains($mail->model->subject, 'Disk space is low'));
    Mail::assertSent(TemplateMail::class, fn (TemplateMail $mail): bool => $mail->hasTo($admin->email) && str_contains($mail->model->subject, 'Wenig Speicherplatz') && str_contains($mail->render(), '9,00'));
    Mail::assertNotSent(TemplateMail::class, fn (TemplateMail $mail): bool => $mail->hasTo($excluded->email));
    Mail::assertNothingQueued();

    $reader->samples['/backups'] = ['free' => 9, 'total' => 100];
    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();
    expect($issues['/backups']->refresh()->severity())->toBe(HealthStatus::Down);
    expect(SystemIssue::query()->where('kind', 'disk_space_low')->count())->toBe(3);
    expect((int) systemIssueDeliveries($issues['/backups']->id, 'open')->sum('deliveries.attempts'))->toBe(2);
    $presented = collect(SystemIssueData::open())->first(fn (SystemIssueData $issue): bool => $issue->scope === '/backups');
    expect($presented?->severity)->toBe(HealthStatus::Down);

    $reader->samples['/backups'] = new RuntimeException('Mount unavailable');
    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();
    expect($issues['/backups']->refresh()->details['severity'])->toBe('unreadable');
    expect($issues['/backups']->resolved_at)->toBeNull();
    $reader->samples['/backups'] = ['free' => 15, 'total' => 100];
    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();
    expect($issues['/backups']->refresh()->severity())->toBe(HealthStatus::Warn);

    $reader->samples['/backups'] = ['free' => 25, 'total' => 100];
    $reader->samples['/fixture/postgres'] = ['free' => 25, 'total' => 100];
    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();
    expect($issues['/backups']->refresh()->resolved_at?->toIso8601String())->toBe('2026-10-20T12:00:00+00:00');
    expect($issues['/fixture/postgres']->refresh()->resolved_at)->not->toBeNull();
    expect($issues['/fixture/local-backups']->refresh()->resolved_at)->toBeNull();
    expect(systemIssueDeliveries($issues['/backups']->id, 'recovery')->where('deliveries.state', 'sent')->count())->toBe(2);
});

it('monitors application storage and the backup workspace even when backups use remote storage', function (): void {
    Mail::fake();
    config(['backup.backup.destination.disks' => ['s3'], 'backup.backup.temporary_directory' => storage_path('framework')]);
    $reader = new FakeDiskSpaceReader;
    $this->app->instance(DiskSpaceReader::class, $reader);
    foreach (app(DiskSpaceIssues::class)->paths() as $path) {
        $reader->samples[$path] = ['free' => 30, 'total' => 100];
    }
    $reader->samples[storage_path()] = ['free' => 9, 'total' => 100];
    $reader->samples[storage_path('framework')] = ['free' => 19, 'total' => 100];

    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();

    $this->assertDatabaseHas('system_issues', ['kind' => 'disk_space_low', 'scope' => storage_path(), 'resolved_at' => null]);
    $this->assertDatabaseHas('system_issues', ['kind' => 'disk_space_low', 'scope' => storage_path('framework'), 'resolved_at' => null]);
    expect(SystemIssue::open()->where('kind', 'disk_space_low')->count())->toBe(2);
});

it('recovers every removed filesystem scope without resolving paths that remain low or repeating recovery mail', function (): void {
    Mail::fake();
    $owner = systemIssueRecipient('Owner');
    $reader = new FakeDiskSpaceReader;
    $this->app->instance(DiskSpaceReader::class, $reader);
    foreach (app(DiskSpaceIssues::class)->paths() as $path) {
        $reader->samples[$path] = ['free' => 30, 'total' => 100];
    }
    foreach (['/fixture/postgres', '/fixture/local-backups', '/backups'] as $path) {
        $reader->samples[$path] = ['free' => 9, 'total' => 100];
    }
    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();
    $removed = SystemIssue::open()->where('kind', 'disk_space_low')->whereIn('scope', ['/fixture/postgres', '/fixture/local-backups'])->get();
    expect($removed)->toHaveCount(2);
    config(['heptapod.system_issues.postgres_data_path' => '/fixture/new-postgres', 'filesystems.disks.backups.root' => '/fixture/new-backups']);
    $reader->samples['/fixture/new-postgres'] = $reader->samples['/fixture/new-backups'] = ['free' => 30, 'total' => 100];
    unset($reader->samples['/fixture/postgres'], $reader->samples['/fixture/local-backups']);

    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();
    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();

    foreach ($removed as $issue) {
        expect($issue->refresh()->resolved_at?->toIso8601String())->toBe('2026-10-20T12:00:00+00:00');
    }
    $this->assertDatabaseHas('system_issues', ['kind' => 'disk_space_low', 'scope' => '/backups', 'resolved_at' => null]);
    foreach ($removed as $issue) {
        expect(systemIssueDeliveries($issue->id, 'recovery')->where('deliveries.state', 'sent')->count())->toBe(1);
        expect((int) systemIssueDeliveries($issue->id, 'recovery')->sum('deliveries.attempts'))->toBe(1);
    }
    Mail::assertSent(TemplateMail::class, fn (TemplateMail $mail): bool => $mail->hasTo($owner->email) && str_contains($mail->model->subject, '[System recovered]') && str_contains($mail->render(), '/fixture/postgres'));
});

/** @return array<string, mixed> */
function diskSpaceBackupConfiguration(string $name, string $root): array
{
    $previous = [];
    foreach (['APP_NAME' => $name, 'BACKUP_PATH' => $root] as $key => $value) {
        $previous[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
        $_ENV[$key] = $_SERVER[$key] = $value;
        putenv($key.'='.$value);
    }
    try {
        return require config_path('backup.php');
    } finally {
        foreach ($previous as $key => [$env, $server, $process]) {
            if ($env === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $env;
            }
            if ($server === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $server;
            }
            putenv($process === false ? $key : $key.'='.$process);
        }
    }
}

it('rejects empty names and daily directories that overlap lifecycle archives when loading backup configuration', function (string $name, string $root, string $error): void {
    expect(fn (): array => diskSpaceBackupConfiguration($name, $root))->toThrow(InvalidArgumentException::class, $error);
})->with([
    'reserved name' => ['upgrade-lifecycle', '/backups', 'reserved backup name upgrade-lifecycle'],
    'case alias' => ['Upgrade-Lifecycle', '/backups', 'reserved backup name upgrade-lifecycle'],
    'space alias' => ['upgrade lifecycle', '/backups', 'reserved backup name upgrade-lifecycle'],
    'name dot segments' => ['nested/../upgrade-lifecycle', '/backups', 'reserved backup name upgrade-lifecycle'],
    'empty name' => ['', '/backups', 'non-empty backup name'],
    'slash name' => ['/', '/backups', 'non-empty backup name'],
    'dot name' => ['.', '/backups', 'non-empty backup name'],
    'cancelled name' => ['nested/..', '/backups', 'non-empty backup name'],
    'lifecycle root override' => ['Heptapod', '/backups/upgrade-lifecycle', 'overlaps reserved /backups/upgrade-lifecycle'],
    'exact lifecycle directory' => ['backups/upgrade-lifecycle', '/', 'overlaps reserved /backups/upgrade-lifecycle'],
    'parent daily directory' => ['backups', '/', 'overlaps reserved /backups/upgrade-lifecycle'],
    'root dot segments' => ['Heptapod', '/backups/scratch/../upgrade-lifecycle', 'overlaps reserved /backups/upgrade-lifecycle'],
    'lifecycle subdirectory' => ['Heptapod', '/backups/upgrade-lifecycle/nested', 'overlaps reserved /backups/upgrade-lifecycle'],
    'repeated separators' => ['Heptapod', '/backups//upgrade-lifecycle/', 'overlaps reserved /backups/upgrade-lifecycle'],
]);

it('allows daily backup directories outside the lifecycle namespace including similar sibling prefixes', function (string $root): void {
    expect(diskSpaceBackupConfiguration('Heptapod', $root)['backup']['name'])->toBe('Heptapod');
})->with(['/backups', '/backups/upgrade-lifecycle-archive', '/other/backups']);

it('uses strict free-space thresholds and keeps healthy filesystems out of system issues', function (int $free, ?string $severity): void {
    Mail::fake();
    $reader = new FakeDiskSpaceReader;
    $this->app->instance(DiskSpaceReader::class, $reader);
    foreach (app(DiskSpaceIssues::class)->paths() as $path) {
        $reader->samples[$path] = ['free' => 30000, 'total' => 100000];
    }
    $reader->samples['/backups'] = ['free' => $free, 'total' => 100000];

    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();

    $issue = SystemIssue::open()->where('kind', 'disk_space_low')->where('scope', '/backups')->first();
    expect($issue?->details['severity'])->toBe($severity);
    expect(SystemIssue::open()->where('kind', 'disk_space_low')->count())->toBe($severity === null ? 0 : 1);
})->with([
    '20 percent is healthy' => [20000, null],
    'just below 20 warns' => [19999, 'warning'],
    '10 percent warns' => [10000, 'warning'],
    'just below 10 is critical' => [9999, 'critical'],
    'full filesystem is critical' => [0, 'critical'],
]);

it('names an unreadable filesystem on System health with a translated reason and never the native message', function (string $locale, string $reason): void {
    $owner = User::factory()->owner()->create(['locale' => $locale]);
    $reader = new FakeDiskSpaceReader;
    $this->app->instance(DiskSpaceReader::class, $reader);
    foreach (app(DiskSpaceIssues::class)->paths() as $path) {
        $reader->samples[$path] = ['free' => 30, 'total' => 100];
    }
    $reader->samples['/backups'] = new DiskReadException(DiskReadFailure::Missing, '/backups');
    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();

    $issue = collect($this->actingAs($owner)->get('/settings/system')->assertOk()->viewData('page')['props']['systemPage']['health']['systemIssues'])
        ->firstWhere('scope', '/backups');
    expect($issue['details'])->toBe(['path' => '/backups', 'reason' => 'missing', 'severity' => 'unreadable'])
        ->and(__('disk.read_failure.'.$issue['details']['reason'], ['path' => $issue['details']['path']], $locale))->toBe($reason);
})->with([
    'EN' => ['en', 'The folder /backups does not exist or cannot be read.'],
    'DE' => ['de', 'Der Ordner /backups existiert nicht oder ist nicht lesbar.'],
]);
