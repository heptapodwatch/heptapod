<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Checks\History\CheckDataMaintenance;
use Heptapod\Contracts\RunResult;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\CheckRunRollupDaily;
use Heptapod\Models\CheckRunRollupHourly;
use Heptapod\Models\Incident;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('automatically finalises early-month observations across several months and drops raw data at each retention boundary', function (): void {
    $start = CarbonImmutable::parse('2026-02-01T00:00:00Z');
    $end = $start->addMonth();
    $ended = $start->addDays(9)->addHour();
    $this->travelTo($ended);
    $this->artisan('heptapod:maintain-check-data')->assertSuccessful();
    $run = CheckRun::factory()->create(['started_at' => $ended->subSecond(), 'ended_at' => $ended,
        'result' => RunResult::Failed, 'metrics' => ['total_time_in_seconds' => 2], 'evidence' => ['status_code' => 503]]);
    $incident = Incident::factory()->create(['check_id' => $run->check_id, 'evidence' => $run->evidence]);
    $this->artisan('heptapod:maintain-check-data')->assertSuccessful();
    $hour = CheckRunRollupHourly::query()->where('check_id', $run->check_id)->sole();
    $day = CheckRunRollupDaily::query()->where('check_id', $run->check_id)->sole();
    expect($hour->computed_at->equalTo($ended))->toBeTrue();

    $this->travelTo($end->addMinutes(10)->subMicrosecond());
    $this->artisan('heptapod:maintain-check-data')->assertSuccessful();
    expect($hour->fresh()->computed_at->equalTo($ended))->toBeTrue();
    // January and February are both closed; with 30 days of retention the rotation proves each of them in any two consecutive hourly runs.
    foreach ([$end->addMinutes(10), $end->addHour()] as $at) {
        $this->travelTo($at);
        $this->artisan('heptapod:maintain-check-data')->assertSuccessful();
    }
    $finalisedAt = $hour->fresh()->computed_at;
    expect($finalisedAt->greaterThanOrEqualTo($end->addMinutes(10)))->toBeTrue();
    $finalised = $day->fresh()->getAttributes();
    $runs = [$run];
    for ($at = $end->addHour(); $at->lessThanOrEqualTo(CarbonImmutable::parse('2026-05-31T00:00:00Z')); $at = $at->addHour()) {
        $this->travelTo($at);
        if ($at->day === 10 && $at->hour === 1) {
            $runs[] = CheckRun::factory()->create(['check_id' => $run->check_id, 'started_at' => $at->subSecond(), 'ended_at' => $at,
                'result' => RunResult::Failed, 'metrics' => ['total_time_in_seconds' => 2]]);
        }
        $expiring = array_filter($runs, fn (CheckRun $observation): bool => $observation->ended_at->startOfMonth()->addMonth()->addDays(30)->equalTo($at));
        if ($expiring !== []) {
            $this->travelTo($at->subMicrosecond());
            $this->artisan('heptapod:maintain-check-data')->assertSuccessful();
            foreach ($expiring as $observation) {
                expect(CheckRun::query()->forRun($observation->reference())->exists())->toBeTrue();
            }
            $this->travelTo($at);
        }
        $this->artisan('heptapod:maintain-check-data')->assertSuccessful();
        foreach ($expiring as $observation) {
            $partition = 'check_runs_'.$observation->ended_at->format('Y_m');
            expect(DB::selectOne('SELECT to_regclass(?) AS name', [$partition])->name)->toBeNull()
                ->and(CheckRun::query()->forRun($observation->reference())->exists())->toBeFalse();
            $hourly = CheckRunRollupHourly::query()->where('check_id', $run->check_id)->where('bucket_start', $observation->ended_at->startOfHour())->sole();
            $daily = CheckRunRollupDaily::query()->where('check_id', $run->check_id)->where('bucket_start', $observation->ended_at->startOfDay())->sole();
            expect($hourly->failed_count)->toBe(1)->and($daily->failed_count)->toBe(1)
                ->and($hourly->metric_counts)->toBe(['total_time_in_seconds' => 1])
                ->and($daily->metric_sums)->toBe(['total_time_in_seconds' => 2])
                ->and($incident->fresh()->evidence)->toBe(['status_code' => 503]);
        }
    }
    expect($hour->fresh()->computed_at->equalTo($finalisedAt))->toBeTrue()
        ->and($day->fresh()->getAttributes())->toBe($finalised)
        ->and($incident->fresh()->evidence)->toBe(['status_code' => 503])
        ->and(CheckRun::query()->where('check_id', $run->check_id)->sole()->ended_at->toIso8601String())->toBe('2026-05-10T01:00:00+00:00');
});

