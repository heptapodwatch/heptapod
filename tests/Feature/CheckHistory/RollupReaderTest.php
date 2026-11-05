<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Checks\History\RollupReader;
use Heptapod\Contracts\RunResult;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\CheckRunRollupDaily;
use Heptapod\Models\CheckRunRollupHourly;
use Illuminate\Support\Facades\DB;

it('shows warnings as online errors as completed and missing buckets as no data with sample weighted daily metrics', function (): void {
    $day = CarbonImmutable::now('UTC')->startOfDay();
    $this->travelTo($day->addHours(3));
    $check = Check::factory()->create();
    $other = Check::factory()->create();
    foreach ([[$check, RunResult::Succeeded, 0, 1], [$check, RunResult::Warning, 1, 3], [$check, RunResult::Failed, 2, 5], [$check, RunResult::Errored, 3, null], [$check, RunResult::Failed, 120, 100], [$other, RunResult::Failed, 0, 999]] as [$owner, $result, $minute, $metric]) {
        $ended = $day->addMinutes($minute);
        CheckRun::factory()->create(['check_id' => $owner->id, 'result' => $result, 'started_at' => $ended->subSecond(), 'ended_at' => $ended,
            'metrics' => $metric === null ? null : ['total_time_in_seconds' => $metric]]);
    }
    $this->artisan('heptapod:maintain-check-data')->assertSuccessful();
    $reader = app(RollupReader::class);
    $hours = $reader->read($check->id, 'hourly', $day->setTimezone('Europe/Berlin'), $day->addHours(3));
    expect($hours)->toHaveCount(3)
        ->and($hours[0]['bucket_start']->equalTo($day))->toBeTrue()
        ->and($hours[0]['completed_count'])->toBe(4)
        ->and($hours[0]['uptime_percentage'])->toBe(50.0)
        ->and($hours[0]['metric_averages'])->toBe(['total_time_in_seconds' => 3.0])
        ->and($hours[1]['completed_count'])->toBe(0)
        ->and($hours[1]['uptime_percentage'])->toBeNull()
        ->and($hours[1]['metric_sums'])->toBe([])
        ->and($hours[1]['metric_averages'])->toBe([])
        ->and($hours[1]['metric_p95'])->toBe([])
        ->and($hours[2]['uptime_percentage'])->toBe(0.0);
    $daily = $reader->read($check->id, 'daily', $day, $day->addDay())[0];
    expect($daily['completed_count'])->toBe(5)
        ->and($daily['uptime_percentage'])->toBe(40.0)
        ->and($daily['metric_averages']['total_time_in_seconds'])->toBe(27.25);
    expect($daily['metric_p95']['total_time_in_seconds'])->toEqualWithDelta(85.75, 0.00001);
    expect($reader->read($check->id, 'hourly', $day, $day))->toBe([]);
});

it('reads metric-free rollups across UTC midnight as JSON objects', function (): void {
    $now = CarbonImmutable::parse('2026-10-04T00:10:00Z');
    $this->travelTo($now);
    $check = Check::factory()->create();
    $day = $now->subDay()->startOfDay();
    CheckRunRollupHourly::factory()->for($check)->create(['bucket_start' => $now->subHour()->startOfHour()]);
    CheckRunRollupDaily::factory()->for($check)->create(['bucket_start' => $day]);

    $reader = app(RollupReader::class);
    $series = $reader->series($check->id, 'day', $day, $now->startOfDay());
    expect($series)->toHaveCount(1)
        ->and($series[0]['completed_count'])->toBe(1)
        ->and($series[0]['metric_sums'])->toBe([])
        ->and($reader->read($check->id, 'daily', $day, $day->addDay())[0]['metric_p95'])->toBe([]);
    foreach (['hourly', 'daily'] as $period) {
        $types = DB::selectOne("SELECT jsonb_typeof(metric_sums) AS sums, jsonb_typeof(metric_counts) AS counts, jsonb_typeof(metric_p95) AS p95 FROM check_run_rollups_{$period} WHERE check_id = ?", [$check->id]);
        expect((array) $types)->toBe(['sums' => 'object', 'counts' => 'object', 'p95' => 'object']);
    }
});

it('rejects unsupported periods reversed unaligned and excessive windows', function (string $period, int $fromSeconds, int $untilSeconds): void {
    $day = CarbonImmutable::now('UTC')->startOfDay();
    expect(fn () => app(RollupReader::class)->read(1, $period, $day->addSeconds($fromSeconds), $day->addSeconds($untilSeconds)))
        ->toThrow(InvalidArgumentException::class);
})->with([['weekly', 0, 3600], ['hourly', 3600, 0], ['hourly', 1, 3600], ['daily', 0, 3600], ['hourly', 0, 3600 * 10001]]);
