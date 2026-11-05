<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Actions\PauseSite;
use Heptapod\Actions\RequestRoutingReconciliation;
use Heptapod\Addons\Uptime\UptimeMetrics;
use Heptapod\Checks\Recording\RecordableRun;
use Heptapod\Checks\Recording\ResultRecorder;
use Heptapod\Contracts\CheckError;
use Heptapod\Contracts\CheckOutcome;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Contracts\RunResult;
use Heptapod\Enums\ClientScope;
use Heptapod\Enums\DeliveryOutcome;
use Heptapod\Enums\NotificationCategory;
use Heptapod\Jobs\DispatchNotifications;
use Heptapod\Models\Check;
use Heptapod\Models\CheckSnooze;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\User;
use Heptapod\Queries\IncidentsReader;
use Heptapod\Settings\InstanceSettings;
use Heptapod\Testing\ClaimedRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/../Support/NotificationChannels.php';

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-20T10:00:00Z'));
    config(['mail.default' => 'smtp']);
    $settings = app(InstanceSettings::class);
    $settings->alert_after_minutes = 10;
    $settings->alert_recovery_checks = 3;
    $settings->save();
});

function lateRuleObservation(Check $check, RunResult $result = RunResult::Failed): void
{
    $at = CarbonImmutable::now('UTC');
    $context = ClaimedRun::start($check->fresh(), $at);
    app(ResultRecorder::class)->record(new RecordableRun($check->id, $context->claimToken, $at, $at,
        new CheckOutcome($result, 'uptime.status', [], $result === RunResult::Errored ? null : new UptimeMetrics(null, null, null, null, null, 0.01,
            array_fill_keys(['namelookup_time', 'connect_time', 'appconnect_time', 'starttransfer_time', 'pretransfer_time', 'redirect_time', 'total_time', 'header_size', 'size_download', 'speed_download'], null), '2026-10-20'),
            error: $result === RunResult::Errored ? new CheckError('RuntimeException', 'Controlled check error') : null)));
}

/**
 * A routing change that reaches the check's open outage: the Outage cell turns on (again) for each channel, with the
 * design 2.2 reconciliation request in the same transaction. Saving it twice must not admit a second opener.
 *
 * @param  list<NotificationDestination>  $destinations
 */
function lateRuleSave(Check $check, array $destinations): void
{
    expect($check->site_id)->not->toBeNull();
    foreach ($destinations as $destination) {
        notificationRoute($destination, [NotificationCategory::Outage]);
    }
}

/** Removing a site mute is a design 2.2 trigger, like an old rule that widened its scope to that site. */
function lateRuleUnmute(NotificationDestination $destination, int $siteId): void
{
    DB::transaction(static function () use ($destination, $siteId): void {
        DB::table('notification_site_mutes')->where('destination_id', $destination->id)->where('site_id', $siteId)->delete();
        app(RequestRoutingReconciliation::class)->forChannel($destination, $siteId);
    });
}