it('writes JSON objects for metric-free hourly and daily rollups', function (): void {
    $now = CarbonImmutable::parse('2026-10-04T00:10:00Z');
    $this->travelTo($now);
    $ended = $now->subHour();
    $run = CheckRun::factory()->create(['started_at' => $ended->subSecond(), 'ended_at' => $ended, 'result' => RunResult::Errored, 'metrics' => null]);
    $this->artisan('heptapod:maintain-check-data')->assertSuccessful();

    foreach (['hourly', 'daily'] as $period) {
        $types = DB::selectOne("SELECT jsonb_typeof(metric_sums) AS sums, jsonb_typeof(metric_counts) AS counts, jsonb_typeof(metric_p95) AS p95 FROM check_run_rollups_{$period} WHERE check_id = ?", [$run->check_id]);
        expect((array) $types)->toBe(['sums' => 'object', 'counts' => 'object', 'p95' => 'object']);
    }
});

it('recomputes exact hourly and daily counts metrics and percentiles idempotently including late arrivals', function (): void {
    $day = CarbonImmutable::now('UTC')->startOfDay();
    $this->travelTo($day->addHours(3));
    $check = Check::factory()->create();
    foreach ([
        [RunResult::Succeeded, 0, ['total_time_in_seconds' => 1, 'curl' => ['total_time' => 1], 'status' => 'ok']],
        [RunResult::Warning, 1, ['total_time_in_seconds' => 3, 'optional' => null]],
        [RunResult::Failed, 2, ['total_time_in_seconds' => 5]],
        [RunResult::Errored, 3, null],
        [RunResult::Succeeded, 60, ['total_time_in_seconds' => 100]],
    ] as [$result, $minute, $metrics]) {
        $ended = $day->addMinutes($minute);
        CheckRun::factory()->create(['check_id' => $check->id, 'result' => $result, 'started_at' => $ended->subSecond(), 'ended_at' => $ended, 'metrics' => $metrics]);
    }
    $this->artisan('heptapod:maintain-check-data')->assertSuccessful();
    $hour = CheckRunRollupHourly::query()->where('check_id', $check->id)->orderBy('bucket_start')->firstOrFail();
    $daily = CheckRunRollupDaily::query()->where('check_id', $check->id)->sole();
    expect([$hour->succeeded_count, $hour->warning_count, $hour->failed_count, $hour->errored_count])->toBe([1, 1, 1, 1]);
    expect($hour->metric_sums)->toBe(['curl.total_time' => 1, 'total_time_in_seconds' => 9])
        ->and($hour->metric_counts)->toBe(['curl.total_time' => 1, 'total_time_in_seconds' => 3]);
    expect($hour->metric_p95['total_time_in_seconds'])->toEqualWithDelta(4.8, 0.00001);
    expect($daily->metric_sums['total_time_in_seconds'])->toBe(109)
        ->and($daily->metric_counts['total_time_in_seconds'])->toBe(4);
    expect($daily->metric_p95['total_time_in_seconds'])->toEqualWithDelta(85.75, 0.00001);
    $before = $daily->getAttributes();
    $this->artisan('heptapod:maintain-check-data')->assertSuccessful();
    expect($daily->fresh()->getAttributes())->toBe($before);
    expect(CheckRunRollupHourly::query()->count())->toBe(2);

    $ended = $day->addMinutes(4);
    CheckRun::factory()->create(['check_id' => $check->id, 'result' => RunResult::Warning, 'started_at' => $ended->subSecond(), 'ended_at' => $ended, 'metrics' => ['total_time_in_seconds' => 7]]);
    $this->artisan('heptapod:maintain-check-data')->assertSuccessful();
    $hour = $hour->fresh();
    $daily = $daily->fresh();
    expect($hour->warning_count)->toBe(2)
        ->and($hour->metric_sums['total_time_in_seconds'])->toBe(16)
        ->and($hour->metric_counts['total_time_in_seconds'])->toBe(4);
    expect($hour->metric_p95['total_time_in_seconds'])->toEqualWithDelta(6.7, 0.00001);
    expect($daily->metric_sums['total_time_in_seconds'])->toBe(116)
        ->and($daily->metric_counts['total_time_in_seconds'])->toBe(5);
    expect($daily->metric_p95['total_time_in_seconds'])->toEqualWithDelta(81.4, 0.00001);
    expect(CheckRunRollupHourly::query()->count())->toBe(2);
});

