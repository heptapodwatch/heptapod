<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Checks\History\RollupReader;
use Heptapod\Contracts\RunResult;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\Incident;
use Heptapod\Models\User;
use Heptapod\Settings\InstanceSettings;
use Heptapod\Testing\ApiSpec;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-03T12:00:00Z'));
    app()->singleton(ApiSpec::class);
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $this->withToken($owner->createToken('windows', ['*'])->plainTextToken);
});

it('aligns unaligned rollup windows outward and returns real bucket timestamps', function (string $period, string $bucket): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:30:00', 'UTC'));
    $check = Check::factory()->create(['created_at' => '2026-09-27 00:00:00+00', 'first_result_at' => '2026-09-27 00:00:00+00']);
    $check->site->update(['created_at' => '2026-09-27 00:00:00+00']);
    CheckRun::factory()->create(['check_id' => $check->id, 'started_at' => '2026-10-01 10:29:59+00', 'ended_at' => '2026-10-01 10:30:00+00', 'result' => RunResult::Succeeded, 'metrics' => ['dns_time_in_seconds' => 1, 'tcp_time_in_seconds' => 2, 'ssl_handshake_time_in_seconds' => 3, 'remote_server_processing_time_in_seconds' => 4, 'download_time_in_seconds' => 5, 'total_time_in_seconds' => 15]]);
    $this->artisan('heptapod:maintain-check-data', ['--recompute-range' => '2026-10-01T10:00:00Z,2026-10-01T11:00:00Z'])->assertSuccessful();
    $this->getJson('/api/monitors/'.$check->site_id.'/http-uptime-metrics?filter[start]=20261001101500&filter[end]=20261001104500&filter[group_by]='.$period)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.date', $bucket)->assertJsonPath('data.0.total_time_in_seconds', 15);
    if ($period === 'hour') {
        $this->getJson('/api/monitors/'.$check->site_id.'/uptime?filter[started_at]=20261001101500&filter[ended_at]=20261001104500')->assertOk()->assertJsonPath('0.datetime', '2026-10-01 10:00:00')->assertJsonPath('0.uptime_percentage', 100);
    }
})->with([['hour', '2026-10-01 10:00:00'], ['day', '2026-10-01 00:00:00'], ['week', '2026-09-28 00:00:00'], ['month', '2026-10-01 00:00:00']]);

it('measures each populated uptime bucket independently across gaps', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-03 15:00:00', 'UTC'));
    $check = Check::factory()->create(['created_at' => now()->subDay(), 'first_result_at' => now()->subDay()]);
    $check->site->update(['created_at' => now()->subDay()]);
    foreach (['2026-10-03 10:30:00+00', '2026-10-03 13:30:00+00'] as $ended) {
        CheckRun::factory()->for($check)->create(['started_at' => CarbonImmutable::parse($ended)->subSecond(), 'ended_at' => $ended, 'result' => RunResult::Succeeded]);
    }
    Incident::factory()->for($check)->create(['first_failure_at' => '2026-10-03 10:40:00+00', 'opened_at' => '2026-10-03 10:40:00+00', 'resolved_at' => '2026-10-03 10:50:00+00']);
    $this->artisan('heptapod:maintain-check-data', ['--recompute-range' => '2026-10-03T10:00:00Z,2026-10-03T14:00:00Z'])->assertSuccessful();

    $response = $this->getJson('/api/monitors/'.$check->site_id.'/uptime?filter[started_at]=20261003100000&filter[ended_at]=20261003140000')->assertOk()->assertJsonCount(2);
    expect(round($response->json('0.uptime_percentage'), 2))->toBe(83.33);
    $response->assertJsonPath('1.datetime', '2026-10-03 13:00:00')->assertJsonPath('1.uptime_percentage', 100);
});

it('clips future uptime requests at the current instant', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-03 12:50:00', 'UTC'));
    $check = Check::factory()->create(['created_at' => now()->subDay(), 'first_result_at' => now()->subDay()]);
    $check->site->update(['created_at' => now()->subDay()]);
    CheckRun::factory()->for($check)->create(['started_at' => now()->subMinutes(21), 'ended_at' => now()->subMinutes(20), 'result' => RunResult::Succeeded]);
    Incident::factory()->for($check)->create(['first_failure_at' => now()->subMinutes(10), 'opened_at' => now()->subMinutes(10)]);
    $this->artisan('heptapod:maintain-check-data', ['--recompute-range' => '2026-10-03T12:00:00Z,2026-10-03T13:00:00Z'])->assertSuccessful();

    $response = $this->getJson('/api/monitors/'.$check->site_id.'/uptime?filter[started_at]=20261003120000&filter[ended_at]=20261003160000')->assertOk()->assertJsonCount(1);
    expect(round($response->json('0.uptime_percentage'), 2))->toBe(80.0);
});