it('admits a late rule once at the original episode deadline', function (string $savedAt, bool $previouslyHealthy): void {
    Mail::fake();
    $check = Check::factory()->create(['failure_threshold' => 1]);
    if ($previouslyHealthy) {
        lateRuleObservation($check, RunResult::Succeeded);
        $this->travelTo(CarbonImmutable::parse('2026-10-20T10:01:00Z'));
    }
    $openedAt = CarbonImmutable::now('UTC');
    lateRuleObservation($check);
    dispatchNotifications();
    $opening = Event::query()->where('check_id', $check->id)->where('type', 'incident.opened')->sole();
    expect($opening->notification_routed_at)->not->toBeNull();
    $destination = NotificationDestination::factory()->create();
    $this->travelTo($openedAt->addMinutes((int) $savedAt));

    lateRuleSave($check, [$destination]);
    dispatchNotifications();
    lateRuleSave($check, [$destination]);
    dispatchNotifications();

    expect($opening->fresh()->notification_routed_at->toIso8601String())->toBe($openedAt->toIso8601String());
    $delivery = Delivery::query()->where('event_id', $opening->id)->sole();
    if ((int) $savedAt < 9) {
        expect($delivery->next_attempt_at->toIso8601String())->toBe($openedAt->addMinutes(9)->toIso8601String());
        expect($delivery->state)->toBe(DeliveryState::Pending);
        Mail::assertNothingSent();
        $this->travelTo($openedAt->addMinutes(9));
        dispatchNotifications();
    }
    dispatchNotifications();
    expect($delivery->fresh()->state)->toBe(DeliveryState::Sent);
    expect($delivery->fresh()->sent_at->toIso8601String())->toBe(CarbonImmutable::now('UTC')->toIso8601String());
    expect(Delivery::query()->where('event_id', $opening->id)->count())->toBe(1);
    Mail::assertSentCount(1);
})->with(['before deadline' => ['2', false], 'after deadline' => ['12', false], 'previously healthy' => ['12', true]]);

it('admits only the new recipient when a rule gains a destination', function (): void {
    Mail::fake();
    $check = Check::factory()->create(['failure_threshold' => 1]);
    $first = NotificationDestination::factory()->create();
    $second = NotificationDestination::factory()->create();
    lateRuleSave($check, [$first]);
    lateRuleObservation($check);
    dispatchNotifications();
    $this->travelTo(CarbonImmutable::parse('2026-10-20T10:10:00Z'));
    dispatchNotifications();
    expect(Delivery::query()->where('destination_id', $first->id)->sole()->state)->toBe(DeliveryState::Sent);

    lateRuleSave($check, [$first, $second]);
    dispatchNotifications();
    lateRuleSave($check, [$first, $second]);
    dispatchNotifications();

    expect(Delivery::query()->where('state', 'sent')->count())->toBe(2);
    expect(Delivery::query()->where('destination_id', $first->id)->count())->toBe(1);
    expect(Delivery::query()->where('destination_id', $second->id)->sole()->state)->toBe(DeliveryState::Sent);
    Mail::assertSentCount(2);
});

it('does not admit an opening or recovery when recovery precedes the late rule', function (): void {
    Mail::fake();
    $check = Check::factory()->create(['failure_threshold' => 1]);
    lateRuleObservation($check);
    dispatchNotifications();
    $this->travelTo(CarbonImmutable::parse('2026-10-20T10:02:00Z'));
    lateRuleObservation($check, RunResult::Succeeded);
    $destination = NotificationDestination::factory()->create();

    lateRuleSave($check, [$destination]);
    notificationRule($destination, 'site.recovered');
    $this->travelTo(CarbonImmutable::parse('2026-10-20T10:12:00Z'));
    dispatchNotifications();

    expect(Delivery::query()->count())->toBe(0);
    Mail::assertNothingSent();
});

it('keeps a routing change made during planning for the next dispatcher pass', function (): void {
    Mail::fake();
    $check = Check::factory()->create(['failure_threshold' => 1]);
    lateRuleObservation($check);
    dispatchNotifications();
    $this->travelTo(CarbonImmutable::parse('2026-10-20T10:12:00Z'));
    $first = NotificationDestination::factory()->create();
    $second = NotificationDestination::factory()->create();
    lateRuleSave($check, [$first]);
    $changed = false;
    // Interleave a real save after routes were read, before the request is cleared.
    Delivery::created(function (Delivery $delivery) use ($check, $first, $second, &$changed): void {
        if (! $changed && $delivery->destination_id === $first->id) {
            $changed = true;
            lateRuleSave($check, [$first, $second]);
        }
    });

    dispatchNotifications();

    expect($changed)->toBeTrue();
    expect($check->incidents()->sole()->routing_reconcile_requested_at)->not->toBeNull();
    expect(Delivery::query()->where('destination_id', $second->id)->count())->toBe(0);
    dispatchNotifications();
    expect($check->incidents()->sole()->routing_reconcile_requested_at)->toBeNull();
    expect(Delivery::query()->where('destination_id', $second->id)->sole()->state)->toBe(DeliveryState::Sent);
    Mail::assertSentCount(2);
});

