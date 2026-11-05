<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Addons\Uptime\UptimeMetrics;
use Heptapod\Checks\Recording\RecordableRun;
use Heptapod\Checks\Recording\ResultRecorder;
use Heptapod\Contracts\CheckError;
use Heptapod\Contracts\CheckOutcome;
use Heptapod\Contracts\RunPhase;
use Heptapod\Contracts\RunResult;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\CheckRunAttempt;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Queries\HistoryReader;
use Heptapod\Settings\InstanceSettings;
use Heptapod\Testing\ClaimedRun;
use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-20T00:00:00Z'));
});

/** @param array<string, mixed> $attributes */
function retentionRollup(Check $check, string $period, string $bucket, string $computed, array $attributes = []): void
{
    DB::table('check_run_rollups_'.$period)->insert(['check_id' => $check->id, 'bucket_start' => $bucket,
        'computed_at' => $computed, 'succeeded_count' => 1, ...$attributes]);
}

/** @return array<string, list<string>> */
function retentionReport(): array
{
    expect(Artisan::call('heptapod:prune-history', ['--dry-run' => true]))->toBe(0);
    $rows = [];
    foreach (explode("\n", Artisan::output()) as $line) {
        if (str_starts_with($line, '|')) {
            $cells = array_map(trim(...), explode('|', trim($line, '|')));
            $rows[$cells[0]] = array_slice($cells, 1);
        }
    }

    return $rows;
}

it('shows no measured uptime before the first result', function (): void {
    $owner = User::factory()->owner()->create();
    $site = Site::factory()->create(['created_at' => '2026-10-19T23:00:00Z']);
    $check = Check::factory()->for($site)->create(['created_at' => '2026-10-19T23:00:00Z']);
    $this->actingAs($owner);

    $this->get('/sites')->assertInertia(fn (Assert $page): Assert => $page
        ->loadDeferredProps(['list'], fn (Assert $list): Assert => $list
            ->has('sites', 1)->where('sites.0.id', $site->id)->where('sites.0.uptime', null)));
    $this->get('/sites/'.$site->id)->assertInertia(fn (Assert $page): Assert => $page
        ->where('uptime_ranges', ['hours24' => null, 'days7' => null, 'days30' => null, 'days90' => null]));
    $history = app(HistoryReader::class)->read($owner, $site->id, $check->id);
    expect($history['uptime_percentage'])->toBeNull();
    expect(array_column($history['uptime'], 'uptime'))->toBe(array_fill(0, 90, null));
});

it('shows measured uptime after the first recorded success', function (): void {
    $owner = User::factory()->owner()->create();
    $site = Site::factory()->create(['created_at' => '2026-10-19T23:00:00Z']);
    $check = Check::factory()->for($site)->create(['created_at' => '2026-10-19T23:00:00Z']);
    $startedAt = CarbonImmutable::parse('2026-10-19T23:10:00Z');
    $context = ClaimedRun::start($check, $startedAt);
    $curl = array_fill_keys(['namelookup_time', 'connect_time', 'appconnect_time', 'starttransfer_time', 'pretransfer_time', 'redirect_time', 'total_time', 'header_size', 'size_download', 'speed_download'], null);
    $success = new CheckOutcome(RunResult::Succeeded, 'uptime.succeeded', [],
        new UptimeMetrics(null, null, null, null, null, 0.1, $curl, $startedAt->toIso8601String()));

    expect(app(ResultRecorder::class)->record(new RecordableRun($check->id, $context->claimToken,
        $startedAt, $startedAt->addSecond(), $success))->accepted)->toBeTrue();

    expect($check->refresh()->first_result_at)->toEqual(CarbonImmutable::parse('2026-10-19T23:10:01Z'));
    $this->actingAs($owner)->get('/sites')->assertInertia(fn (Assert $page): Assert => $page
        ->loadDeferredProps(['list'], fn (Assert $list): Assert => $list
            ->has('sites', 1)->where('sites.0.id', $site->id)->where('sites.0.uptime', 100)));
    $this->get('/sites/'.$site->id)->assertInertia(fn (Assert $page): Assert => $page
        ->where('uptime_ranges', ['hours24' => 100, 'days7' => 100, 'days30' => 100, 'days90' => 100]));
    $history = app(HistoryReader::class)->read($owner, $site->id, $check->id);
    expect($history['uptime_percentage'])->toBe(100.0);
    expect($history['uptime'][89]->uptime)->toBe(100.0);
});

