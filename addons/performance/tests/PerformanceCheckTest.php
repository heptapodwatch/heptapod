<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Checks\Execution\CheckExecutor;
use Heptapod\Checks\Execution\ClaimService;
use Heptapod\Checks\Execution\RunOrigin;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\RunResult;
use Heptapod\Enums\NotificationCategory;
use Heptapod\Enums\NotificationMode;
use Heptapod\Jobs\RunCheck;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../../../tests/Support/NotificationChannels.php';

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05T12:30:00Z'));
    Http::preventStrayRequests();
    Http::fake();
});

/** @param list<?float> $current
 * @param  list<?float>  $previous
 * @param  array<string, int>  $config
 */
function runPerformanceAddon(array $current, array $previous = [], array $config = [], int $baselineDays = 3, int $evaluations = 3): ?CheckRun
{
    $site = Site::factory()->create();
    $uptime = Check::factory()->for($site)->create();
    foreach ([$current, $previous] as $window => $samples) {
        foreach ($samples as $index => $seconds) {
            $endedAt = $window === 0 ? CarbonImmutable::now()->subMinutes($index + 31)
                : CarbonImmutable::now()->startOfHour()->subDays($index % $baselineDays + 1)->addMinutes(intdiv($index, $baselineDays) + 1);
            CheckRun::factory()->for($uptime)->create([
                'started_at' => $endedAt->subSecond(),
                'ended_at' => $endedAt,
                'metrics' => ['total_time_in_seconds' => $seconds],
            ]);
        }
    }
    // Other site's runs and failed/error samples must never enter the average.
    CheckRun::factory()->create(['metrics' => ['total_time_in_seconds' => 1000.0]]);
    CheckRun::factory()->for($uptime)->create(['result' => RunResult::Failed, 'started_at' => CarbonImmutable::now()->subMinute()->subSecond(), 'ended_at' => CarbonImmutable::now()->subMinute(), 'metrics' => ['total_time_in_seconds' => 1000.0]]);
    CheckRun::factory()->for($uptime)->create(['result' => RunResult::Errored, 'started_at' => CarbonImmutable::now()->subMinute()->subSecond(), 'ended_at' => CarbonImmutable::now()->subMinute(), 'metrics' => null]);
    CheckRun::factory()->for($uptime)->create(['started_at' => CarbonImmutable::now()->subMinutes(61)->subSecond(), 'ended_at' => CarbonImmutable::now()->subMinutes(61), 'metrics' => ['total_time_in_seconds' => 1000.0]]);
    foreach ([CarbonImmutable::parse('2026-10-04T13:00:00Z'), CarbonImmutable::parse('2026-09-27T12:15:00Z')] as $outsideBaseline) {
        CheckRun::factory()->for($uptime)->create(['started_at' => $outsideBaseline->subSecond(), 'ended_at' => $outsideBaseline, 'metrics' => ['total_time_in_seconds' => 1000.0]]);
    }
    $check = Check::factory()->for($site)->create([
        'type' => CheckKey::Performance,
        'config' => app(CheckRegistry::class)->get(CheckKey::Performance)->definition()->configSchema->validateConfig($config),
    ]);
    Bus::fake([RunCheck::class]);
    for ($evaluation = 0; $evaluation < $evaluations; $evaluation++) {
        expect(app(ClaimService::class)->runNow($check->id, RunOrigin::System))->not->toBeNull();
        app(CheckExecutor::class)->execute($check->id, $check->refresh()->claim_token, 10);
    }
    Http::assertNothingSent();

    return CheckRun::query()->where('check_id', $check->id)->orderByDesc('id')->first();
}