it('clears a saved routing request without sending if the incident recovers before dispatch', function (): void {
    Mail::fake();
    $check = Check::factory()->create(['failure_threshold' => 1]);
    lateRuleObservation($check);
    dispatchNotifications();
    lateRuleSave($check, [NotificationDestination::factory()->create()]);
    expect($check->incidents()->sole()->routing_reconcile_requested_at)->not->toBeNull();
    $this->travelTo(CarbonImmutable::parse('2026-10-20T10:02:00Z'));
    lateRuleObservation($check, RunResult::Succeeded);

    dispatchNotifications();

    expect($check->incidents()->sole()->routing_reconcile_requested_at)->toBeNull();
    expect(Delivery::query()->count())->toBe(0);
    Mail::assertNothingSent();
});

it('does not admit work after monitoring stops', function (): void {
    Mail::fake();
    $check = Check::factory()->create(['failure_threshold' => 1]);
    lateRuleObservation($check);
    dispatchNotifications();
    lateRuleSave($check, [NotificationDestination::factory()->create()]);
    app(PauseSite::class)($check->site);
    $this->travelTo(CarbonImmutable::parse('2026-10-20T10:12:00Z'));

    dispatchNotifications();

    expect($check->incidents()->sole()->routing_reconcile_requested_at)->toBeNull();
    expect(Delivery::query()->count())->toBe(0);
    Mail::assertNothingSent();
});

it('defers a newly admitted opener through a check snooze without restarting its delay', function (): void {
    Mail::fake();
    $check = Check::factory()->create(['failure_threshold' => 1]);
    lateRuleObservation($check);
    dispatchNotifications();
    $this->travelTo(CarbonImmutable::parse('2026-10-20T10:12:00Z'));
    CheckSnooze::factory()->create(['check_id' => $check->id, 'starts_at' => now(), 'ends_at' => now()->addMinutes(3)]);
    lateRuleSave($check, [NotificationDestination::factory()->create()]);

    dispatchNotifications();

    $delivery = Delivery::query()->sole();
    expect($delivery->state)->toBe(DeliveryState::Pending);
    expect($delivery->last_reason)->toBe('deferred_by_snooze');
    expect($delivery->next_attempt_at->toIso8601String())->toBe('2026-10-20T10:15:00+00:00');
    Mail::assertNothingSent();
    $this->travelTo(CarbonImmutable::parse('2026-10-20T10:15:00Z'));
    dispatchNotifications();
    dispatchNotifications();
    expect($delivery->fresh()->state)->toBe(DeliveryState::Sent);
    Mail::assertSentCount(1);
});

it('keeps maintenance catch-up ownership for a late route', function (bool $catchUp): void {
    Mail::fake();
    $check = Check::factory()->create(['failure_threshold' => 1]);
    lateRuleObservation($check);
    dispatchNotifications();
    $this->travelTo(CarbonImmutable::parse('2026-10-20T10:12:00Z'));
    MaintenancePeriod::factory()->create(['site_id' => $check->site_id, 'target_type' => 'site', 'target_id' => $check->site_id,
        'starts_at' => now(), 'ends_at' => now()->addMinutes(3), 'mute_alerts' => true, 'catch_up_at_end' => $catchUp]);
    lateRuleSave($check, [NotificationDestination::factory()->create()]);

    dispatchNotifications();

    $openingDelivery = Delivery::query()->sole();
    expect($openingDelivery->last_reason)->toBe('deferred_by_maintenance');
    Mail::assertNothingSent();
    $this->travelTo(CarbonImmutable::parse('2026-10-20T10:15:00Z'));
    dispatchNotifications();
    dispatchNotifications();
    expect($openingDelivery->fresh()->suppression_reason)->toBe('superseded_by_maintenance_catch_up');
    $sent = Delivery::query()->where('state', 'sent')->sole();
    expect($sent->event->type)->toBe($catchUp ? 'maintenance.catch_up' : 'incident.still_down');
    Mail::assertSentCount(1);
})->with(['catch-up enabled' => true, 'catch-up disabled' => false]);