it('rolls a result recorded after midnight within the lease into the previous day', function (): void {
    $midnight = CarbonImmutable::parse('2026-10-05T00:00:00Z');
    $check = Check::factory()->create();
    $this->travelTo($midnight);
    CheckRun::factory()->create(['check_id' => $check->id, 'started_at' => $midnight->subHour(), 'ended_at' => $midnight->subHour()->addSecond(), 'metrics' => ['total_time_in_seconds' => 1]]);
    $this->artisan('heptapod:maintain-check-data')->assertSuccessful();
    // The attempt ended before midnight; its result is accepted at the end of the 390-second lease.
    $this->travelTo($midnight->addSeconds(390));
    CheckRun::factory()->create(['check_id' => $check->id, 'started_at' => $midnight->subSeconds(2), 'ended_at' => $midnight->subSecond(), 'metrics' => ['total_time_in_seconds' => 3]]);

    $this->travelTo($midnight->addHour());
    $this->artisan('heptapod:maintain-check-data')->assertSuccessful();
    $day = CheckRunRollupDaily::query()->where('check_id', $check->id)->sole();
    expect($day->bucket_start->equalTo($midnight->subDay()))->toBeTrue()
        ->and($day->succeeded_count)->toBe(2)
        ->and($day->metric_sums)->toBe(['total_time_in_seconds' => 4]);
});

it('catches up the previous day when maintenance resumes five hours after its last run', function (): void {
    $midnight = CarbonImmutable::parse('2026-10-05T00:00:00Z');
    $check = Check::factory()->create();
    $this->travelTo($midnight->subHour());
    CheckRun::factory()->create(['check_id' => $check->id, 'started_at' => $midnight->subMinutes(91), 'ended_at' => $midnight->subMinutes(90)]);
    $this->artisan('heptapod:maintain-check-data')->assertSuccessful();
    // Recorded while maintenance was down.
    $this->travelTo($midnight->subMinutes(30));
    CheckRun::factory()->create(['check_id' => $check->id, 'started_at' => $midnight->subMinutes(31), 'ended_at' => $midnight->subMinutes(30)]);

    $this->travelTo($midnight->addHours(4));
    $this->artisan('heptapod:maintain-check-data')->assertSuccessful();
    $day = CheckRunRollupDaily::query()->where('check_id', $check->id)->sole();
    expect($day->succeeded_count)->toBe(2)
        ->and($day->computed_at->equalTo($midnight->addHours(4)))->toBeTrue();
});

it('finalises the closed month and the previous day when maintenance resumes two days after its last run', function (): void {
    $monthEnd = CarbonImmutable::parse('2026-11-01T00:00:00Z');
    $check = Check::factory()->create();
    $this->travelTo($monthEnd->subHours(3));
    CheckRun::factory()->create(['check_id' => $check->id, 'started_at' => $monthEnd->subMinutes(211), 'ended_at' => $monthEnd->subMinutes(210)]);
    $this->artisan('heptapod:maintain-check-data')->assertSuccessful();
    // Recorded while maintenance was down, on both sides of the month end.
    $this->travelTo($monthEnd->subMinutes(30));
    CheckRun::factory()->create(['check_id' => $check->id, 'started_at' => $monthEnd->subMinutes(31), 'ended_at' => $monthEnd->subMinutes(30)]);
    $this->travelTo($monthEnd->addHours(12));
    CheckRun::factory()->create(['check_id' => $check->id, 'started_at' => $monthEnd->addHours(12)->subSecond(), 'ended_at' => $monthEnd->addHours(12)]);

    // With 30 days of retention the rotation proves each closed month within two hourly runs.
    foreach ([$monthEnd->addDay()->addHour(), $monthEnd->addDay()->addHours(2)] as $at) {
        $this->travelTo($at);
        $this->artisan('heptapod:maintain-check-data')->assertSuccessful();
    }
    $daily = fn (CarbonImmutable $day): CheckRunRollupDaily => CheckRunRollupDaily::query()->where('check_id', $check->id)->where('bucket_start', $day)->sole();
    expect($daily($monthEnd->subDay())->succeeded_count)->toBe(2)
        ->and($daily($monthEnd)->succeeded_count)->toBe(1)
        ->and(CheckRunRollupHourly::query()->where('check_id', $check->id)->where('bucket_start', $monthEnd->subHour())->sole()->succeeded_count)->toBe(1);

    // A final month is not rolled up again.
    $finalised = $daily($monthEnd->subDay())->getAttributes();
    $this->travelTo($monthEnd->addDays(2));
    $this->artisan('heptapod:maintain-check-data')->assertSuccessful();
    expect($daily($monthEnd->subDay())->getAttributes())->toBe($finalised);
});