it('defaults minute metrics to exactly the previous 24 hours from one captured timestamp', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:30:00.123456', 'UTC'));
    $check = Check::factory()->create();
    $timings = ['dns_time_in_seconds' => 1, 'tcp_time_in_seconds' => 2, 'ssl_handshake_time_in_seconds' => 3, 'remote_server_processing_time_in_seconds' => 4, 'download_time_in_seconds' => 5, 'total_time_in_seconds' => 15];
    foreach (['2026-09-30 12:29:59+00', '2026-09-30 12:30:01+00', '2026-10-01 12:29:59+00'] as $ended) {
        CheckRun::factory()->create(['check_id' => $check->id, 'started_at' => CarbonImmutable::parse($ended)->subSecond(), 'ended_at' => $ended, 'metrics' => $timings]);
    }
    $this->getJson('/api/monitors/'.$check->site_id.'/http-uptime-metrics?filter[group_by]=minute')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.date', '2026-09-30 12:30:00');
});

it('preserves Berlin offsets when selecting complete rollup buckets through HTTP', function (string $period, string $outlier, string $bucket): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-02 12:00:00', 'UTC'));
    $instance = app(InstanceSettings::class);
    $instance->timezone = 'Europe/Berlin';
    $instance->save();
    $check = Check::factory()->create(['created_at' => '2026-09-27 00:00:00+00', 'first_result_at' => '2026-09-27 00:00:00+00']);
    $check->site->update(['created_at' => '2026-09-27 00:00:00+00']);
    foreach ([['2026-10-01 10:30:00+00', RunResult::Succeeded, 15], [$outlier, RunResult::Failed, 999]] as [$ended, $result, $timing]) {
        CheckRun::factory()->create(['check_id' => $check->id, 'started_at' => CarbonImmutable::parse($ended)->subSecond(), 'ended_at' => $ended, 'result' => $result, 'metrics' => array_fill_keys(['dns_time_in_seconds', 'tcp_time_in_seconds', 'ssl_handshake_time_in_seconds', 'remote_server_processing_time_in_seconds', 'download_time_in_seconds', 'total_time_in_seconds'], $timing)]);
    }
    $this->artisan('heptapod:maintain-check-data', ['--recompute-range' => '2026-09-30T22:00:00Z,2026-10-02T00:00:00Z'])->assertSuccessful();
    $this->getJson('/api/monitors/'.$check->site_id.'/http-uptime-metrics?filter[start]=20261001101500&filter[end]=20261001104500&filter[group_by]='.$period)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.total_time_in_seconds', 15)->assertJsonPath('data.0.date', $bucket);
    $this->getJson('/api/monitors/'.$check->site_id.'/uptime?filter[started_at]=20261001101500&filter[ended_at]=20261001104500&split='.$period)->assertOk()->assertJsonCount(1)->assertJsonPath('0.uptime_percentage', 100)->assertJsonPath('0.datetime', $bucket);
})->with([
    ['hour', '2026-10-01 12:30:00+00', '2026-10-01 10:00:00'],
    ['day', '2026-10-01 23:30:00+00', '2026-09-30 22:00:00'],
]);