it('does not unmute an opening recorded during maintenance when a late rule is saved', function (): void {
    Mail::fake();
    $check = Check::factory()->create(['failure_threshold' => 1]);
    MaintenancePeriod::factory()->create(['site_id' => $check->site_id, 'target_type' => 'site', 'target_id' => $check->site_id,
        'starts_at' => now()->subMinute(), 'ends_at' => now()->addMinutes(15), 'mute_alerts' => true, 'catch_up_at_end' => true]);
    lateRuleObservation($check);
    dispatchNotifications();
    $this->travelTo(CarbonImmutable::parse('2026-10-20T10:12:00Z'));
    lateRuleSave($check, [NotificationDestination::factory()->create()]);

    dispatchNotifications();

    expect(Delivery::query()->count())->toBe(0);
    Mail::assertNothingSent();
    $this->travelTo(CarbonImmutable::parse('2026-10-20T10:15:00Z'));
    dispatchNotifications();
    dispatchNotifications();
    expect(Delivery::query()->where('state', 'sent')->sole()->event->type)->toBe('maintenance.catch_up');
    Mail::assertSentCount(1);
});

it('does not send a late opener to a personal recipient whose client access was revoked', function (): void {
    Mail::fake();
    $check = Check::factory()->create(['failure_threshold' => 1]);
    lateRuleObservation($check);
    dispatchNotifications();
    $user = User::factory()->member()->create(['client_scope' => ClientScope::Restricted]);
    $user->clients()->sync([$check->site->client_id]);
    $destination = notificationAccountChannel($user, []);
    lateRuleSave($check, [$destination]);
    dispatchNotifications();
    $user->clients()->detach();
    $this->travelTo(CarbonImmutable::parse('2026-10-20T10:12:00Z'));

    dispatchNotifications();

    expect(Delivery::query()->sole()->suppression_reason)->toBe('personal_site_access_revoked');
    Mail::assertNothingSent();
});

it('admits a recipient when a removed site mute reaches the open outage', function (): void {
    Mail::fake();
    $check = Check::factory()->create(['failure_threshold' => 1]);
    $other = Check::factory()->create(['failure_threshold' => 1]);
    $destination = NotificationDestination::factory()->create();
    notificationMuteSite($destination, $check->site_id);
    lateRuleSave($other, [$destination]);
    lateRuleObservation($check);
    dispatchNotifications();
    $this->travelTo(CarbonImmutable::parse('2026-10-20T10:12:00Z'));

    lateRuleUnmute($destination, $check->site_id);
    dispatchNotifications();

    expect(Delivery::query()->where('state', 'sent')->sole()->event->check_id)->toBe($check->id);
    Mail::assertSentCount(1);
});

it('does not send a second opener to an already alerted recipient in a continued episode', function (): void {
    Mail::fake();
    $check = Check::factory()->create(['failure_threshold' => 1]);
    $destination = NotificationDestination::factory()->create();
    lateRuleSave($check, [$destination]);
    lateRuleObservation($check);
    dispatchNotifications();
    $this->travelTo(CarbonImmutable::parse('2026-10-20T10:09:00Z'));
    dispatchNotifications();
    $this->travelTo(CarbonImmutable::parse('2026-10-20T10:10:00Z'));
    lateRuleObservation($check, RunResult::Succeeded);
    dispatchNotifications();
    $this->travelTo(CarbonImmutable::parse('2026-10-20T10:11:00Z'));
    lateRuleObservation($check);
    dispatchNotifications();

    lateRuleSave($check, [$destination]);
    dispatchNotifications();
    dispatchNotifications();

    expect($check->incidents()->count())->toBe(2);
    expect($check->incidents()->orderByDesc('id')->first()->alert_episode_started_at->toIso8601String())->toBe('2026-10-20T10:00:00+00:00');
    expect(Delivery::query()->where('state', 'sent')->count())->toBe(1);
    Mail::assertSentCount(1);
});