it('preserves the created-at uptime window when backfilling after real raw retention', function (): void {
    $owner = User::factory()->owner()->create();
    $site = Site::factory()->create(['created_at' => '2026-07-25T00:00:00Z']);
    $check = Check::factory()->for($site)->create(['created_at' => '2026-07-25T00:00:00Z']);
    DB::statement("CREATE TABLE IF NOT EXISTS check_runs_2026_07 PARTITION OF check_runs FOR VALUES FROM ('2026-07-01') TO ('2026-08-01')");
    DB::statement("CREATE TABLE IF NOT EXISTS check_runs_2026_09 PARTITION OF check_runs FOR VALUES FROM ('2026-09-01') TO ('2026-10-01')");
    foreach (['2026-07-25T00:00:00Z', '2026-09-25T00:00:00Z'] as $endedAt) {
        CheckRun::factory()->for($check)->create(['started_at' => CarbonImmutable::parse($endedAt)->subSecond(), 'ended_at' => $endedAt]);
    }
    Incident::factory()->for($check)->create(['opened_at' => '2026-08-01T00:00:00Z',
        'first_failure_at' => '2026-08-01T00:00:00Z', 'resolved_at' => '2026-08-01T01:00:00Z']);

    $this->artisan('heptapod:maintain-check-data')->assertSuccessful();

    expect(DB::scalar("SELECT to_regclass('check_runs_2026_07')"))->toBeNull();
    expect(CheckRun::query()->where('check_id', $check->id)->pluck('ended_at')->all())
        ->toEqual([CarbonImmutable::parse('2026-09-25T00:00:00Z')]);
    $this->assertDatabaseHas('check_run_rollups_daily', ['check_id' => $check->id, 'bucket_start' => '2026-07-25T00:00:00Z', 'succeeded_count' => 1]);
    $migration = require database_path('migrations/2026_10_22_103856_add_first_result_at_to_checks.php');
    $migration->down();
    $migration->up();

    expect($check->refresh()->first_result_at)->toEqual(CarbonImmutable::parse('2026-07-25T00:00:00Z'));
    // One hour down across the 87 days from July 25 to October 20: 2087 / 2088 hours up.
    $this->actingAs($owner)->get('/sites/'.$site->id)->assertInertia(fn (Assert $page): Assert => $page
        ->where('uptime_ranges.days90', 99.9521072796935));
});

it('starts the uptime window at a first result recorded after creation', function (): void {
    $owner = User::factory()->owner()->create();
    $site = Site::factory()->create(['created_at' => '2026-10-10T00:00:00Z']);
    $check = Check::factory()->for($site)->create(['created_at' => '2026-10-10T00:00:00Z']);
    $startedAt = CarbonImmutable::parse('2026-10-15T00:00:00Z');
    $context = ClaimedRun::start($check, $startedAt);
    $curl = array_fill_keys(['namelookup_time', 'connect_time', 'appconnect_time', 'starttransfer_time', 'pretransfer_time', 'redirect_time', 'total_time', 'header_size', 'size_download', 'speed_download'], null);
    $success = new CheckOutcome(RunResult::Succeeded, 'uptime.succeeded', [],
        new UptimeMetrics(null, null, null, null, null, 0.1, $curl, $startedAt->toIso8601String()));
    expect(app(ResultRecorder::class)->record(new RecordableRun($check->id, $context->claimToken,
        $startedAt, $startedAt->addSecond(), $success))->accepted)->toBeTrue();
    Incident::factory()->for($check)->create(['opened_at' => '2026-10-16T00:00:00Z',
        'first_failure_at' => '2026-10-16T00:00:00Z', 'resolved_at' => '2026-10-16T01:00:00Z']);

    expect($check->refresh()->first_result_at)->toEqual(CarbonImmutable::parse('2026-10-15T00:00:01Z'));
    // The 7-day window starts at the first result (Oct 15 00:00:01), not at creation (Oct 10, clipped to Oct 13):
    // one hour down out of 5 days minus 1 s.
    $this->actingAs($owner)->get('/sites/'.$site->id)->assertInertia(fn (Assert $page): Assert => $page
        ->where('uptime_ranges.days7', 100 * (1 - 3_600_000 / 431_999_000)));
});

