<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Addons\Dns\DnsMetrics;
use Heptapod\Addons\Uptime\UptimeMetrics;
use Heptapod\Checks\Recording\RecordableRun;
use Heptapod\Checks\Recording\ResultRecorder;
use Heptapod\Contracts\CheckError;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckOutcome;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Contracts\RunResult;
use Heptapod\Enums\NotificationCategory;
use Heptapod\Enums\NotificationMode;
use Heptapod\Models\Check;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Testing\ClaimedRun;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../Support/NotificationChannels.php';

// The team default is Relaxed: uptime alerts after 30 failed minutes, other checks wait for a confirming run.
beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-28T12:00:00Z'));
    Http::preventStrayRequests();
    Http::fake(['https://receiver.test/alerts' => Http::response([], 200)]);
    notificationRoute(notificationWebhook(), [NotificationCategory::Outage, NotificationCategory::Failure,
        NotificationCategory::CheckError, NotificationCategory::Recovery]);
});

/**
 * An hourly DNS check whose regular slot is fixed at minute 30:56 of each hour. CheckSchedule spreads checks over the
 * interval by their ID; a sequence-assigned ID would move the regular slot with test order, before or after the
 * 10-minute confirming re-run.
 */
function hourlyDnsCheck(): Check
{
    $check = Check::factory()->create(['id' => 1000155, 'type' => CheckKey::Dns, 'interval_seconds' => 3600, 'failure_threshold' => 1]);
    expect(crc32((string) $check->id) % 3600)->toBe(1856);

    return $check;
}

function sensitivityRun(Check $check, RunResult $result): void
{
    $curl = array_fill_keys(['namelookup_time', 'connect_time', 'appconnect_time', 'starttransfer_time', 'pretransfer_time', 'redirect_time', 'total_time', 'header_size', 'size_download', 'speed_download'], null);
    $outcome = match (true) {
        $check->type === CheckKey::Uptime => new CheckOutcome($result, $result === RunResult::Failed ? 'uptime.status' : 'uptime.succeeded', [],
            new UptimeMetrics($result === RunResult::Failed ? 503 : 200, null, null, null, null, 0.1, $curl, now()->toIso8601String())),
        $result === RunResult::Errored => new CheckOutcome($result, 'checks.engine.errored', [], null, error: new CheckError(RuntimeException::class, 'Resolver timed out')),
        $result === RunResult::Failed => new CheckOutcome($result, 'dns.issue.records_missing', ['types' => 'AAAA'], new DnsMetrics(1, 1, false)),
        default => new CheckOutcome($result, 'dns.succeeded', [], new DnsMetrics(1, 0, false)),
    };
    $context = ClaimedRun::start($check, CarbonImmutable::now('UTC')->subSecond());
    app(ResultRecorder::class)->record(new RecordableRun($check->id, $context->claimToken, $context->startedAt, CarbonImmutable::now('UTC'), $outcome));
    dispatchNotifications();
}

it('keeps a 26-minute outage quiet and alerts a 31-minute one under Relaxed', function (int $failedMinutes, int $alerts): void {
    $check = Check::factory()->create(['type' => CheckKey::Uptime]);
    foreach ([...array_fill(0, $failedMinutes, RunResult::Failed), ...array_fill(0, 4, RunResult::Succeeded)] as $result) {
        sensitivityRun($check, $result);
        $this->travel(1)->minutes();
    }
    // Opener and recovery, or nothing; the incident list keeps the short outage either way.
    Http::assertSentCount($alerts);
    expect(Incident::query()->where('check_id', $check->id)->whereNotNull('resolved_at')->count())->toBe(1);
})->with(['26 minutes' => [26, 0], '31 minutes' => [31, 2]]);

it('sends a Check error of an hourly check only once the re-run 10 minutes later confirms it', function (RunResult $rerun, int $alerts): void {
    $check = hourlyDnsCheck();
    sensitivityRun($check, RunResult::Errored);
    expect(Incident::query()->where('check_id', $check->id)->sole()->resolved_at)->toBeNull()
        ->and($check->refresh()->next_run_at->toIso8601String())->toBe('2026-10-28T12:10:00+00:00');
    $this->travel(5)->minutes();
    dispatchNotifications();
    Http::assertNothingSent();

    $this->travelTo($check->next_run_at);
    sensitivityRun($check, $rerun);
    $this->travel(1)->minutes();
    dispatchNotifications();

    Http::assertSentCount($alerts);
    if ($rerun === RunResult::Succeeded) {
        // The one-off stays in the incident list; neither its opener nor its recovery is sent.
        expect(Incident::query()->where('check_id', $check->id)->sole()->resolved_at)->not->toBeNull()
            ->and(Delivery::query()->where('suppression_reason', 'recovered_before_confirmation')->count())->toBe(1);
    }
})->with(['one-off' => [RunResult::Succeeded, 0], 'confirmed' => [RunResult::Errored, 1]]);

it('does not let a different problem confirm an alert and waits for the new problem to repeat', function (): void {
    $check = hourlyDnsCheck();
    sensitivityRun($check, RunResult::Errored);
    $this->travelTo($check->refresh()->next_run_at);
    // The re-run fails differently: the Check error is not confirmed, and the Failed result needs its own re-run.
    sensitivityRun($check, RunResult::Failed);
    expect($check->refresh()->next_run_at->toIso8601String())->toBe('2026-10-28T12:20:00+00:00');
    $this->travel(5)->minutes();
    dispatchNotifications();
    Http::assertNothingSent();
    expect(Delivery::query()->where('suppression_reason', 'recovered_before_confirmation')->count())->toBe(1);

    $this->travelTo($check->next_run_at);
    sensitivityRun($check, RunResult::Failed);
    $this->travel(1)->minutes();
    dispatchNotifications();
    Http::assertSentCount(1);
    // The confirmed Failed alert also carries the recovery.
    sensitivityRun($check, RunResult::Succeeded);
    $this->travel(1)->minutes();
    dispatchNotifications();
    Http::assertSentCount(2);
});

it('keeps an unconfirmed Check error out of the digest until its confirming run repeats it', function (): void {
    $digest = notificationRoute(notificationWebhook(), [NotificationCategory::CheckError], NotificationMode::Digest);
    notificationSchedule($digest, ['digest_time' => '13:00']);
    $check = hourlyDnsCheck();
    sensitivityRun($check, RunResult::Errored);
    // The confirming run is late: the digest at 13:00 leaves the alert out and keeps it waiting.
    $this->travelTo(CarbonImmutable::parse('2026-10-28T13:00:00Z'));
    dispatchNotifications();
    expect(Event::query()->where('type', 'notification.digest')->count())->toBe(0)
        ->and(Delivery::query()->where('destination_id', $digest->id)->sole()->only(['state', 'suppressed']))->toEqual(['state' => DeliveryState::Pending, 'suppressed' => false]);

    // The confirming run at 13:00 repeats the Check error: the digest due now carries it.
    sensitivityRun($check, RunResult::Errored);
    expect(Event::query()->where('type', 'notification.digest')->count())->toBe(1);
});