it('shows the saved deadline in the incident delivery panel for a late rule', function (): void {
    Mail::fake();
    $check = Check::factory()->create(['failure_threshold' => 1]);
    lateRuleObservation($check);
    dispatchNotifications();
    $this->travelTo(CarbonImmutable::parse('2026-10-20T10:02:00Z'));
    $destination = NotificationDestination::factory()->create();
    lateRuleSave($check, [$destination]);
    dispatchNotifications();
    $owner = User::factory()->owner()->create();

    $rows = app(IncidentsReader::class)->deliveries($owner, $check->incidents()->sole());
    expect($rows)->toHaveCount(1);
    expect($rows[0]->toArray())->toMatchArray(['state' => 'pending', 'scheduled_at' => '2026-10-20T10:09:00+00:00']);
    Mail::assertNothingSent();
    $this->travelTo(CarbonImmutable::parse('2026-10-20T10:09:00Z'));
    dispatchNotifications();
    Mail::assertSentCount(1);
});

it('admits exactly one catch-up for a rule saved after a muted window was processed without recipients', function (int $windowMinutes, int $ruleMinute, bool $catchUp): void {
    Mail::fake();
    $check = Check::factory()->create(['failure_threshold' => 1]);
    $window = MaintenancePeriod::factory()->create(['site_id' => $check->site_id, 'target_type' => 'site', 'target_id' => $check->site_id,
        'starts_at' => now()->subMinute(), 'ends_at' => now()->addMinutes($windowMinutes), 'mute_alerts' => true, 'catch_up_at_end' => $catchUp]);
    lateRuleObservation($check);
    dispatchNotifications();
    $opening = Event::query()->where('check_id', $check->id)->where('type', 'incident.opened')->sole();
    $this->travelTo(CarbonImmutable::parse('2026-10-20T10:00:00Z')->addMinutes($windowMinutes));
    dispatchNotifications();
    expect(Delivery::query()->count())->toBe(0);
    if ($catchUp) {
        expect($window->fresh()->catch_up_processed_at)->not->toBeNull();
    }
    $destination = NotificationDestination::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-20T10:00:00Z')->addMinutes($ruleMinute));

    lateRuleSave($check, [$destination]);
    dispatchNotifications();
    lateRuleSave($check, [$destination]);
    dispatchNotifications();

    if ($ruleMinute < 9) {
        expect(Delivery::query()->sole()->next_attempt_at->toIso8601String())->toBe('2026-10-20T10:09:00+00:00');
        Mail::assertNothingSent();
        $this->travelTo(CarbonImmutable::parse('2026-10-20T10:09:00Z'));
        dispatchNotifications();
    }
    lateRuleSave($check, [$destination]);
    dispatchNotifications();
    expect(Delivery::query()->where('state', 'sent')->count())->toBe(1);
    expect(Delivery::query()->where('state', 'sent')->sole()->event->type)->toBe($catchUp ? 'maintenance.catch_up' : 'incident.still_down');
    expect($opening->fresh()->suppressed)->toBeTrue();
    expect($opening->fresh()->suppression_reason)->toBe('maintenance');
    Mail::assertSentCount(1);
})->with(['after deadline' => [10, 12, true], 'before deadline' => [2, 3, true], 'no catch-up policy' => [10, 12, false]]);