it('drops only wholly expired months after rolling them up and keeps incident evidence and boundary-month runs', function (): void {
    $boundary = CarbonImmutable::parse('2026-03-31T00:00:00Z');
    $this->travelTo($boundary->subMicrosecond());
    DB::statement("CREATE TABLE IF NOT EXISTS check_runs_2026_02 PARTITION OF check_runs FOR VALUES FROM ('2026-02-01T00:00:00Z') TO ('2026-03-01T00:00:00Z')");
    $this->artisan('heptapod:maintain-check-data')->assertSuccessful();
    $check = Check::factory()->create();
    $ended = CarbonImmutable::parse('2026-02-28T23:59:59.999999Z');
    $run = CheckRun::factory()->create(['check_id' => $check->id, 'started_at' => $ended->subSecond(), 'ended_at' => $ended, 'result' => RunResult::Failed, 'metrics' => ['total_time_in_seconds' => 2], 'evidence' => ['status_code' => 503]]);
    $check->update(['latest_run_id' => $run->id, 'latest_run_ended_at' => $ended]);
    $incident = Incident::factory()->create(['check_id' => $check->id, 'evidence' => $run->evidence]);
    $atCutoff = CheckRun::factory()->create(['check_id' => $check->id, 'started_at' => $ended, 'ended_at' => $boundary->subDays(30)]);

    $this->artisan('heptapod:maintain-check-data', ['--recompute-range' => '2026-02-01T00:00:00Z,2026-03-01T00:00:00Z'])->assertSuccessful();
    expect(CheckRun::query()->forRun($run->reference())->exists())->toBeTrue();
    $this->travelTo($boundary);
    $this->artisan('heptapod:maintain-check-data')->assertSuccessful();
    expect(DB::selectOne("SELECT to_regclass('check_runs_2026_02') AS name")->name)->toBeNull();
    expect(CheckRun::query()->forRun($run->reference())->exists())->toBeFalse();
    expect(CheckRun::query()->forRun($atCutoff->reference())->exists())->toBeTrue();
    expect($incident->fresh()->evidence)->toBe(['status_code' => 503]);
    expect($check->fresh()->latest_run_id)->toBe($run->id);
    $hourly = CheckRunRollupHourly::query()->where('check_id', $check->id)->where('bucket_start', $ended->startOfHour()->format('Y-m-d H:i:s.uP'))->sole();
    $daily = CheckRunRollupDaily::query()->where('check_id', $check->id)->where('bucket_start', $ended->startOfDay()->format('Y-m-d H:i:s.uP'))->sole();
    expect($hourly->failed_count)->toBe(1)->and($daily->metric_sums)->toBe(['total_time_in_seconds' => 2]);
    $this->artisan('heptapod:maintain-check-data')->assertSuccessful();
    expect($daily->fresh()->getAttributes())->toBe($daily->getAttributes());
    expect(DB::selectOne("SELECT to_regclass('check_runs_2026_05') AS name")->name)->toBe('check_runs_2026_05');
    $future = CarbonImmutable::parse('2026-05-31T23:59:59Z');
    expect(CheckRun::factory()->create(['check_id' => $check->id, 'started_at' => $future->subSecond(), 'ended_at' => $future])->exists)->toBeTrue();
});

