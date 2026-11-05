<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\RunResult;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\Event;

it('materializes legacy cause facts in runs, current snapshots and retained event payloads', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-03T12:00:00Z'));
    $migration = require database_path('migrations/2026_10_03_170539_backfill_legacy_summary_facts.php');
    $examples = [
        [CheckKey::Performance, 'performance.slow', ['average' => 2800], ['average_response_time_in_ms' => 2800], [], 'causes.response_time', ['seconds' => 2.8, 'threshold' => 2.5]],
        [CheckKey::CertificateHealth, 'certificate_health.expires_soon', [], [], ['certificate_details' => ['valid_until' => '2026-10-12T12:00:00Z']], 'causes.certificate_expiry', ['days' => 9, 'presentation' => 'direct']],
        [CheckKey::Uptime, 'uptime.failed', [], ['total_time_in_seconds' => 3.5], ['status_code' => 503, 'issues' => ['status']], 'causes.http_timing', ['status' => 503, 'seconds' => 3.5]],
        [CheckKey::Uptime, 'uptime.status', [], [], ['failure_kind' => 'timeout', 'timeout_seconds' => 10], 'causes.timeout', ['seconds' => 10]],
        [CheckKey::Uptime, 'uptime.failed', [], [], ['issues' => ['connection']], 'causes.connection', []],
    ];
    $retained = [];
    foreach ($examples as [$type, $old, $params, $metrics, $evidence, $expectedKey, $expectedParams]) {
        $config = $type === CheckKey::Performance
            ? app(CheckRegistry::class)->get($type)->definition()->configSchema->validateConfig(['threshold_in_ms' => 2500])
            : app(CheckRegistry::class)->get($type)->definition()->configSchema->validateConfig([]);
        $check = Check::factory()->create(['type' => $type, 'config' => $config, 'summary' => $old, 'summary_params' => $params]);
        $run = CheckRun::factory()->for($check)->create(['result' => $type === CheckKey::Uptime ? RunResult::Failed : RunResult::Warning,
            'summary' => $old, 'summary_params' => $params, 'metrics' => $metrics, 'evidence' => $evidence]);
        $check->update(['latest_run_id' => $run->id, 'latest_run_ended_at' => $run->ended_at]);
        $event = Event::factory()->for($check)->create(['to_result' => CheckResult::Warning,
            'payload' => ['run_id' => $run->id, 'run_ended_at' => $run->ended_at->format('Y-m-d\\TH:i:s.uP'), 'summary' => $old, 'summary_params' => $params, 'other' => 'keep']]);
        $retained[] = [$check, $run, $event, $expectedKey, $expectedParams];
    }
    $untouched = CheckRun::factory()->create(['summary' => 'uptime.succeeded', 'summary_params' => ['marker' => 1]]);

    $migration->up();
    $migration->up();

    foreach ($retained as [$check, $run, $event, $expectedKey, $expectedParams]) {
        expect($run->refresh()->summary)->toBe($expectedKey)->and($run->summary_params)->toBe($expectedParams)
            ->and($check->refresh()->summary)->toBe($expectedKey)->and($check->summary_params)->toBe($expectedParams)
            ->and($event->refresh()->payload['summary'])->toBe($expectedKey)
            ->and($event->payload['summary_params'])->toBe($expectedParams)
            ->and($event->payload['other'])->toBe('keep');
    }
    expect($untouched->refresh()->summary)->toBe('uptime.succeeded')->and($untouched->summary_params)->toBe(['marker' => 1]);
});