it('records the first measured result once and ignores check errors', function (): void {
    $check = Check::factory()->create();
    $startedAt = CarbonImmutable::parse('2026-10-19T23:00:00Z');
    $context = ClaimedRun::start($check, $startedAt);
    $error = new CheckOutcome(RunResult::Errored, 'checks.engine.errored', [], null,
        error: new CheckError(RuntimeException::class, 'Fixture check error.'));

    expect(app(ResultRecorder::class)->record(new RecordableRun($check->id, $context->claimToken,
        $startedAt, $startedAt->addSecond(), $error))->accepted)->toBeTrue();
    $this->assertDatabaseHas('checks', ['id' => $check->id, 'first_result_at' => null]);

    $startedAt = CarbonImmutable::parse('2026-10-19T23:10:00Z');
    $context = ClaimedRun::start($check->refresh(), $startedAt);
    $curl = array_fill_keys(['namelookup_time', 'connect_time', 'appconnect_time', 'starttransfer_time', 'pretransfer_time', 'redirect_time', 'total_time', 'header_size', 'size_download', 'speed_download'], null);
    $success = new CheckOutcome(RunResult::Succeeded, 'uptime.succeeded', [],
        new UptimeMetrics(null, null, null, null, null, 0.1, $curl, $startedAt->toIso8601String()));
    expect(app(ResultRecorder::class)->record(new RecordableRun($check->id, $context->claimToken,
        $startedAt, $startedAt->addSecond(), $success))->accepted)->toBeTrue();
    expect($check->refresh()->first_result_at)->toEqual(CarbonImmutable::parse('2026-10-19T23:10:01Z'));

    $startedAt = CarbonImmutable::parse('2026-10-19T23:20:00Z');
    $context = ClaimedRun::start($check, $startedAt);
    expect(app(ResultRecorder::class)->record(new RecordableRun($check->id, $context->claimToken,
        $startedAt, $startedAt->addSecond(), $success))->accepted)->toBeTrue();
    expect($check->refresh()->first_result_at)->toEqual(CarbonImmutable::parse('2026-10-19T23:10:01Z'));
});