it('keeps the pre-existing-rule no-catch-up policy for an outage opened during check snooze', function (bool $late): void {
    Mail::fake();
    $check = Check::factory()->create(['failure_threshold' => 1]);
    $destination = NotificationDestination::factory()->create();
    if (! $late) {
        lateRuleSave($check, [$destination]);
    }
    CheckSnooze::factory()->create(['check_id' => $check->id, 'starts_at' => now()->subMinute(), 'ends_at' => now()->addMinutes(3)]);
    lateRuleObservation($check);
    dispatchNotifications();
    $this->travelTo(CarbonImmutable::parse('2026-10-20T10:12:00Z'));
    if ($late) {
        lateRuleSave($check, [$destination]);
    }

    dispatchNotifications();
    dispatchNotifications();

    expect(Delivery::query()->count())->toBe(0);
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner)->get(route('incidents.show', $check->incidents()->sole()->id))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('opening_delivery_explanation', 'notifications.opening_snoozed_no_catch_up'));
    Mail::assertNothingSent();
})->with(['pre-existing rule' => false, 'late rule' => true]);

it('uses the eligible episode opening instead of a muted cause change', function (bool $late, string $mute): void {
    Mail::fake();
    $check = Check::factory()->create(['failure_threshold' => 1]);
    $destination = NotificationDestination::factory()->create();
    if (! $late) {
        lateRuleSave($check, [$destination]);
    }
    lateRuleObservation($check);
    dispatchNotifications();
    $this->travelTo(CarbonImmutable::parse('2026-10-20T10:02:00Z'));
    if ($mute === 'maintenance') {
        MaintenancePeriod::factory()->create(['site_id' => $check->site_id, 'target_type' => 'site', 'target_id' => $check->site_id,
            'starts_at' => now(), 'ends_at' => now()->addMinutes(10), 'mute_alerts' => true, 'catch_up_at_end' => true]);
    } else {
        CheckSnooze::factory()->create(['check_id' => $check->id, 'starts_at' => now(), 'ends_at' => now()->addMinutes(10)]);
    }
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    $this->travelTo(CarbonImmutable::parse('2026-10-20T10:03:00Z'));
    lateRuleObservation($check, RunResult::Errored);
    dispatchNotifications();
    $this->travelTo(CarbonImmutable::parse('2026-10-20T10:04:00Z'));
    lateRuleObservation($check);
    dispatchNotifications();
    $this->travelTo(CarbonImmutable::parse('2026-10-20T10:09:00Z'));
    dispatchNotifications();
    Mail::assertNothingSent();
    $incident = $check->incidents()->sole();
    $this->get(route('incidents.show', $incident->id))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('opening_delivery_explanation', null));
    if (! $late) {
        expect(app(IncidentsReader::class)->deliveries($owner, $incident)[0]->state)->toBe(DeliveryOutcome::Pending);
    }
    $this->travelTo(CarbonImmutable::parse('2026-10-20T10:13:00Z'));
    if ($late) {
        lateRuleSave($check, [$destination]);
    }

    dispatchNotifications();
    dispatchNotifications();

    expect(Delivery::query()->where('state', 'sent')->count())->toBe(1);
    expect(Event::query()->where('type', 'incident.cause_changed')->where('suppressed', true)->where('suppression_reason', $mute)->count())->toBe(2);
    expect(Delivery::query()->where('state', 'sent')->sole()->event->type)->not->toBe('incident.cause_changed');
    $this->get(route('incidents.show', $incident->id))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('opening_delivery_explanation', null));
    expect(app(IncidentsReader::class)->deliveries($owner, $incident)[0]->state)->toBe(DeliveryOutcome::Accepted);
    Mail::assertSentCount(1);
})->with(['maintenance early' => [false, 'maintenance'], 'maintenance late' => [true, 'maintenance'],
    'snooze early' => [false, 'snooze'], 'snooze late' => [true, 'snooze']]);

