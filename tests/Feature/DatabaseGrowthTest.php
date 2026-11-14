<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\DiskSpaceReader;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRunRollupDaily;
use Heptapod\Models\CheckRunRollupHourly;
use Heptapod\Models\Event;
use Heptapod\Models\User;
use Heptapod\SystemHealth\DiskReadException;
use Heptapod\SystemHealth\DiskReadFailure;
use Heptapod\Testing\FakeDiskSpaceReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-06T12:00:00Z'));
    config(['heptapod.health.browser_urls' => [], 'broadcasting.default' => 'log',
        'heptapod.system_issues.postgres_data_path' => '/fixture/postgres',
        'monitoring.raw_retention_days' => 30, 'monitoring.attempt_retention_days' => 7, 'monitoring.hourly_rollup_retention_days' => 92,
        'monitoring.hourly_rollup_deletion_enabled' => true]);
    $this->disk = FakeDiskSpaceReader::healthy();
    $this->app->instance(DiskSpaceReader::class, $this->disk);
    $this->owner = User::factory()->create();
    $this->owner->assignRole('Owner');
});

/**
 * @param  array<string, array{bytes: int, rows: int}>  $tables
 * @param  array<string, int>  $intake
 */
function databaseSizeSample(string $day, int $totalBytes, array $tables, array $intake): void
{
    DB::table('database_size_samples')->insert(['sampled_on' => $day, 'sampled_at' => $day.' 00:05:00+00', 'total_bytes' => $totalBytes,
        'tables' => json_encode($tables), 'intake' => json_encode($intake)]);
}

it('records one catalog sample per UTC day with the last 24 hours of intake and keeps three years of samples', function (): void {
    $this->actingAs($this->owner)->getJson('/settings/system/data')->assertOk()->assertJsonPath('health.databaseGrowth', null);
    $check = Check::factory()->create();
    Event::factory()->for($check)->count(2)->create(['occurred_at' => now()->subHours(23)]);
    Event::factory()->for($check)->create(['occurred_at' => now()->subHours(25)]);
    databaseSizeSample('2023-10-06', 1, [], []);
    databaseSizeSample('2023-10-07', 1, [], []);

    $this->artisan('heptapod:record-database-size')->assertSuccessful();
    $this->travelTo(CarbonImmutable::parse('2026-10-06T23:00:00Z'));
    $this->artisan('heptapod:record-database-size')->expectsOutput('Today\'s database size sample already exists.')->assertSuccessful();

    $samples = DB::table('database_size_samples')->orderBy('sampled_on')->get();
    expect($samples->pluck('sampled_on')->all())->toBe(['2023-10-07', '2026-10-06']);
    $today = $samples->last();
    expect($today->sampled_at)->toStartWith('2026-10-06 12:00:00')
        ->and(json_decode($today->intake, true))->toEqual(['check_results' => 0, 'attempts' => 0, 'hourly_rollups' => 0, 'daily_rollups' => 0, 'history' => 2]);
    $tables = json_decode($today->tables, true);
    // Monthly partitions count under check_runs, so the projection sees one table per data class.
    expect($tables)->toHaveKeys(['check_runs', 'events', 'database_size_samples'])
        ->and(array_filter(array_keys($tables), static fn (string $table): bool => str_starts_with($table, 'check_runs_')))->toBe([])
        ->and($tables['events']['bytes'])->toBeGreaterThan(0)
        // The sampler analyzes intake tables, so the row estimate is the real count, not the unknown -1 of a fresh table.
        ->and($tables['events']['rows'])->toBe(3);

    // Just after midnight the rollup intake is yesterday's complete buckets, never the bucket still filling.
    $checks = Check::factory()->count(3)->create();
    foreach ($checks as $check) {
        CheckRunRollupDaily::factory()->for($check)->create(['bucket_start' => '2026-10-06T00:00:00Z']);
        CheckRunRollupHourly::factory()->for($check)->create(['bucket_start' => '2026-10-06T00:00:00Z']);
    }
    CheckRunRollupDaily::factory()->for($checks[0])->create(['bucket_start' => '2026-10-07T00:00:00Z']);
    CheckRunRollupHourly::factory()->for($checks[0])->create(['bucket_start' => '2026-10-07T00:00:00Z']);
    $this->travelTo(CarbonImmutable::parse('2026-10-07T00:00:05Z'));
    $this->artisan('heptapod:record-database-size')->assertSuccessful();
    expect(json_decode(DB::table('database_size_samples')->where('sampled_on', '2026-10-07')->value('intake'), true))
        ->toMatchArray(['hourly_rollups' => 3, 'daily_rollups' => 3]);

    $this->actingAs($this->owner)->getJson('/settings/system/data')->assertOk()
        ->assertJsonPath('health.databaseGrowth.sampleCount', 2)->assertJsonPath('health.databaseGrowth.measured', false);
});

