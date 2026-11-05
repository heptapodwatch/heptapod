<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Actions\CreateRecurringMaintenancePeriod;
use Heptapod\Actions\DeleteMaintenancePeriod;
use Heptapod\Actions\DeleteRecurringMaintenancePeriod;
use Heptapod\Actions\GenerateMaintenancePeriods;
use Heptapod\Actions\StartMaintenance;
use Heptapod\Actions\StopMaintenance;
use Heptapod\Actions\UpdateMaintenancePeriod;
use Heptapod\Actions\UpdateRecurringMaintenancePeriod;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\NotificationSuppression;
use Heptapod\Contracts\SuppressionSubject;
use Heptapod\Enums\SiteStatus;
use Heptapod\Incidents\SiteState;
use Heptapod\Models\Check;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Models\RecurringMaintenancePeriod;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

it('starts and stops maintenance without changing check results or another site', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-03-28 12:00:00Z'));
    $check = Check::factory()->create();
    $other = Check::factory()->create();
    $period = app(StartMaintenance::class)($check->site);
    expect($period->ends_at->toIso8601String())->toBe('2026-03-28T13:00:00+00:00')
        ->and(app(SiteState::class)->derive($check->site))->toBe(SiteStatus::Maintenance)
        ->and(app(NotificationSuppression::class)->isSuppressed(new SuppressionSubject($other->id), CarbonImmutable::now())->suppressed)->toBeFalse();
    $this->travel(10)->seconds();
    expect(app(StopMaintenance::class)($check->site, 5))->toBe(1);
    $this->travel(5)->seconds();
    expect(app(NotificationSuppression::class)->isSuppressed(new SuppressionSubject($check->id), CarbonImmutable::now())->suppressed)->toBeFalse()
        ->and($check->fresh()->latest_run_result)->toBe(CheckResult::Pending);
    app(DeleteMaintenancePeriod::class)($period);
    expect(MaintenancePeriod::query()->count())->toBe(0);
});

it('rejects invalid maintenance durations', function (): void {
    $site = Check::factory()->create()->site;
    expect(fn () => app(StartMaintenance::class)($site, stopMaintenanceAfterSeconds: 4))->toThrow(ValidationException::class)
        ->and(fn () => app(StopMaintenance::class)($site, -1))->toThrow(ValidationException::class);
});

it('materialises RFC5545 DST windows with exact UTC boundaries and no duplicate generation', function (string $date, string $start, string $end, string $expectedStart, string $expectedEnd): void {
    $this->travelTo(CarbonImmutable::parse($date.' 00:00:00Z'));
    $recurring = RecurringMaintenancePeriod::factory()->create(['start_time' => $start, 'end_time' => $end]);
    expect(app(GenerateMaintenancePeriods::class)($recurring, CarbonImmutable::now()))->toBe(1);
    $period = MaintenancePeriod::query()->sole();
    expect($period->starts_at->format('Y-m-d H:i:s'))->toBe($expectedStart)
        ->and($period->ends_at->format('Y-m-d H:i:s'))->toBe($expectedEnd)
        ->and(app(GenerateMaintenancePeriods::class)($recurring, CarbonImmutable::now()))->toBe(0);
})->with([
    'spring gap start preserves duration' => ['2026-03-29', '02:30', '03:30', '2026-03-29 01:30:00', '2026-03-29 02:30:00'],
    'crossing spring gap' => ['2026-03-29', '01:30', '03:30', '2026-03-29 00:30:00', '2026-03-29 01:30:00'],
    'first fall ambiguity' => ['2026-10-25', '02:30', '03:30', '2026-10-25 00:30:00', '2026-10-25 02:30:00'],
    'crossing fall change' => ['2026-10-25', '01:30', '03:30', '2026-10-24 23:30:00', '2026-10-25 02:30:00'],
    'overnight' => ['2026-03-28', '23:00', '01:00', '2026-03-28 22:00:00', '2026-03-29 00:00:00'],
]);

it('generates upcoming weekly and monthly periods through the scheduled command', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-01-30 12:00:00Z'));
    $site = Check::factory()->create()->site;
    $monthly = app(CreateRecurringMaintenancePeriod::class)($site, ['recurrence_type' => 'monthly', 'day_of_month' => 31, 'start_time' => '10:00', 'end_time' => '11:00'], false);
    $weekly = app(CreateRecurringMaintenancePeriod::class)($site, ['recurrence_type' => 'weekly', 'days_of_week' => [0], 'start_time' => '23:00', 'end_time' => '01:00', 'timezone' => 'Europe/Berlin'], false);
    config(['heptapod.maintenance.horizon_days' => 60]);
    $this->artisan('heptapod:generate-maintenance')->assertSuccessful();
    expect(MaintenancePeriod::query()->where('recurring_maintenance_period_id', $monthly->id)->orderBy('starts_at')->pluck('starts_at')->map(fn (CarbonImmutable $date): string => $date->format('Y-m-d'))->all())->toBe(['2026-01-31', '2026-03-31']);
    expect(MaintenancePeriod::query()->where('recurring_maintenance_period_id', $weekly->id)->count())->toBe(9);
    $this->artisan('heptapod:generate-maintenance')->assertSuccessful();
    expect(MaintenancePeriod::query()->count())->toBe(11)->and($monthly->fresh()->timezone)->toBe($site->client->timezone);
});

