<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Addons\Uptime\UptimeEvidence;
use Heptapod\Addons\Uptime\UptimeMetrics;
use Heptapod\Checks\Recording\RecordableRun;
use Heptapod\Checks\Recording\TransactionalResultRecorder;
use Heptapod\Contracts\CheckOutcome;
use Heptapod\Contracts\RunResult;
use Heptapod\Enums\NotificationCategory;
use Heptapod\Models\Check;
use Heptapod\Models\Incident;
use Heptapod\Testing\ClaimedRun;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../Support/NotificationChannels.php';

function recordEpisodeRun(Check $check, RunResult $result, CarbonImmutable $at): void
{
    test()->travelTo($at);
    $curl = array_fill_keys(['namelookup_time', 'connect_time', 'appconnect_time', 'starttransfer_time', 'pretransfer_time', 'redirect_time', 'total_time', 'header_size', 'size_download', 'speed_download'], null);
    $context = ClaimedRun::start($check, $at->subSecond());
    app(TransactionalResultRecorder::class)->record(new RecordableRun($check->id, $context->claimToken, $context->startedAt, $at,
        new CheckOutcome($result, 'uptime.'.$result->value, [], new UptimeMetrics(null, null, null, null, null, 0.2, $curl, $at->toIso8601String()),
            new UptimeEvidence($result === RunResult::Failed ? 503 : 200, $result === RunResult::Failed ? ['status'] : []))));
}

// Reef 2026-11-13: one failed run weeks after a confirmed recovery must not pull the next outage into the old episode.
it('starts a fresh alert episode after a confirmed recovery even when a single failure precedes the new outage', function (array $afterResolve, bool $joinsOldEpisode): void {
    Http::preventStrayRequests();
    Http::fake(['https://receiver.test/alerts' => Http::response([], 200)]);
    notificationRule(notificationWebhook());
    $check = Check::factory()->create(['failure_threshold' => 2]);
    $check->site->update(['alert_after_minutes' => 5, 'alert_recovery_checks' => 3]);
    $start = CarbonImmutable::parse('2026-10-20T21:08:00Z');
    foreach ([RunResult::Failed, RunResult::Failed, RunResult::Succeeded] as $minute => $result) {
        recordEpisodeRun($check, $result, $start->addMinutes($minute));
    }
    $at = $start->addMinutes(3);
    foreach ($afterResolve as $result) {
        recordEpisodeRun($check, $result, $at);
        $at = $at->addMinute();
    }
    $at = CarbonImmutable::parse('2026-11-13T19:49:00Z');
    foreach ([RunResult::Failed, RunResult::Succeeded, RunResult::Failed, RunResult::Failed] as $minute => $result) {
        recordEpisodeRun($check, $result, $at->addMinutes($minute));
    }
    // An outage starts when its first failed run started, one second before it ended.
    $firstFailure = $at->addMinutes(2)->subSecond();
    $outage = Incident::query()->where('check_id', $check->id)->latest('id')->firstOrFail();
    expect(Incident::query()->where('check_id', $check->id)->count())->toBe(2)
        ->and($outage->alert_episode_started_at->toIso8601String())->toBe(($joinsOldEpisode ? $start->subSecond() : $firstFailure)->toIso8601String());

    dispatchNotifications();
    Http::assertSentCount($joinsOldEpisode ? 1 : 0);
    $this->travelTo($firstFailure->addMinutes(4));
    dispatchNotifications();
    Http::assertSentCount(1);
})->with([
    'three good checks confirm the recovery' => [[RunResult::Succeeded, RunResult::Succeeded], false],
    'many good checks confirm the recovery' => [array_fill(0, 30, RunResult::Succeeded), false],
    'a failure before the third good check keeps the episode' => [[RunResult::Succeeded, RunResult::Failed, RunResult::Succeeded], true],
]);

it('holds a due recovery alert while a good check is missing and sends it with the last one', function (): void {
    Http::preventStrayRequests();
    Http::fake(['https://receiver.test/alerts' => Http::response([], 200)]);
    notificationRoute(notificationWebhook(), [NotificationCategory::Outage, NotificationCategory::Recovery]);
    $check = Check::factory()->create(['failure_threshold' => 1]);
    $check->site->update(['alert_after_minutes' => 2, 'alert_recovery_checks' => 3]);
    $at = CarbonImmutable::parse('2026-11-13T19:49:00Z');
    foreach ([RunResult::Failed, RunResult::Failed, RunResult::Failed, RunResult::Succeeded, RunResult::Succeeded] as $minute => $result) {
        recordEpisodeRun($check, $result, $at->addMinutes($minute));
        dispatchNotifications();
    }
    // The recovery is due two intervals after the resolve, but the third good check is late.
    $this->travelTo($at->addMinutes(6));
    dispatchNotifications();
    Http::assertSentCount(1);
    recordEpisodeRun($check, RunResult::Succeeded, $at->addMinutes(6)->addSeconds(30));
    Http::assertSentCount(2);
});