it('previews all retention targets and exemptions without changing rows or raw partitions', function (): void {
    $check = Check::factory()->create();
    retentionRollup($check, 'hourly', '2026-07-18T01:00:00Z', '2026-07-19T00:10:00Z');
    retentionRollup($check, 'hourly', '2026-07-19T01:00:00Z', '2026-07-20T00:10:00Z');
    retentionRollup($check, 'daily', '2026-07-18T00:00:00Z', '2026-07-19T00:10:00Z');
    retentionRollup($check, 'daily', '2024-01-01T00:00:00Z', '2024-01-02T00:10:00Z');
    $episode = ['alert_episode_started_at' => '2025-01-01T00:00:00Z', 'alert_after_minutes' => 5, 'alert_recovery_checks' => 2];
    $open = Incident::factory()->for($check)->create(['opened_at' => '2025-01-01T00:00:00Z', ...$episode]);
    Incident::factory()->for($check)->create(['resolved_at' => '2025-02-01T00:00:00Z', ...$episode]);
    Incident::factory()->for($check)->create(['resolved_at' => '2025-03-01T00:00:00Z']);
    $expired = Event::factory()->for($check)->create(['occurred_at' => '2025-03-01T00:00:00Z']);
    Event::factory()->for($check)->for($open)->create(['occurred_at' => '2025-03-01T00:00:00Z']);
    $pending = Event::factory()->for($check)->create(['occurred_at' => '2025-03-01T00:00:00Z']);
    $digest = Event::factory()->for($check)->create(['occurred_at' => '2025-03-01T00:00:00Z', 'type' => 'notification.digest']);
    $destination = NotificationDestination::factory()->create();
    foreach ([[$expired, 'sent', null], [$pending, 'pending', $digest->id]] as [$event, $state, $digestId]) {
        Delivery::query()->create(['event_id' => $event->id, 'destination_id' => $destination->id, 'state' => $state,
            'next_attempt_at' => now(), 'suppression_evaluated_at' => now(), 'digest_event_id' => $digestId]);
    }
    DB::table('activity_log')->insert(['log_name' => 'default', 'description' => 'retention fixture', 'created_at' => '2025-03-01T00:00:00Z']);
    DB::table('failed_jobs')->insert(['uuid' => '00000000-0000-4000-8000-000000000001', 'connection' => 'redis', 'queue' => 'default', 'payload' => '{}', 'exception' => 'fixture', 'failed_at' => '2026-10-01T00:00:00Z']);
    DB::table('job_batches')->insert(['id' => 'retention', 'name' => 'fixture', 'total_jobs' => 1, 'pending_jobs' => 0, 'failed_jobs' => 0, 'failed_job_ids' => '[]', 'created_at' => 1790812800, 'finished_at' => 1790812800]);
    $before = [];
    foreach (['check_run_rollups_hourly', 'check_run_rollups_daily', 'events', 'deliveries', 'incidents', 'activity_log', 'failed_jobs', 'job_batches'] as $table) {
        $before[$table] = DB::table($table)->orderBy('id')->get()->all();
    }
    $partitions = DB::select("SELECT inhrelid FROM pg_inherits WHERE inhparent = 'check_runs'::regclass ORDER BY inhrelid");

    $rows = retentionReport();

    expect($rows['check_run_rollups_hourly'])->toBe(['1', '2026-07-18 01:00:00+00', '1', '200000', 'daily coverage missing=1', 'no']);
    expect($rows['check_run_rollups_daily'][0])->toBe('1');
    expect($rows['events'])->toBe(['1', '2025-03-01 00:00:00+00', '1', '200000', 'kept incident=1; pending/sending=2; digest sources retained=1', 'no']);
    expect($rows['deliveries'][0])->toBe('1');
    expect($rows['incidents'][0])->toBe('1');
    expect($rows['incidents'][4])->toBe('open=1; open episode=1');
    foreach (['activity_log', 'failed_jobs', 'job_batches'] as $table) {
        expect($rows[$table][0])->toBe('1');
    }
    foreach ($before as $table => $records) {
        expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($records);
        expect($rows[$table][5])->toBe('no');
    }
    expect(DB::select("SELECT inhrelid FROM pg_inherits WHERE inhparent = 'check_runs'::regclass ORDER BY inhrelid"))->toEqual($partitions);
    $this->artisan('heptapod:prune-history')->expectsOutputToContain('History deletion is not available yet')->assertFailed();
    config(['monitoring.hourly_rollup_deletion_enabled' => true]);
    $enabledRows = retentionReport();
    foreach (array_keys($before) as $table) {
        expect($enabledRows[$table][5])->toBe($table === 'check_run_rollups_hourly' ? 'yes' : 'no');
    }
    $this->artisan('heptapod:prune-history')->expectsOutputToContain('History deletion is not available yet')->assertFailed();
});

it('deletes only expired hours with final daily coverage after explicit enable and converges on a second run', function (): void {
    $check = Check::factory()->create();
    retentionRollup($check, 'hourly', '2026-07-18T01:00:00Z', '2026-07-19T00:10:00Z');
    retentionRollup($check, 'hourly', '2026-07-19T01:00:00Z', '2026-07-20T00:10:00Z');
    retentionRollup($check, 'hourly', '2026-07-19T02:00:00Z', '2026-07-20T00:10:00Z');
    retentionRollup($check, 'hourly', '2026-07-20T00:00:00Z', '2026-07-21T00:10:00Z');
    retentionRollup($check, 'hourly', '2026-07-20T01:00:00Z', '2026-07-21T00:10:00Z');
    retentionRollup($check, 'daily', '2026-07-18T00:00:00Z', '2026-07-19T00:10:00Z');
    retentionRollup($check, 'daily', '2026-07-19T00:00:00Z', '2026-07-20T00:09:59Z');
    retentionRollup($check, 'daily', '2026-07-20T00:00:00Z', '2026-07-21T00:10:00Z');
    $this->artisan('heptapod:maintain-check-data')->assertSuccessful();
    expect(DB::table('check_run_rollups_hourly')->count())->toBe(5);
    expect(retentionReport()['check_run_rollups_hourly'][0])->toBe('1');
    config(['monitoring.hourly_rollup_deletion_enabled' => true]);

    $this->artisan('heptapod:maintain-check-data')->assertSuccessful();

    expect(DB::table('check_run_rollups_hourly')->orderBy('bucket_start')->pluck('bucket_start')->all())->toBe([
        '2026-07-19 01:00:00+00', '2026-07-19 02:00:00+00', '2026-07-20 00:00:00+00', '2026-07-20 01:00:00+00',
    ]);
    expect(retentionReport()['check_run_rollups_hourly'])->toBe(['0', '', '0', '200000', 'daily coverage missing=2', 'yes']);
    $this->artisan('heptapod:maintain-check-data')->assertSuccessful();
    expect(DB::table('check_run_rollups_hourly')->count())->toBe(4);
    expect(DB::table('check_run_rollups_daily')->count())->toBe(3);
});