it('edits an active end immediately but keeps its already-started start', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00Z'));
    $period = MaintenancePeriod::factory()->create(['starts_at' => now()->subHour(), 'ends_at' => now()->addHour()]);
    $updated = app(UpdateMaintenancePeriod::class)($period, 'Changed', CarbonImmutable::now()->addHour(), CarbonImmutable::now()->addMinutes(30));
    expect($updated->starts_at->format('H:i'))->toBe('11:00')->and($updated->ends_at->format('H:i'))->toBe('12:30');
});

it('regenerates and deletes only future recurring windows, keeping active periods and history', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00Z'));
    config(['heptapod.maintenance.horizon_days' => 1]);
    $recurring = RecurringMaintenancePeriod::factory()->create(['timezone' => 'UTC', 'start_time' => '11:00', 'end_time' => '13:00']);
    app(GenerateMaintenancePeriods::class)($recurring);
    $history = MaintenancePeriod::factory()->create(['site_id' => $recurring->site_id, 'recurring_maintenance_period_id' => $recurring->id, 'starts_at' => now()->subDay(), 'ends_at' => now()->subDay()->addHour()]);
    $active = MaintenancePeriod::activeForSite($recurring->site_id, CarbonImmutable::now())->sole();
    app(UpdateRecurringMaintenancePeriod::class)($recurring, ['start_time' => '10:00', 'end_time' => '14:00']);
    expect($active->fresh()->ends_at->format('H:i'))->toBe('13:00')
        ->and(MaintenancePeriod::query()->getQuery()->where('starts_at', '>', now())->sole()->starts_at)->toBe('2026-10-02 10:00:00+00');
    app(DeleteRecurringMaintenancePeriod::class)($recurring);
    expect(MaintenancePeriod::query()->count())->toBe(2)->and($active->fresh())->not->toBeNull()->and($history->fresh())->not->toBeNull();
    expect(Activity::query()->where('subject_type', $recurring->getMorphClass())->where('subject_id', $recurring->id)->orderBy('id')->pluck('event')->all())->toBe(['created', 'updated', 'deleted']);
});

it('rejects incomplete or invalid recurring schedules before creating windows', function (array $changes): void {
    $site = Check::factory()->create()->site;
    expect(fn () => app(CreateRecurringMaintenancePeriod::class)($site, array_replace(['recurrence_type' => 'daily', 'start_time' => '01:00', 'end_time' => '02:00', 'timezone' => 'Europe/Berlin'], $changes)))->toThrow(ValidationException::class);
    expect(RecurringMaintenancePeriod::query()->count())->toBe(0)->and(MaintenancePeriod::query()->count())->toBe(0);
})->with([
    'weekly requires weekdays' => [['recurrence_type' => 'weekly']],
    'monthly requires day' => [['recurrence_type' => 'monthly']],
    'invalid timezone' => [['timezone' => 'Not/AZone']],
]);

it('treats equal local times as a next-day window', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-01 00:00:00Z'));
    config(['heptapod.maintenance.horizon_days' => 0]);
    $check = Check::factory()->create();
    expect(fn () => app(CreateRecurringMaintenancePeriod::class)($check->site, ['recurrence_type' => 'daily', 'start_time' => '01:00', 'end_time' => '01:00', 'timezone' => 'UTC']))->not->toThrow(ValidationException::class);
    $period = MaintenancePeriod::query()->sole();
    expect($period->starts_at->toIso8601String())->toBe('2026-10-01T01:00:00+00:00')
        ->and($period->ends_at->toIso8601String())->toBe('2026-10-02T01:00:00+00:00');
    expect(app(NotificationSuppression::class)->isSuppressed(new SuppressionSubject($check->id), CarbonImmutable::parse('2026-10-02 00:59:59Z'))->suppressed)->toBeTrue()
        ->and(app(NotificationSuppression::class)->isSuppressed(new SuppressionSubject($check->id), CarbonImmutable::parse('2026-10-02 01:00:00Z'))->suppressed)->toBeFalse();
});

it('stops a just-started period immediately even when the clock has not advanced', function (): void {
    $check = Check::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00.500000Z'));
    app(StartMaintenance::class)($check->site);
    expect(app(NotificationSuppression::class)->isSuppressed(new SuppressionSubject($check->id), CarbonImmutable::now())->suppressed)->toBeTrue();
    app(StopMaintenance::class)($check->site);
    expect(app(NotificationSuppression::class)->isSuppressed(new SuppressionSubject($check->id), CarbonImmutable::now())->suppressed)->toBeFalse();
});