it('uses non-overlapping UTC hour boundaries for Kolkata hours and local days', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-03 12:00:00', 'UTC'));
    $instance = app(InstanceSettings::class);
    $instance->timezone = 'Asia/Kolkata';
    $instance->save();
    $check = Check::factory()->create(['created_at' => '2026-09-27 00:00:00+00', 'first_result_at' => '2026-09-27 00:00:00+00']);
    $check->site->update(['created_at' => '2026-09-27 00:00:00+00']);
    foreach ([['2026-10-01 17:30:00+00', 1], ['2026-10-01 18:30:00+00', 2], ['2026-10-01 19:30:00+00', 4], ['2026-10-02 18:30:00+00', 999]] as [$ended, $timing]) {
        CheckRun::factory()->create(['check_id' => $check->id, 'started_at' => CarbonImmutable::parse($ended)->subSecond(), 'ended_at' => $ended, 'result' => RunResult::Succeeded, 'metrics' => array_fill_keys(['dns_time_in_seconds', 'tcp_time_in_seconds', 'ssl_handshake_time_in_seconds', 'remote_server_processing_time_in_seconds', 'download_time_in_seconds', 'total_time_in_seconds'], $timing)]);
    }
    $this->artisan('heptapod:maintain-check-data', ['--recompute-range' => '2026-09-30T18:00:00Z,2026-10-02T19:00:00Z'])->assertSuccessful();
    $this->getJson('/api/monitors/'.$check->site_id.'/http-uptime-metrics?filter[start]=20261001181500&filter[end]=20261001184500&filter[group_by]=hour')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.total_time_in_seconds', 2)->assertJsonPath('data.0.date', '2026-10-01 18:00:00');
    $this->getJson('/api/monitors/'.$check->site_id.'/http-uptime-metrics?filter[start]=20260930180000&filter[end]=20261002180000&filter[group_by]=day')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.date', '2026-09-30 18:00:00')->assertJsonPath('data.0.total_time_in_seconds', 1)->assertJsonPath('data.1.date', '2026-10-01 18:00:00')->assertJsonPath('data.1.total_time_in_seconds', 3);
    $this->getJson('/api/monitors/'.$check->site_id.'/uptime?filter[started_at]=20261001180000&filter[ended_at]=20261002180000&split=day')->assertOk()->assertJsonCount(1)->assertJsonPath('0.datetime', '2026-10-01 18:00:00');
    $buckets = app(RollupReader::class)->series($check->id, 'day', CarbonImmutable::parse('2026-09-30 18:00:00', 'UTC'), CarbonImmutable::parse('2026-10-02 18:00:00', 'UTC'), 'Asia/Kolkata');
    expect(array_column($buckets, 'completed_count'))->toBe([1, 2]);
    expect(array_sum(array_column($buckets, 'completed_count')))->toBe(3);
});

it('keeps Berlin DST days at their real UTC duration including both repeated autumn hours', function (string $start, string $end, int $hours): void {
    $from = CarbonImmutable::parse($start, 'UTC');
    $until = CarbonImmutable::parse($end, 'UTC');
    $this->travelTo($until->addDay());
    $instance = app(InstanceSettings::class);
    $instance->timezone = 'Europe/Berlin';
    $instance->save();
    $this->artisan('heptapod:maintain-check-data')->assertSuccessful();
    $check = Check::factory()->create(['created_at' => $from->subDay(), 'first_result_at' => $from->subDay()]);
    $check->site->update(['created_at' => $from->subDay()]);
    for ($hour = -1; $hour <= $hours; $hour++) {
        $ended = $from->addHours($hour)->addMinutes(30);
        $inside = $hour >= 0 && $hour < $hours;
        CheckRun::factory()->create(['check_id' => $check->id, 'started_at' => $ended->subSecond(), 'ended_at' => $ended, 'result' => $inside ? RunResult::Succeeded : RunResult::Failed, 'metrics' => array_fill_keys(['dns_time_in_seconds', 'tcp_time_in_seconds', 'ssl_handshake_time_in_seconds', 'remote_server_processing_time_in_seconds', 'download_time_in_seconds', 'total_time_in_seconds'], $inside ? 1 : 999)]);
    }
    $this->artisan('heptapod:maintain-check-data', ['--recompute-range' => $from->subHour()->toIso8601String().','.$until->addHour()->toIso8601String()])->assertSuccessful();
    $query = 'filter[started_at]='.$from->format('YmdHis').'&filter[ended_at]='.$until->format('YmdHis');
    $this->getJson('/api/monitors/'.$check->site_id.'/uptime?'.$query.'&split=hour')->assertOk()->assertJsonCount($hours);
    $startInsideDay = $from->addMinutes(15);
    $endInsideDay = $from->addMinutes(45);
    $this->getJson('/api/monitors/'.$check->site_id.'/uptime?filter[started_at]='.$startInsideDay->format('YmdHis').'&filter[ended_at]='.$endInsideDay->format('YmdHis').'&split=day')->assertOk()->assertJsonCount(1)->assertJsonPath('0.datetime', $from->format('Y-m-d H:i:s'))->assertJsonPath('0.uptime_percentage', 100);
    $this->getJson('/api/monitors/'.$check->site_id.'/http-uptime-metrics?filter[start]='.$startInsideDay->format('YmdHis').'&filter[end]='.$endInsideDay->format('YmdHis').'&filter[group_by]=day')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.total_time_in_seconds', 1)->assertJsonPath('data.0.date', $from->format('Y-m-d H:i:s'));
    $buckets = app(RollupReader::class)->series($check->id, 'day', $startInsideDay, $endInsideDay, 'Europe/Berlin');
    expect(array_column($buckets, 'completed_count'))->toBe([$hours]);
})->with([
    ['2026-03-28 23:00:00', '2026-03-29 22:00:00', 23],
    ['2026-10-24 22:00:00', '2026-10-25 23:00:00', 25],
]);