it('caps hourly deletion at 200000 rows per maintenance run', function (): void {
    $check = Check::factory()->create();
    DB::statement("INSERT INTO check_run_rollups_hourly (check_id, bucket_start, computed_at) SELECT ?, '2000-01-01'::timestamptz + n * interval '1 hour', '2026-01-01'::timestamptz FROM generate_series(0, 200000) n", [$check->id]);
    DB::statement("INSERT INTO check_run_rollups_daily (check_id, bucket_start, computed_at) SELECT DISTINCT check_id, date_trunc('day', bucket_start, 'UTC'), '2026-01-01'::timestamptz FROM check_run_rollups_hourly");
    expect(retentionReport()['check_run_rollups_hourly'][2])->toBe('200000');
    config(['monitoring.hourly_rollup_deletion_enabled' => true]);

    $this->artisan('heptapod:maintain-check-data')->assertSuccessful();

    expect(DB::table('check_run_rollups_hourly')->count())->toBe(1);
    $this->artisan('heptapod:maintain-check-data')->assertSuccessful();
    expect(DB::table('check_run_rollups_hourly')->count())->toBe(0);
});

it('retains attempts only from the configured cutoff onwards', function (?int $days, bool $keepsTwoDays, bool $keepsEightDays): void {
    $at = CarbonImmutable::now();
    if ($days !== null) {
        config(['monitoring.attempt_retention_days' => $days]);
    }
    $check = Check::factory()->create();
    $cutoff = $at->subDays($days ?? 7);
    $attempts = [];
    foreach ([$at->subDays(2), $at->subDays(8), $cutoff->subSecond(), $cutoff, $cutoff->addSecond()] as $index => $requestedAt) {
        $attempts[] = CheckRunAttempt::query()->create([
            'check_id' => $check->id, 'claim_token' => sprintf('00000000-0000-4000-8000-%012d', $index),
            'phase' => RunPhase::Done, 'queue' => 'checks-fast', 'requested_at' => $requestedAt,
        ]);
    }

    $this->artisan('heptapod:maintain-check-data')->assertSuccessful();

    expect(CheckRunAttempt::query()->whereKey($attempts[0]->id)->exists())->toBe($keepsTwoDays);
    expect(CheckRunAttempt::query()->whereKey($attempts[1]->id)->exists())->toBe($keepsEightDays);
    $this->assertModelMissing($attempts[2]);
    $this->assertModelExists($attempts[3]);
    $this->assertModelExists($attempts[4]);
})->with([
    'one day' => [1, false, false],
    'thirty days' => [30, true, true],
    'default seven days' => [null, true, false],
]);

it('rejects invalid retention configuration before maintenance writes', function (array $config): void {
    config($config);
    $partitions = DB::select("SELECT inhrelid FROM pg_inherits WHERE inhparent = 'check_runs'::regclass ORDER BY inhrelid");

    expect(fn () => Artisan::call('heptapod:prune-history', ['--dry-run' => true]))->toThrow(LogicException::class);
    expect(fn () => Artisan::call('heptapod:maintain-check-data'))->toThrow(LogicException::class);

    expect(DB::select("SELECT inhrelid FROM pg_inherits WHERE inhparent = 'check_runs'::regclass ORDER BY inhrelid"))->toEqual($partitions);
})->with([
    'hourly below raw' => [['monitoring.raw_retention_days' => 100]],
    'hourly below history margin' => [['monitoring.hourly_rollup_retention_days' => 91]],
    'daily below hourly' => [['monitoring.daily_rollup_retention_days' => 91]],
    'history below raw' => [['monitoring.raw_retention_days' => 100, 'monitoring.hourly_rollup_retention_days' => 110, 'monitoring.history_retention_days' => 99]],
    'history below view margin' => [['monitoring.history_retention_days' => 92]],
    'not integer' => [['monitoring.attempt_retention_days' => '7']],
    'not positive' => [['monitoring.failed_job_retention_days' => 0]],
    'not boolean' => [['monitoring.hourly_rollup_deletion_enabled' => 'false']],
]);