it('derives response-time warnings from successful uptime windows without another probe', function (array $current, array $previous, array $config, RunResult $result, string $summary, float $average, ?float $change): void {
    $run = runPerformanceAddon($current, $previous, $config);
    expect($run->result)->toBe($result)
        ->and($run->summary)->toBe($summary)
        ->and($run->metrics['average_response_time_in_ms'])->toEqual($average)
        ->and($run->metrics['change_percentage'])->toEqual($change);
})->with([
    'default threshold' => [[3.0, 3.0, 3.0], [], [], RunResult::Warning, 'performance.median_above_threshold', 3000.0, null],
    'threshold is strict' => [[2.0], [], [], RunResult::Succeeded, 'performance.succeeded', 2000.0, null],
    'configured threshold' => [[3.0], [], ['threshold_in_ms' => 4000], RunResult::Succeeded, 'performance.succeeded', 3000.0, null],
    'same-hour baseline rise' => [[1.0, 2.0, 3.0], array_fill(0, 9, 1.0), [], RunResult::Warning, 'performance.median_above_configured_range', 2000.0, 100.0],
    'change threshold strict' => [[1.5, 1.5, 1.5], array_fill(0, 9, 1.0), [], RunResult::Succeeded, 'performance.succeeded', 1500.0, 50.0],
    'configured change' => [[1.5, 1.5, 1.5], array_fill(0, 9, 1.0), ['change_percentage' => 25], RunResult::Warning, 'performance.median_above_configured_range', 1500.0, 50.0],
    'sparse baseline' => [[2.0, 2.0, 2.0], [0.1], [], RunResult::Succeeded, 'performance.succeeded', 2000.0, null],
    'sparse current' => [[2.0], array_fill(0, 9, 0.1), [], RunResult::Succeeded, 'performance.succeeded', 2000.0, null],
    'zero baseline' => [[1.0, 1.0, 1.0], array_fill(0, 9, 0.0), [], RunResult::Succeeded, 'performance.succeeded', 1000.0, null],
    'falling times' => [[0.5, 0.5, 0.5], array_fill(0, 9, 1.0), [], RunResult::Succeeded, 'performance.succeeded', 500.0, -50.0],
    'missing metric is not zero' => [[null, 1.0], [], [], RunResult::Succeeded, 'performance.succeeded', 1000.0, null],
    'observed zero is sample' => [[0.0, 1.0], [], [], RunResult::Succeeded, 'performance.succeeded', 500.0, null],
]);

it('requires nine same-UTC-hour samples on three days and ignores outside-hour and eighth-day samples', function (int $count, int $days, ?float $baseline, ?float $change, RunResult $result, string $summary, array $params): void {
    $run = runPerformanceAddon([2.0, 2.0, 2.0], array_fill(0, $count, 1.0), ['threshold_in_ms' => 4000], $days);

    expect($run->result)->toBe($result)
        ->and($run->summary)->toBe($summary)
        ->and($run->summary_params)->toEqual($params)
        ->and($run->metrics)->toEqual([
            'average_response_time_in_ms' => 2000, 'previous_average_response_time_in_ms' => $baseline,
            'change_percentage' => $change, 'sample_count' => 3, 'previous_sample_count' => $count,
            'consecutive_warning_samples' => $result === RunResult::Warning ? 3 : 0,
            'consecutive_good_samples' => $result === RunResult::Warning ? 0 : 3,
            'consecutive_improved_samples' => 0, 'consecutive_normal_samples' => 0,
        ]);
})->with([
    'eight samples on three days' => [8, 3, null, null, RunResult::Succeeded, 'performance.succeeded', ['average' => 2000]],
    'nine samples on two days' => [9, 2, null, null, RunResult::Succeeded, 'performance.succeeded', ['average' => 2000]],
    'nine samples on three days' => [9, 3, 1000.0, 100.0, RunResult::Warning, 'performance.median_above_configured_range',
        ['average' => 2000, 'median' => 2000, 'baseline' => 1000, 'p90' => 1000, 'delta' => 1000, 'minimum_increase' => 50, 'relative_threshold' => 1500]],
]);

it('retains raw averages but compares interpolated median and p90 with rounded summary measurements', function (): void {
    $run = runPerformanceAddon([1.0, 2.0, 5.0], [0.5, 0.6, 0.7, 0.8, 1.0, 1.1, 1.2, 1.5, 2.0], ['threshold_in_ms' => 10000]);

    expect($run->result)->toBe(RunResult::Warning)
        ->and($run->summary)->toBe('performance.median_above_configured_range')
        ->and($run->summary_params)->toEqual(['average' => 2666.67, 'median' => 2000, 'baseline' => 1000, 'p90' => 1600,
            'delta' => 1000, 'minimum_increase' => 50, 'relative_threshold' => 1600])
        ->and($run->metrics['average_response_time_in_ms'])->toBe(8000 / 3)
        ->and($run->metrics['previous_average_response_time_in_ms'])->toBe(1000)
        ->and($run->metrics['change_percentage'])->toBe(100)
        ->and($run->metrics['sample_count'])->toBe(3)
        ->and($run->metrics['previous_sample_count'])->toBe(9);
});