it('leaves observations older than the automatic windows unchanged until explicit range repair', function (): void {
    $day = CarbonImmutable::now('UTC')->startOfMonth()->addDays(3);
    $this->travelTo($day->addHours(3));
    $old = $day->subDays(2);
    $check = Check::factory()->create();
    CheckRun::factory()->create(['check_id' => $check->id, 'started_at' => $old, 'ended_at' => $old->addMinute(), 'metrics' => ['total_time_in_seconds' => 1]]);
    $range = $old->addMinutes(15)->toIso8601String().','.$old->addMinutes(16)->toIso8601String();
    $this->artisan('heptapod:maintain-check-data', ['--recompute-range' => $range])->assertSuccessful();
    $hour = CheckRunRollupHourly::query()->where('check_id', $check->id)->sole();
    $daily = CheckRunRollupDaily::query()->where('check_id', $check->id)->sole();
    CheckRun::factory()->create(['check_id' => $check->id, 'started_at' => $old, 'ended_at' => $old->addMinutes(30), 'metrics' => ['total_time_in_seconds' => 3]]);

    $this->artisan('heptapod:maintain-check-data')->assertSuccessful();
    expect($hour->fresh()->succeeded_count)->toBe(1)->and($daily->fresh()->metric_sums)->toBe(['total_time_in_seconds' => 1]);
    $this->artisan('heptapod:maintain-check-data', ['--recompute-range' => $range])->assertSuccessful();
    expect($hour->fresh()->succeeded_count)->toBe(2)->and($daily->fresh()->metric_sums)->toBe(['total_time_in_seconds' => 4]);
});

it('automatically repairs first-start historical raw data before dropping its expired partition', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-03-31T00:00:00Z'));
    DB::statement("CREATE TABLE IF NOT EXISTS check_runs_2026_02 PARTITION OF check_runs FOR VALUES FROM ('2026-02-01T00:00:00Z') TO ('2026-03-01T00:00:00Z')");
    $ended = CarbonImmutable::parse('2026-02-28T23:59:59Z');
    $run = CheckRun::factory()->create(['started_at' => $ended->subSecond(), 'ended_at' => $ended, 'metrics' => ['total_time_in_seconds' => 2]]);

    expect(CheckRunRollupHourly::query()->count())->toBe(0);
    expect(CheckRunRollupDaily::query()->count())->toBe(0);
    $this->artisan('heptapod:maintain-check-data')->assertSuccessful();
    expect(app(CheckDataMaintenance::class)->maintain(CarbonImmutable::now('UTC')))->toBe([]);
    expect(CheckRun::query()->forRun($run->reference())->exists())->toBeFalse();
    expect(CheckRunRollupHourly::query()->sole()->metric_counts)->toBe(['total_time_in_seconds' => 1]);
    expect(CheckRunRollupDaily::query()->sole()->metric_sums)->toBe(['total_time_in_seconds' => 2]);
});

it('proves each closed month in every six consecutive hourly runs while another month expires', function (): void {
    // 152 days of raw history: June to October are closed on 2026-11-29, and June expires on 2026-11-30 at 00:00.
    config(['monitoring.raw_retention_days' => 152, 'monitoring.hourly_rollup_retention_days' => 152]);
    foreach (['06', '07', '08', '09', '10'] as $month) {
        $start = CarbonImmutable::parse("2026-{$month}-01T00:00:00Z");
        DB::statement("CREATE TABLE IF NOT EXISTS check_runs_2026_{$month} PARTITION OF check_runs FOR VALUES FROM ('{$start->toIso8601String()}') TO ('{$start->addMonth()->toIso8601String()}')");
    }
    $check = Check::factory()->create();
    $proofs = [];
    for ($hour = 0; $hour < 14; $hour++) {
        $this->travelTo(CarbonImmutable::parse('2026-11-29T17:00:00Z')->addHours($hour));
        // A new October bucket that no rollup covers yet; only a proof of October rolls it up.
        $bucket = CarbonImmutable::parse('2026-10-01T00:00:00Z')->addHours($hour);
        CheckRun::factory()->create(['check_id' => $check->id, 'started_at' => $bucket, 'ended_at' => $bucket->addMinute()]);
        $this->artisan('heptapod:maintain-check-data')->assertSuccessful();
        $proofs[] = CheckRunRollupHourly::query()->where('check_id', $check->id)->where('bucket_start', $bucket)->exists();
    }

    expect(DB::selectOne("SELECT to_regclass('check_runs_2026_06') AS name")->name)->toBeNull();
    for ($window = 0; $window + 6 <= count($proofs); $window++) {
        expect(in_array(true, array_slice($proofs, $window, 6), true))->toBeTrue();
    }
});