it('returns explicit 422 for API windows past the hourly or uptime history horizon', function (string $locale, string $endpoint, string $query, string $field, string $message): void {
    app()->setLocale($locale);
    $owner = User::factory()->owner()->create(['locale' => $locale]);
    $check = Check::factory()->create();
    $this->withToken($owner->createToken('retention', ['*'])->plainTextToken);

    $response = $this->getJson('/api/monitors/'.$check->site_id.'/'.$endpoint.'?'.$query)->assertUnprocessable();
    expect($response->json('errors')[$field][0])->toBe($message);
})->with([
    ['en', 'http-uptime-metrics', 'filter[start]=20260719000000&filter[end]=20260720000000', 'filter.start', 'Hourly history is only available for the last 92 days'],
    ['de', 'http-uptime-metrics', 'filter[start]=20260719000000&filter[end]=20260720000000', 'filter.start', 'Stündliche Verlaufsdaten sind nur für die letzten 92 Tage verfügbar'],
    ['en', 'http-uptime-metrics', 'filter[start]=20260719000000&filter[end]=20260720000000&filter[group_by]=day', 'filter.start', 'Hourly history is only available for the last 92 days'],
    ['en', 'http-uptime-metrics', 'filter[start]=20260719000000&filter[end]=20260720000000&filter[group_by]=week', 'filter.start', 'Hourly history is only available for the last 92 days'],
    ['en', 'http-uptime-metrics', 'filter[start]=20260719000000&filter[end]=20260720000000&filter[group_by]=month', 'filter.start', 'Hourly history is only available for the last 92 days'],
    ['en', 'uptime', 'filter[started_at]=20260719000000&filter[ended_at]=20260720000000', 'filter.started_at', 'Hourly history is only available for the last 92 days'],
    ['de', 'uptime', 'filter[started_at]=20251019000000&filter[ended_at]=20251020000000', 'filter.started_at', 'Erreichbarkeitsdaten sind nur für die letzten 365 Tage verfügbar'],
]);

it('keeps the complete oldest of 90 local history days in UTC plus 14 after pruning', function (): void {
    $owner = User::factory()->owner()->create();
    $check = Check::factory()->create(['created_at' => '2026-01-01T00:00:00Z']);
    $check->site->update(['created_at' => '2026-01-01T00:00:00Z']);
    $settings = app(InstanceSettings::class);
    $settings->timezone = 'Pacific/Kiritimati';
    for ($hour = 0; $hour < 24; $hour++) {
        retentionRollup($check, 'hourly', CarbonImmutable::parse('2026-07-21T10:00:00Z')->addHours($hour)->toIso8601String(), '2026-07-23T00:10:00Z', [
            'succeeded_count' => $hour === 0 ? 0 : 1, 'failed_count' => $hour === 0 ? 1 : 0,
            'metric_sums' => '{"total_time_in_seconds":2}', 'metric_counts' => '{"total_time_in_seconds":1}',
        ]);
    }
    retentionRollup($check, 'hourly', '2026-07-18T01:00:00Z', '2026-07-19T00:10:00Z');
    retentionRollup($check, 'daily', '2026-07-18T00:00:00Z', '2026-07-19T00:10:00Z');
    retentionRollup($check, 'daily', '2026-07-21T00:00:00Z', '2026-07-23T00:10:00Z');
    retentionRollup($check, 'daily', '2026-07-22T00:00:00Z', '2026-07-24T00:10:00Z');
    $before = app(HistoryReader::class)->read($owner, $check->site_id, $check->id);
    expect($before['uptime'])->toHaveCount(90);
    expect($before['uptime'][0]->date)->toBe('2026-07-21T10:00:00+00:00');
    expect($before['uptime'][0]->succeeded_count)->toBe(23);
    expect($before['uptime'][0]->failed_count)->toBe(1);
    expect($before['uptime'][0]->failed_seconds)->toBe(60);
    config(['monitoring.hourly_rollup_deletion_enabled' => true]);

    $this->artisan('heptapod:maintain-check-data')->assertSuccessful();

    $after = app(HistoryReader::class)->read($owner, $check->site_id, $check->id);
    expect($after)->toEqual($before);
    expect(DB::table('check_run_rollups_hourly')->count())->toBe(24);
});