it('confirms a sustained warning only on the third evaluation', function (int $evaluations, RunResult $result, string $summary, array $params): void {
    $run = runPerformanceAddon([3.0, 3.0, 3.0], evaluations: $evaluations);

    expect($run->result)->toBe($result)
        ->and($run->summary)->toBe($summary)
        ->and($run->summary_params)->toEqual($params)
        ->and($run->metrics['consecutive_warning_samples'])->toBe($evaluations)
        ->and($run->metrics['consecutive_good_samples'])->toBe(0);
})->with([
    [1, RunResult::Succeeded, 'performance.confirming_warning', ['average' => 3000, 'count' => 1]],
    [2, RunResult::Succeeded, 'performance.confirming_warning', ['average' => 3000, 'count' => 2]],
    [3, RunResult::Warning, 'performance.median_above_threshold', ['average' => 3000, 'median' => 3000, 'threshold' => 2000]],
]);

/**
 * Evaluates once per entry, each with three current samples of that many seconds, against a same-hour baseline on three days.
 *
 * @param  list<float>  $currentSeconds
 */
function evaluatePerformanceSequence(array $currentSeconds, float $baselineSeconds = 2.0, int $baselineSamples = 9): Check
{
    $site = Site::factory()->create();
    $uptime = Check::factory()->for($site)->create();
    for ($index = 0; $index < $baselineSamples; $index++) {
        $endedAt = CarbonImmutable::now()->startOfHour()->subDays($index % 3 + 1)->addMinutes(intdiv($index, 3) + 1);
        CheckRun::factory()->for($uptime)->create(['started_at' => $endedAt->subSecond(), 'ended_at' => $endedAt, 'metrics' => ['total_time_in_seconds' => $baselineSeconds]]);
    }
    $current = [];
    foreach ([31, 32, 33] as $minutes) {
        $current[] = CheckRun::factory()->for($uptime)->create(['started_at' => CarbonImmutable::now()->subMinutes($minutes)->subSecond(), 'ended_at' => CarbonImmutable::now()->subMinutes($minutes)]);
    }
    $check = Check::factory()->for($site)->create(['type' => CheckKey::Performance,
        'config' => app(CheckRegistry::class)->get(CheckKey::Performance)->definition()->configSchema->validateConfig([])]);
    Bus::fake([RunCheck::class]);
    foreach ($currentSeconds as $seconds) {
        foreach ($current as $run) {
            $run->update(['metrics' => ['total_time_in_seconds' => $seconds]]);
        }
        expect(app(ClaimService::class)->runNow($check->id, RunOrigin::System))->not->toBeNull();
        app(CheckExecutor::class)->execute($check->id, $check->refresh()->claim_token, 10);
    }

    return $check;
}

function performanceImprovements(Check $check): int
{
    return Event::query()->where('check_id', $check->id)->where('type', 'performance.improved')->count();
}

it('notes a confirmed speed-up once as an info event and re-arms only after three normal evaluations', function (): void {
    $check = evaluatePerformanceSequence([0.3, 0.3]);
    expect(performanceImprovements($check))->toBe(0);

    $check = evaluatePerformanceSequence([0.3, 0.3, 0.3]);
    $event = Event::query()->where('check_id', $check->id)->where('type', 'performance.improved')->sole();
    expect($event->to_result)->toBe(CheckResult::Succeeded)
        ->and($event->incident_id)->toBeNull()
        ->and(Incident::query()->where('check_id', $check->id)->exists())->toBeFalse()
        ->and(CheckRun::query()->where('check_id', $check->id)->pluck('result')->unique()->all())->toBe([RunResult::Succeeded]);
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $this->actingAs($owner)->get('/activity?period=all')->assertInertia(fn (Assert $page): Assert => $page
        ->where('events.0.latest.id', $event->id)
        ->where('events.0.latest.severity', 'info')
        ->where('events.0.latest.message', 'Got faster: 2 s → 0.3 s (-85%).'));

    $check = evaluatePerformanceSequence([0.3, 0.3, 0.3, 0.3, 2.0, 2.0, 0.3, 2.0, 2.0, 0.3, 0.3, 0.3]);
    expect(performanceImprovements($check))->toBe(1);

    $check = evaluatePerformanceSequence([0.3, 0.3, 0.3, 0.3, 2.0, 2.0, 2.0, 0.3, 0.3, 0.3]);
    expect(performanceImprovements($check))->toBe(2);
});

