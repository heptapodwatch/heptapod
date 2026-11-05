<?php

use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\OhDear\CheckData;
use Heptapod\Contracts\OhDear\CheckRunData;
use Heptapod\Contracts\OhDear\CheckSummaryData;
use Heptapod\Contracts\OhDear\MonitorData;
use Heptapod\Contracts\RunResult;
use Heptapod\Contracts\Schema\Field;
use Heptapod\Contracts\Schema\FieldType;
use Heptapod\Contracts\Schema\Schema;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use OhDear\PhpSdk\Dto\CheckSummary;
use OhDear\PhpSdk\Dto\Monitor;
use Spatie\LaravelData\Data;

uses(RefreshDatabase::class);

function ohDearFixture(string $name): array
{
    return json_decode(file_get_contents(base_path('tests/Fixtures/OhDear/'.$name.'.json')), true, flags: JSON_THROW_ON_ERROR);
}
function dtoJson(Data $data): array
{
    return json_decode($data->toJson(), true, flags: JSON_THROW_ON_ERROR);
}

it('serializes seeded Monitor Check CheckRun and CheckSummary data into the spec-derived fixtures', function (): void {
    // Exercise explicit fixture provisioning even when the wall-clock migration created this month.
    DB::statement('DROP TABLE IF EXISTS check_runs_2026_10');
    DB::statement("CREATE TABLE IF NOT EXISTS check_runs_2026_10 PARTITION OF check_runs FOR VALUES FROM ('2026-10-01T00:00:00+00:00') TO ('2026-11-01T00:00:00+00:00')");
    app()->setLocale('en');
    $monitor = ohDearFixture('Monitor');
    $config = $monitor['uptime_check_settings'];
    unset($config['frequency_value'], $config['frequency_unit'], $config['failed_notification_threshold']);
    $fields = [];
    foreach ($config as $key => $value) {
        $type = is_array($value) ? FieldType::List : (is_int($value) ? FieldType::Integer : FieldType::String);
        $fields[$key] = new Field($type, ['nullable'], items: $type === FieldType::List ? new Field(FieldType::String) : null);
    }
    $site = Site::factory()->create(['id' => 1, 'url' => $monitor['url'], 'friendly_name' => null, 'badge_id' => $monitor['badge_id'], 'created_at' => '2026-10-01 07:25:00', 'updated_at' => '2026-10-01 07:25:00']);
    $check = Check::factory()->create(['id' => 100, 'site_id' => $site->id, 'config' => new Schema($fields)->validateConfig($config), 'latest_run_result' => CheckResult::Succeeded,
        'latest_run_ended_at' => '2026-10-01 07:29:02', 'summary' => 'checks.uptime.succeeded']);
    $run = CheckRun::factory()->create(['id' => 200, 'check_id' => $check->id, 'started_at' => '2026-10-01 07:29:01', 'ended_at' => '2026-10-01 07:29:02']);
    $checkDto = new CheckData($check->id, $check->type, __('checks.uptime.label'), $check->enabled, $check->latest_run_ended_at->format('Y-m-d H:i:s'), $check->latest_run_result, __($check->summary), (object) ['uptime_check_settings' => (object) ['uptime_check_location' => $check->executionConfig()['location']]], null);
    expect(dtoJson($checkDto))->toEqual([...ohDearFixture('Check'), 'unavailable_reason' => null]);
    $sdkCheck = OhDear\PhpSdk\Dto\Check::fromResponse(dtoJson($checkDto));
    expect($sdkCheck->checkResult())->toBe(OhDear\PhpSdk\Enums\CheckResult::Succeeded);
    expect($sdkCheck->settings)->toBe(ohDearFixture('Check')['settings']);
    $runDto = new CheckRunData($run->id, $run->check_id, $run->check_type, $run->result, $run->started_at->format('Y-m-d\TH:i:s\Z'), $run->ended_at->format('Y-m-d\TH:i:s\Z'), 'https://dashboard.example/checks/'.$check->id.'/runs/'.$run->id);
    expect(dtoJson($runDto))->toEqual(ohDearFixture('CheckRun'));
    expect(dtoJson(new CheckSummaryData($check->latest_run_result, __($check->summary))))->toEqual(ohDearFixture('CheckSummary'));
    $settings = [...$check->executionConfig(), 'failed_notification_threshold' => $check->failure_threshold, 'frequency_value' => intdiv($check->interval_seconds, 60), 'frequency_unit' => 'minute'];
    $dto = new MonitorData($site->id, 1, $site->url, true, 'example.com', 'example.com', $site->group_name, [], $site->description, $site->notes,
        $checkDto->latest_run_ended_at, $check->latest_run_result, [$checkDto], $settings, $site->badge_id, null, $site->created_at->format('Y-m-d H:i:s'), $site->updated_at->format('Y-m-d H:i:s'));
    $monitor['checks'] = array_map(static fn (array $item): array => [...$item, 'unavailable_reason' => null], $monitor['checks']);
    expect(dtoJson($dto))->toEqual($monitor);
    $sdkMonitor = Monitor::fromResponse(dtoJson($dto));
    expect($sdkMonitor->checkResult())->toBe(OhDear\PhpSdk\Enums\CheckResult::Succeeded);
    expect($sdkMonitor->uptimeCheckSettings['valid_status_codes'])->toBe(['2*']);
    $sdkSummary = CheckSummary::fromResponse(dtoJson(new CheckSummaryData($check->latest_run_result, __($check->summary))));
    expect($sdkSummary->checkResult())->toBe(OhDear\PhpSdk\Enums\CheckResult::Succeeded);
});

it('preserves check errors internally but applies the two approved wire exceptions', function (): void {
    $check = Check::factory()->create(['latest_run_result' => CheckResult::Errored, 'latest_run_ended_at' => now()])->refresh();
    $run = CheckRun::factory()->create(['check_id' => $check->id, 'result' => RunResult::Errored, 'metrics' => null, 'error' => 'RuntimeException: Probe deadline exceeded'])->refresh();
    $ended = $run->ended_at->toIso8601String();
    expect(dtoJson(new CheckRunData($run->id, $check->id, $run->check_type, $run->result, null, $ended, 'https://dashboard.example/checks/'.$check->id.'/runs/'.$run->id))['result'])->toBe('failed');
    expect(dtoJson(new CheckSummaryData($check->latest_run_result, __('checks.engine.errored')))['result'])->toBe('failed');
    expect(dtoJson(new CheckData($check->id, $check->type, __('checks.uptime.label'), $check->enabled, $ended, $check->latest_run_result, null, new stdClass, null))['latest_run_result'])->toBe('errored-or-timed-out');
    $pending = Check::factory()->create()->refresh();
    expect($pending->latest_run_ended_at)->toBeNull();
    expect(dtoJson(new CheckSummaryData(null, __('checks.pending')))['result'])->toBeNull();
});