it('prunes addon history only up to the month boundary before the cutoff when an older raw month is missing', function (): void {
    // With no January partition the oldest raw month is February, but the 30-day cutoff on March 1 is January 30.
    $this->travelTo(CarbonImmutable::parse('2026-03-01T01:00:00Z'));
    $check = Check::factory()->create();
    foreach (['2025-12-31T12:00:00Z', '2026-01-31T12:00:00Z'] as $index => $ended) {
        DB::table('dns_observations')->insert(['site_id' => $check->site_id, 'check_id' => $check->id, 'run_id' => 900 + $index,
            'claim_token' => '00000000-0000-4000-8000-00000000001'.$index, 'run_ended_at' => $ended, 'snapshot' => '{}', 'expected' => '{}', 'created_at' => $ended]);
    }

    $this->artisan('heptapod:maintain-check-data')->assertSuccessful();

    expect(DB::selectOne("SELECT to_regclass('check_runs_2026_01') AS name")->name)->toBeNull()
        ->and(DB::table('dns_observations')->pluck('run_id')->all())->toBe([901]);
});

it('proves an expired month again right before its drop, after the rollups of the same run', function (): void {
    DB::statement("CREATE TABLE IF NOT EXISTS check_runs_2026_02 PARTITION OF check_runs FOR VALUES FROM ('2026-02-01T00:00:00Z') TO ('2026-03-01T00:00:00Z')");
    $check = Check::factory()->create();
    $ended = CarbonImmutable::parse('2026-02-28T23:59:59Z');
    CheckRun::factory()->create(['check_id' => $check->id, 'started_at' => $ended->subSecond(), 'ended_at' => $ended]);
    $this->travelTo(CarbonImmutable::parse('2026-03-02T00:00:00Z'));
    $this->artisan('heptapod:maintain-check-data', ['--recompute-range' => '2026-02-01T00:00:00Z,2026-03-01T00:00:00Z'])->assertSuccessful();
    $this->travelTo(CarbonImmutable::parse('2026-03-31T00:00:00Z'));
    CheckRun::factory()->create(['check_id' => $check->id, 'started_at' => now()->subMinutes(2), 'ended_at' => now()->subMinute()]);
    // A backfill into February while this run rolls up the recent window, after February was first proven final.
    DB::unprepared(<<<SQL
        CREATE FUNCTION backfill_february() RETURNS trigger LANGUAGE plpgsql AS \$\$
        BEGIN
            INSERT INTO check_runs (check_id, check_type, result, started_at, ended_at, summary, metrics)
            SELECT {$check->id}, 'uptime', 'succeeded', '2026-02-15T11:59:59Z', '2026-02-15T12:00:00Z', 'checks.uptime.succeeded', '{}'
            WHERE NOT EXISTS (SELECT 1 FROM check_runs WHERE ended_at = '2026-02-15T12:00:00Z');
            RETURN NEW;
        END; \$\$;
        CREATE TRIGGER backfill_february AFTER INSERT ON check_run_rollups_hourly FOR EACH ROW EXECUTE FUNCTION backfill_february();
        SQL);

    $this->artisan('heptapod:maintain-check-data')->expectsOutputToContain('check_runs_2026_02')->assertSuccessful();

    expect(DB::selectOne("SELECT to_regclass('check_runs_2026_02') AS name")->name)->toBe('check_runs_2026_02')
        ->and(CheckRun::query()->where('ended_at', '2026-02-15T12:00:00Z')->exists())->toBeTrue();
});

it('keeps an expired month\'s addon history while its partition drop fails and prunes it after the drop', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-03-31T00:00:00Z'));
    DB::statement("CREATE TABLE IF NOT EXISTS check_runs_2026_02 PARTITION OF check_runs FOR VALUES FROM ('2026-02-01T00:00:00Z') TO ('2026-03-01T00:00:00Z')");
    $ended = CarbonImmutable::parse('2026-02-28T23:59:59Z');
    $run = CheckRun::factory()->create(['started_at' => $ended->subSecond(), 'ended_at' => $ended]);
    DB::table('dns_observations')->insert(['site_id' => $run->check->site_id, 'check_id' => $run->check_id, 'run_id' => $run->id,
        'claim_token' => '00000000-0000-4000-8000-000000000002', 'run_ended_at' => $ended, 'snapshot' => '{}', 'expected' => '{}', 'created_at' => $ended]);
    // A dependent view makes DROP TABLE fail after the month was proven final.
    DB::statement('CREATE VIEW february_runs AS SELECT id FROM check_runs_2026_02');

    expect(fn (): int => Artisan::call('heptapod:maintain-check-data'))->toThrow(QueryException::class, 'february_runs');
    expect(DB::selectOne("SELECT to_regclass('check_runs_2026_02') AS name")->name)->toBe('check_runs_2026_02')
        ->and(DB::table('dns_observations')->count())->toBe(1);

    DB::statement('DROP VIEW february_runs');
    $this->artisan('heptapod:maintain-check-data')->assertSuccessful();
    expect(DB::selectOne("SELECT to_regclass('check_runs_2026_02') AS name")->name)->toBeNull()
        ->and(DB::table('dns_observations')->count())->toBe(0);
});