it('notes no speed-up without a baseline, below the change or 200 ms floor, or when a warning recovers', function (array $currentSeconds, float $baselineSeconds, int $baselineSamples): void {
    expect(performanceImprovements(evaluatePerformanceSequence($currentSeconds, $baselineSeconds, $baselineSamples)))->toBe(0);
})->with([
    'eight baseline samples' => [[0.3, 0.3, 0.3], 2.0, 8],
    'at the change percentage' => [[1.0, 1.0, 1.0], 2.0, 9],
    'less than 200 ms faster' => [[0.12, 0.12, 0.12], 0.3, 9],
    'recovery from a warning' => [[3.0, 3.0, 3.0, 0.3, 0.3, 0.3], 2.0, 9],
]);

it('routes a speed-up through Changes only to channels that turned Changes on', function (): void {
    $default = NotificationDestination::factory()->create();
    $immediate = notificationRoute(NotificationDestination::factory()->create(), [NotificationCategory::Changes]);
    $digest = notificationRoute(NotificationDestination::factory()->create(), [NotificationCategory::Changes], NotificationMode::Digest);
    $check = evaluatePerformanceSequence([0.3, 0.3, 0.3]);
    $event = Event::query()->where('check_id', $check->id)->where('type', 'performance.improved')->sole();

    dispatchNotifications();

    expect(NotificationCategory::of($event))->toBe(NotificationCategory::Changes)
        ->and(Delivery::query()->where('event_id', $event->id)->where('suppressed', false)->orderBy('destination_id')->pluck('destination_id')->all())
        ->toBe([$immediate->id, $digest->id])
        ->and(Delivery::query()->where('destination_id', $default->id)->exists())->toBeFalse();
});

it('returns no observation when there are no usable uptime samples without rewriting state', function (): void {
    expect(runPerformanceAddon([null]))->toBeNull();
    $check = Check::query()->where('type', CheckKey::Performance)->firstOrFail();
    expect($check->latest_run_id)->toBeNull()
        ->and($check->consecutive_failures)->toBe(0)
        ->and($check->claim_token)->toBeNull();
});

it('accepts warning uptime samples as online observations', function (): void {
    $site = Site::factory()->create();
    $uptime = Check::factory()->for($site)->create();
    CheckRun::factory()->for($uptime)->create(['result' => RunResult::Warning, 'started_at' => CarbonImmutable::now()->subMinute()->subSecond(), 'ended_at' => CarbonImmutable::now()->subMinute(), 'metrics' => ['total_time_in_seconds' => 1.0]]);
    $check = Check::factory()->for($site)->create(['type' => CheckKey::Performance]);
    Bus::fake([RunCheck::class]);
    expect(app(ClaimService::class)->runNow($check->id, RunOrigin::System))->not->toBeNull();
    app(CheckExecutor::class)->execute($check->id, $check->refresh()->claim_token, 10);
    $run = CheckRun::query()->where('check_id', $check->id)->firstOrFail();
    expect($run->result)->toBe(RunResult::Succeeded)
        ->and($run->metrics['average_response_time_in_ms'])->toBe(1000)
        ->and($run->metrics['sample_count'])->toBe(1);
    Http::assertNothingSent();
});

it('rejects invalid performance settings at their exact field', function (array $config, string $field): void {
    try {
        app(CheckRegistry::class)->get(CheckKey::Performance)->definition()->configSchema->validateConfig($config);
        $this->fail('Invalid performance settings accepted.');
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe([$field]);
    }
})->with([
    [['threshold_in_ms' => -1], 'values.threshold_in_ms'],
    [['change_percentage' => -1], 'values.change_percentage'],
    [['threshold_in_ms' => '2000'], 'values.threshold_in_ms'],
]);

it('loads performance translations in both locales with matching keys', function (): void {
    $en = json_decode(file_get_contents(__DIR__.'/../lang/en.json'), true, flags: JSON_THROW_ON_ERROR);
    $de = json_decode(file_get_contents(__DIR__.'/../lang/de.json'), true, flags: JSON_THROW_ON_ERROR);
    expect(array_keys($en))->toBe(array_keys($de));
    foreach (['en' => $en, 'de' => $de] as $locale => $keys) {
        app()->setLocale($locale);
        foreach ($keys as $key => $value) {
            expect(__($key))->toBe($value);
        }
    }
});
