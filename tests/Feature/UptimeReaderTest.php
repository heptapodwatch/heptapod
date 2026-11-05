<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Actions\DisableCheck;
use Heptapod\Actions\EnableCheck;
use Heptapod\Actions\PauseSite;
use Heptapod\Actions\ResumeSite;
use Heptapod\Contracts\RunResult;
use Heptapod\Enums\IncidentCause;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\Incident;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Models\Site;
use Heptapod\Queries\UptimeReader;
use Illuminate\Support\Facades\DB;

it('measures down time within monitored windows without double counting maintenance or overlapping pauses', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-03T12:00:00Z'));
    $start = now()->subHours(2);
    $site = Site::factory()->create(['created_at' => $start->subMinutes(30)]);
    $check = Check::factory()->for($site)->create(['created_at' => $start->addMinutes(20), 'first_result_at' => $start->addMinutes(20)]);
    Incident::factory()->for($check)->create(['first_failure_at' => $start->addMinutes(10), 'opened_at' => $start->addMinutes(10), 'resolved_at' => $start->addMinutes(90)]);
    Incident::factory()->for($check)->create(['first_failure_at' => $start->addMinutes(80), 'opened_at' => $start->addMinutes(80), 'resolved_at' => $start->addMinutes(100)]);
    MaintenancePeriod::factory()->create(['site_id' => $site->id, 'starts_at' => $start->addMinutes(30), 'ends_at' => $start->addMinutes(50)]);
    DB::table('unmonitored_periods')->insert(['site_id' => $site->id, 'started_at' => $start->addMinutes(40), 'ended_at' => $start->addMinutes(60)]);
    $reader = app(UptimeReader::class);
    // 100 monitored minutes after creation minus union [30,60] = 70; down [20,100] minus [30,60] = 50.
    expect(round($reader->percentage([$check->id], $start, $start->addHours(2)), 2))->toBe(28.57);
    expect($reader->percentage([$check->id], $start, $start->addMinutes(20)))->toBeNull();
    $series = $reader->series([$check->id], [[$start, $start->addHour()], [$start->addHour(), $start->addHours(2)]]);
    expect($series[0])->toBe(0)->and(round($series[1], 2))->toBe(33.33);
});

it('counts an open failed incident through the window end without treating warnings or errors as downtime', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-03T12:00:00Z'));
    $site = Site::factory()->create(['created_at' => now()->subMinutes(90)]);
    $check = Check::factory()->for($site)->create(['created_at' => now()->subMinutes(90), 'first_result_at' => now()->subMinutes(90)]);
    CheckRun::factory()->for($check)->create(['result' => RunResult::Warning, 'started_at' => now()->subMinutes(75), 'ended_at' => now()->subMinutes(74)]);
    Incident::factory()->for($check)->create(['cause' => IncidentCause::Errored, 'first_failure_at' => now()->subMinutes(60), 'opened_at' => now()->subMinutes(60), 'resolved_at' => now()->subMinutes(45)]);
    Incident::factory()->for($check)->create(['first_failure_at' => now()->subMinutes(30), 'opened_at' => now()->subMinutes(30)]);

    expect(round(app(UptimeReader::class)->percentage([$check->id], now()->subMinutes(90), now()), 2))->toBe(66.67);
});