it('requires finalisation at least ten minutes after the partition end before dropping raw data', function (int $microseconds, bool $finalised): void {
    $monthEnd = CarbonImmutable::parse('2026-03-01T00:00:00Z');
    $this->travelTo($monthEnd->addMicroseconds($microseconds));
    DB::statement("CREATE TABLE IF NOT EXISTS check_runs_2026_02 PARTITION OF check_runs FOR VALUES FROM ('2026-02-01T00:00:00Z') TO ('2026-03-01T00:00:00Z')");
    $ended = $monthEnd->subSecond();
    $run = CheckRun::factory()->create(['started_at' => $ended->subSecond(), 'ended_at' => $ended]);
    $this->artisan('heptapod:maintain-check-data')->assertSuccessful();
    $this->travelTo($monthEnd->addDays(30));

    $this->artisan('heptapod:maintain-check-data')->assertSuccessful();
    expect(CheckRun::query()->forRun($run->reference())->exists())->toBeFalse();
    $finalisedAt = $finalised ? $monthEnd->addMicroseconds($microseconds) : $monthEnd->addDays(30);
    expect(CheckRunRollupHourly::query()->sole()->computed_at->equalTo($finalisedAt))->toBeTrue()
        ->and(CheckRunRollupDaily::query()->sole()->computed_at->equalTo($finalisedAt))->toBeTrue();
    expect(CheckRunRollupHourly::query()->sole()->succeeded_count)->toBe(1);
    expect(CheckRunRollupDaily::query()->sole()->succeeded_count)->toBe(1);
})->with(['before safety margin' => [599999999, false], 'at safety margin' => [600000000, true]]);

it('rejects malformed empty and reversed manual repair ranges', function (string $range): void {
    expect(fn () => Artisan::call('heptapod:maintain-check-data', ['--recompute-range' => $range]))->toThrow(InvalidArgumentException::class);
})->with(['missing bound' => ['2026-02-01'], 'empty bound' => ['2026-02-01,'], 'three bounds' => ['2026-02-01,2026-02-02,2026-02-03'], 'reversed' => ['2026-02-02,2026-02-01'], 'empty range' => ['2026-02-01,2026-02-01']]);

it('fails loudly before rollups or pruning when a required partition name belongs to an ordinary table', function (): void {
    $month = CarbonImmutable::now('UTC')->startOfMonth();
    $this->travelTo($month->addDay());
    $collision = 'check_runs_'.$month->addMonths(2)->format('Y_m');
    DB::statement('CREATE TABLE '.$collision.' (id bigint)');
    $expiredStart = $month->subMonths(3);
    $expiredName = 'check_runs_'.$expiredStart->format('Y_m');
    DB::statement("CREATE TABLE IF NOT EXISTS {$expiredName} PARTITION OF check_runs FOR VALUES FROM ('{$expiredStart->toIso8601String()}') TO ('{$expiredStart->addMonth()->toIso8601String()}')");
    $ended = $expiredStart->addDay();
    $run = CheckRun::factory()->create(['started_at' => $ended->subSecond(), 'ended_at' => $ended]);

    expect(fn () => Artisan::call('heptapod:maintain-check-data'))->toThrow(RuntimeException::class, 'Required tables are not check_runs partitions: '.$collision);
    expect(CheckRun::query()->forRun($run->reference())->exists())->toBeTrue();
    expect(CheckRunRollupHourly::query()->count())->toBe(0);
    expect(CheckRunRollupDaily::query()->count())->toBe(0);
});