it('levels the projection off at the enforced retention windows and fits it against the free disk', function (): void {
    $this->disk->samples['/fixture/postgres'] = ['free' => 10_000_000, 'total' => 50_000_000];
    // 1000 B per check result at 100 a day for 30 + 31 days; 1000 B per attempt at 100 a day for 7 days; 100 B per hourly rollup at 24 a day for 92 days.
    $tables = ['check_runs' => ['bytes' => 1_000_000, 'rows' => 1000], 'check_run_attempts' => ['bytes' => 100_000, 'rows' => 100],
        'check_run_rollups_hourly' => ['bytes' => 24_000, 'rows' => 240], 'sites' => ['bytes' => 500_000, 'rows' => 10]];
    foreach (range(6, 0) as $daysAgo) {
        databaseSizeSample(CarbonImmutable::parse('2026-10-06')->subDays($daysAgo)->toDateString(), 10_000_000, $tables,
            ['check_results' => 100, 'attempts' => 100, 'hourly_rollups' => 24, 'daily_rollups' => 0, 'history' => 0]);
    }

    $growth = $this->actingAs($this->owner)->getJson('/settings/system/data')->assertOk()->json('health.databaseGrowth');

    expect($growth)->toMatchArray(['measured' => true, 'sampleCount' => 7,
        'in30DaysBytes' => 10_000_000 + 3_000_000 + 600_000 + 72_000,
        'in90DaysBytes' => 10_000_000 + 5_100_000 + 600_000 + 196_800,
        'in365DaysBytes' => 15_896_800, 'plateauBytes' => 15_896_800, 'plateauOn' => '2026-12-27', 'monthlyGrowthBytes' => null])
        ->and($growth['projection'])->toBe([['date' => '2026-10-06', 'bytes' => 10_000_000], ['date' => '2026-10-12', 'bytes' => 10_000_000 + 600_000 + 600_000 + 14_400],
            ['date' => '2026-11-26', 'bytes' => 10_000_000 + 5_100_000 + 600_000 + 122_400], ['date' => '2026-12-27', 'bytes' => 15_896_800], ['date' => '2027-10-06', 'bytes' => 15_896_800]])
        ->and($growth['disk'])->toBe(['path' => '/fixture/postgres', 'failure' => null, 'freeBytes' => 10_000_000, 'fillsOn' => null, 'spareBytes' => 10_000_000 - 5_896_800])
        ->and($growth['classes'][0])->toBe(['dataClass' => 'check_results', 'bytes' => 1_000_000, 'in365DaysBytes' => 6_100_000, 'retentionDays' => 30,
            'retentionEnforced' => true, 'retentionVariable' => 'MONITORING_RAW_RETENTION_DAYS'])
        // Other is the rest of the database, so the classes add up to the measured total.
        ->and($growth['classes'][5])->toMatchArray(['dataClass' => 'other', 'bytes' => 10_000_000 - 1_124_000, 'in365DaysBytes' => 10_000_000 - 1_124_000, 'retentionDays' => null]);

    // Without hourly deletion the configured 92 days are not enforced, so hourly rollups keep growing and nothing levels off.
    config(['monitoring.hourly_rollup_deletion_enabled' => false]);
    $growth = $this->getJson('/settings/system/data')->assertOk()->json('health.databaseGrowth');
    expect($growth)->toMatchArray(['plateauBytes' => null, 'plateauOn' => null, 'monthlyGrowthBytes' => 72_000, 'in365DaysBytes' => 15_700_000 + 876_000])
        ->and($growth['classes'][2])->toMatchArray(['dataClass' => 'hourly_rollups', 'retentionDays' => 92, 'retentionEnforced' => false]);
});