it('batches ninety-day uptime across three hundred incident-heavy sites', function (): void {
    $this->travelTo(CarbonImmutable::now('UTC')->startOfMinute());
    $checks = [];
    $incidents = [];
    foreach (Site::factory()->count(300)->create(['created_at' => now()->subDays(100)]) as $site) {
        $check = Check::factory()->for($site)->create(['created_at' => now()->subDays(100), 'first_result_at' => now()->subDays(100)]);
        $checks[] = $check->id;
        for ($week = 0; $week < 12; $week++) {
            $start = now()->subDays(89 - $week * 7);
            $incidents[] = ['site_id' => $site->id, 'check_id' => $check->id, 'cause' => 'failed', 'opened_at' => $start,
                'first_failure_at' => $start, 'resolved_at' => $start->addMinutes(20), 'evidence' => '{}', 'created_at' => now(), 'updated_at' => now()];
        }
    }
    foreach (array_chunk($incidents, 300) as $chunk) {
        DB::table('incidents')->insert($chunk);
    }
    $bounds = [];
    for ($day = 90; $day > 0; $day--) {
        $bounds[] = [CarbonImmutable::now('UTC')->subDays($day), CarbonImmutable::now('UTC')->subDays($day - 1)];
    }

    $coarseBounds = [];
    for ($day = 90; $day > 0; $day -= 3) {
        $coarseBounds[] = [now()->subDays($day), now()->subDays($day - 3)];
    }
    $reader = app(UptimeReader::class);
    $measure = static function (array $ids, array $windows) use ($reader): array {
        $work = 0;
        $countWork = static function () use (&$work): void {
            $work++;
        };
        DB::enableQueryLog();
        DB::flushQueryLog();
        $series = $reader->series($ids, $windows, $countWork);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$series, $work, $queries];
    };
    [$coarse, $coarseWork, $coarseQueries] = $measure($checks, $coarseBounds);
    [$sample, $sampleWork, $sampleQueries] = $measure(array_slice($checks, 0, 100), $bounds);
    [$series, $fineWork, $fineQueries] = $measure($checks, $bounds);
    expect($coarse)->toHaveCount(30)
        ->and($sample)->toHaveCount(90)
        ->and($series)->toHaveCount(90)
        ->and($series[1])->toBeLessThan(100)->toBeGreaterThan(0)
        ->and($sampleQueries)->toBe($fineQueries)
        ->and($fineQueries)->toBe($coarseQueries)->toBeLessThanOrEqual(5)
        ->and($sampleWork)->toBeGreaterThan(0)
        ->and($fineWork)->toBeLessThan($sampleWork * 4)
        ->and($fineWork)->toBeLessThan($coarseWork * 1.4);
});

it('does not treat an open pause as a monitored gap or an open incident as downtime within it', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-03T12:00:00Z'));
    $start = now()->subHours(2);
    $site = Site::factory()->create(['created_at' => $start]);
    $check = Check::factory()->for($site)->create(['created_at' => $start, 'first_result_at' => $start]);
    DB::table('unmonitored_periods')->insert(['site_id' => $site->id, 'started_at' => $start->addMinutes(30)]);
    Incident::factory()->for($check)->create(['first_failure_at' => $start->addMinutes(50), 'opened_at' => $start->addMinutes(50)]);

    $reader = app(UptimeReader::class);
    expect($reader->percentage([$check->id], $start, now()))->toBe(100.0)
        ->and($reader->percentage([$check->id], $start->addMinutes(30), now()))->toBeNull()
        ->and($reader->series([$check->id], [[$start, $start->addMinutes(30)], [$start->addMinutes(30), now()]]))->toBe([100, null]);
});

it('backfills only currently paused sites and disabled uptime checks', function (): void {
    $migration = require database_path('migrations/2026_10_03_162000_create_unmonitored_periods.php');
    $site = Site::factory()->create(['paused_at' => now()->subHour()]);
    $disabled = Check::factory()->for($site)->create(['enabled' => false]);
    $enabled = Check::factory()->create();
    $migration->down();
    $migration->up();

    expect(DB::table('unmonitored_periods')->where('site_id', $site->id)->whereNull('check_id')->whereNull('ended_at')->count())->toBe(1)
        ->and(DB::table('unmonitored_periods')->where('check_id', $disabled->id)->whereNull('ended_at')->count())->toBe(1)
        ->and(DB::table('unmonitored_periods')->where('check_id', $enabled->id)->count())->toBe(0);
});

it('does not duplicate an open exclusion when disabling a stale check model', function (): void {
    $check = Check::factory()->create();
    $stale = Check::query()->findOrFail($check->id);

    app(DisableCheck::class)($check);
    app(DisableCheck::class)($stale);

    expect(DB::table('unmonitored_periods')->where('check_id', $check->id)->whereNull('ended_at')->count())->toBe(1);
});

it('records site and check exclusions through actions and resumes monitoring at the boundary', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-03T10:00:00Z'));
    $site = Site::factory()->create(['created_at' => now()->subHour()]);
    $check = Check::factory()->for($site)->create(['created_at' => now()->subHour(), 'first_result_at' => now()->subHour()]);
    app(PauseSite::class)($site);
    $this->travel(10)->minutes();
    app(DisableCheck::class)($check);
    $this->travel(10)->minutes();
    app(ResumeSite::class)($site);
    $this->travel(10)->minutes();
    app(EnableCheck::class)($check);
    $this->travel(10)->minutes();
    expect(app(UptimeReader::class)->percentage([$check->id], now()->subMinutes(40), now()))->toBe(100.0);
    expect(DB::table('unmonitored_periods')->where('site_id', $site->id)->whereNotNull('ended_at')->count())->toBe(2);
});