it('does not give a late recipient a Recovered alert based on another recipients accepted opener', function (): void {
    Mail::fake();
    $check = Check::factory()->create(['failure_threshold' => 1]);
    $first = NotificationDestination::factory()->create();
    $late = NotificationDestination::factory()->create();
    lateRuleSave($check, [$first]);
    notificationRule($first, 'site.recovered');
    notificationRule($late, 'site.recovered');
    lateRuleObservation($check);
    dispatchNotifications();
    $this->travelTo(CarbonImmutable::parse('2026-10-20T10:09:00Z'));
    dispatchNotifications();
    $this->travelTo(CarbonImmutable::parse('2026-10-20T10:10:00Z'));
    lateRuleSave($check, [$first, $late]);
    foreach (['10:11:00', '10:12:00', '10:13:00'] as $time) {
        $this->travelTo(CarbonImmutable::parse('2026-10-20T'.$time.'Z'));
        lateRuleObservation($check, RunResult::Succeeded);
        dispatchNotifications();
    }

    expect(Delivery::query()->where('destination_id', $late->id)->count())->toBe(0);
    expect(Delivery::query()->where('destination_id', $first->id)->where('state', 'sent')->count())->toBe(2);
    Mail::assertSentCount(2);
});

it('keeps the no-catch-up no-reminder policy for an outage opened muted when reminders are off', function (bool $late): void {
    Mail::fake();
    config(['notifications.renotify_intervals_seconds' => null]);
    $check = Check::factory()->create(['failure_threshold' => 1]);
    $destination = NotificationDestination::factory()->create();
    if (! $late) {
        lateRuleSave($check, [$destination]);
    }
    MaintenancePeriod::factory()->create(['site_id' => $check->site_id, 'target_type' => 'site', 'target_id' => $check->site_id,
        'starts_at' => now()->subMinute(), 'ends_at' => now()->addMinutes(3), 'mute_alerts' => true, 'catch_up_at_end' => false]);
    lateRuleObservation($check);
    dispatchNotifications();
    $this->travelTo(CarbonImmutable::parse('2026-10-20T10:12:00Z'));
    if ($late) {
        lateRuleSave($check, [$destination]);
    }

    dispatchNotifications();
    dispatchNotifications();

    expect(Delivery::query()->count())->toBe(0);
    expect(app(IncidentsReader::class)->openingDeliveryExplanation($check->incidents()->sole()))->toBe('notifications.preview_maintenance_catch_up_disabled');
    Mail::assertNothingSent();
})->with(['pre-existing rule' => false, 'late rule' => true]);

it('keeps a users opener when a team channel is turned on before the first planning pass', function (): void {
    Mail::fake();
    Queue::fake([DispatchNotifications::class]);
    $check = Check::factory()->create(['failure_threshold' => 1]);
    $member = User::factory()->member()->create();
    $personal = notificationAccountChannel($member, [NotificationCategory::Outage]);
    lateRuleObservation($check);
    $opening = Event::query()->where('check_id', $check->id)->where('type', 'incident.opened')->sole();
    expect($opening->notification_routed_at)->toBeNull();
    Queue::assertPushed(DispatchNotifications::class);
    $team = NotificationDestination::factory()->create();
    lateRuleSave($check, [$team]);
    $this->travelTo(CarbonImmutable::parse('2026-10-20T10:09:00Z'));

    dispatchNotifications();
    dispatchNotifications();

    $followerDelivery = Delivery::query()->where('event_id', $opening->id)->where('destination_id', $personal->id)->sole();
    expect($followerDelivery->state)->toBe(DeliveryState::Sent);
    expect(Delivery::query()->where('event_id', $opening->id)->where('destination_id', $team->id)->sole()->state)->toBe(DeliveryState::Sent);
    expect(Delivery::query()->where('destination_id', $personal->id)->count())->toBe(1);
    expect($opening->fresh()->notification_routed_at->toIso8601String())->toBe('2026-10-20T10:09:00+00:00');
    Mail::assertSentCount(2);
});