it('estimates unbounded growth from the current intake before a week of samples and finds the day the disk fills', function (): void {
    $this->disk->samples['/fixture/postgres'] = ['free' => 950_000, 'total' => 50_000_000];
    // History has no enforced retention: 5000 B per event at 20 events a day grows 100 000 B a day without limit.
    databaseSizeSample('2026-10-06', 2_000_000, ['events' => ['bytes' => 50_000, 'rows' => 10]], ['history' => 20]);

    $growth = $this->actingAs($this->owner)->getJson('/settings/system/data')->assertOk()->json('health.databaseGrowth');

    expect($growth)->toMatchArray(['measured' => false, 'sampleCount' => 1, 'in90DaysBytes' => 11_000_000, 'in365DaysBytes' => 38_500_000,
        'plateauBytes' => null, 'monthlyGrowthBytes' => 3_000_000])
        ->and($growth['disk'])->toBe(['path' => '/fixture/postgres', 'failure' => null, 'freeBytes' => 950_000, 'fillsOn' => '2026-10-16', 'spareBytes' => null])
        ->and($growth['classes'][4])->toMatchArray(['dataClass' => 'history', 'retentionDays' => 365, 'retentionEnforced' => false,
            'retentionVariable' => 'MONITORING_HISTORY_RETENTION_DAYS']);

    // Five days later the free space is read again; only growth after today counts against it, not the days since the sample.
    $this->travelTo(CarbonImmutable::parse('2026-10-11T12:00:00Z'));
    $this->getJson('/settings/system/data')->assertOk()->assertJsonPath('health.databaseGrowth.disk.fillsOn', '2026-10-21');
});

it('says plainly when the database disk cannot be read instead of guessing its free space', function (?string $path, ?DiskReadFailure $failure): void {
    config(['heptapod.system_issues.postgres_data_path' => $path]);
    $this->disk->samples['/fixture/postgres'] = new DiskReadException(DiskReadFailure::Missing, '/fixture/postgres');
    databaseSizeSample('2026-10-06', 2_000_000, ['events' => ['bytes' => 50_000, 'rows' => 10]], ['history' => 20]);

    $this->actingAs($this->owner)->getJson('/settings/system/data')->assertOk()
        ->assertJsonPath('health.databaseGrowth.disk', ['path' => $path, 'failure' => $failure?->value, 'freeBytes' => null, 'fillsOn' => null, 'spareBytes' => null]);
})->with([
    'unreadable path' => ['/fixture/postgres', DiskReadFailure::Missing],
    'path not configured or visible' => [null, null],
]);

it('attributes only existing tables to the check results that addon retention prunes', function (): void {
    $registry = app(CheckRegistry::class);
    $tables = [];
    foreach ($registry->keys() as $key) {
        $tables = [...$tables, ...($registry->get($key)->persistence()?->prunedTables() ?? [])];
    }

    // A renamed addon table would otherwise drop silently into Other and lose its retention plateau.
    expect($tables)->not->toBeEmpty()->each(fn ($table) => $table->toBeString()->and(Schema::hasTable($table->value))->toBeTrue());
    // Rows that a pruned table's deletes cascade into leave with it, so their table belongs to the same class.
    $cascading = array_column(DB::select(
        "SELECT DISTINCT conrelid::regclass::text AS name FROM pg_constraint WHERE contype = 'f' AND confdeltype = 'c' AND confrelid::regclass::text = ANY(?::text[]) ORDER BY 1",
        ['{'.implode(',', $tables).'}'],
    ), 'name');
    expect($cascading)->not->toBeEmpty()->and(array_diff($cascading, $tables))->toBe([]);
});