it('reports expired raw partitions with missing coverage without recomputing or dropping them', function (): void {
    DB::statement("CREATE TABLE check_runs_2024_01 PARTITION OF check_runs FOR VALUES FROM ('2024-01-01') TO ('2024-02-01')");
    $check = Check::factory()->create();
    $run = CheckRun::factory()->for($check)->create(['started_at' => '2024-01-10T12:00:00Z', 'ended_at' => '2024-01-10T12:01:00Z']);

    $this->artisan('heptapod:prune-history', ['--dry-run' => true])
        ->expectsOutputToContain('check_runs_2024_01: missing buckets=2; existing maintenance would skip')->assertSuccessful();

    $this->assertModelExists($run);
    expect(DB::table('check_run_rollups_hourly')->count())->toBe(0);
    expect(DB::table('check_run_rollups_daily')->count())->toBe(0);
});

it('accepts the exact hourly cutoff and rejects a calendar expansion beyond uptime history with 422', function (): void {
    $owner = User::factory()->owner()->create();
    $check = Check::factory()->create(['created_at' => '2026-01-01T00:00:00Z']);
    $this->withToken($owner->createToken('retention', ['*'])->plainTextToken);
    $this->getJson('/api/monitors/'.$check->site_id.'/http-uptime-metrics?filter[start]=20260720000000&filter[end]=20260720010000')
        ->assertOk()->assertJsonPath('data', []);
    $this->getJson('/api/monitors/'.$check->site_id.'/uptime?filter[started_at]=20260720000000&filter[ended_at]=20260720010000')->assertOk();
    config(['monitoring.hourly_rollup_retention_days' => 400, 'monitoring.history_retention_days' => 93]);
    retentionRollup($check, 'hourly', '2026-07-23T01:00:00Z', '2026-07-24T00:10:00Z');

    $response = $this->getJson('/api/monitors/'.$check->site_id.'/uptime?filter[started_at]=20260723000000&filter[ended_at]=20260801000000&split=month')->assertUnprocessable();

    expect($response->json('errors')['filter.started_at'][0])->toBe('Uptime history is only available for the last 93 days');
});

it('logs the original rollup failure before a subsequent hourly prune failure', function (): void {
    $check = Check::factory()->create();
    CheckRun::factory()->for($check)->create(['started_at' => now()->subMinutes(2), 'ended_at' => now()->subMinute()]);
    retentionRollup($check, 'hourly', '2026-07-18T01:00:00Z', '2026-07-19T00:10:00Z');
    retentionRollup($check, 'daily', '2026-07-18T00:00:00Z', '2026-07-19T00:10:00Z');
    DB::unprepared(<<<'SQL'
        CREATE FUNCTION storage_b_fail_rollup() RETURNS trigger LANGUAGE plpgsql AS $$
        BEGIN RAISE EXCEPTION 'rollup fixture failed'; END; $$;
        CREATE TRIGGER storage_b_fail_rollup BEFORE INSERT OR UPDATE ON check_run_rollups_hourly
            FOR EACH ROW EXECUTE FUNCTION storage_b_fail_rollup();
        CREATE FUNCTION storage_b_fail_prune() RETURNS trigger LANGUAGE plpgsql AS $$
        BEGIN RAISE EXCEPTION 'prune fixture failed'; END; $$;
        CREATE TRIGGER storage_b_fail_prune BEFORE DELETE ON check_run_rollups_hourly
            FOR EACH ROW EXECUTE FUNCTION storage_b_fail_prune();
        SQL);
    config(['monitoring.hourly_rollup_deletion_enabled' => true]);
    $errors = [];
    Log::listen(function (MessageLogged $event) use (&$errors): void {
        if ($event->level === 'error') {
            $errors[] = $event;
        }
    });

    expect(fn () => DB::transaction(fn (): int => Artisan::call('heptapod:maintain-check-data')))
        ->toThrow(QueryException::class);
    // The failed delete aborts the wrapping test transaction before maintenance can unlock; the savepoint is restored here.
    DB::select('SELECT pg_advisory_unlock(73410201)');

    expect($errors)->toHaveCount(2);
    expect($errors[0]->message)->toBe('Check run rollup computation failed before retention.');
    expect($errors[0]->context['exception']->getMessage())->toContain('rollup fixture failed');
    expect($errors[1]->message)->toBe('Check data maintenance failed.');
    expect($errors[1]->context['exception'])->toContain('prune fixture failed');
});
