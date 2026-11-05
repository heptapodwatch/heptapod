<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Addons\Uptime\UptimeMetrics;
use Heptapod\Checks\Recording\RecordableRun;
use Heptapod\Checks\Recording\ResultRecorder;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckOutcome;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\RunResult;
use Heptapod\Enums\ClientScope;
use Heptapod\Enums\NotificationCategory;
use Heptapod\Models\Check;
use Heptapod\Models\CheckSnooze;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\User;
use Heptapod\Settings\InstanceSettings;
use Heptapod\Testing\ClaimedRun;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/NotificationChannels.php';

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05T00:30:00Z'));
    config(['mail.default' => 'smtp']);
    Http::preventStrayRequests();
    // These cases test reminder cadence, not the alert delay: one minute makes the uptime opener due at once.
    $settings = app(InstanceSettings::class);
    $settings->alert_after_minutes = 1;
    $settings->save();
});

it('offers cleanup of a legacy cron reminder snooze without offering creation', function (string $role): void {
    $user = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $user->assignRole($role);
    $check = Check::factory()->create(['type' => CheckKey::Cron, 'latest_run_result' => CheckResult::Failed]);
    $user->clients()->attach($check->site->client_id);
    $incident = Incident::factory()->for($check)->create(['snoozed_until' => now()->addHour()]);
    $this->actingAs($user);

    $this->get(route('incidents.show', $incident))->assertInertia(fn (Assert $page): Assert => $page
        ->where('can_snooze', false)->where('can_unsnooze', true));
    $this->delete(route('incidents.unsnooze', $incident))->assertRedirect();
    expect($incident->fresh()->snoozed_until)->toBeNull();
    $this->get(route('incidents.show', $incident))->assertInertia(fn (Assert $page): Assert => $page
        ->where('can_snooze', false)->where('can_unsnooze', false));
})->with(['Owner', 'Admin', 'Member']);

it('does not offer cleanup for an expired cron reminder snooze', function (): void {
    $user = User::factory()->owner()->create();
    $check = Check::factory()->create(['type' => CheckKey::Cron]);
    $incident = Incident::factory()->for($check)->create(['snoozed_until' => now()]);
    $this->actingAs($user);

    $this->get(route('incidents.show', $incident))->assertInertia(fn (Assert $page): Assert => $page
        ->where('can_snooze', false)->where('can_unsnooze', false));
});

it('cannot inspect or clear a legacy reminder snooze outside the visible client scope', function (): void {
    $user = User::factory()->member()->create(['client_scope' => ClientScope::Restricted]);
    $incident = Incident::factory()->for(Check::factory()->create(['type' => CheckKey::Cron]))
        ->create(['snoozed_until' => now()->addHour()]);
    $until = $incident->snoozed_until;
    $this->actingAs($user);

    $this->get(route('incidents.show', $incident))->assertNotFound();
    $this->delete(route('incidents.unsnooze', $incident))->assertNotFound();
    expect($incident->fresh()->snoozed_until)->toEqual($until);
});

function reminderIncidentEvent(): Event
{
    $check = Check::factory()->create(['failure_threshold' => 1]);
    $context = ClaimedRun::start($check, CarbonImmutable::now('UTC')->subSecond());
    app(ResultRecorder::class)->record(new RecordableRun(
        $check->id, $context->claimToken, $context->startedAt, CarbonImmutable::now('UTC'),
        new CheckOutcome(RunResult::Failed, 'uptime.status', [],
            new UptimeMetrics(503, null, null, null, null, 0.01,
                array_fill_keys(['namelookup_time', 'connect_time', 'appconnect_time', 'starttransfer_time', 'pretransfer_time', 'redirect_time', 'total_time', 'header_size', 'size_download', 'speed_download'], null), '2026-10-05')),
    ));

    return Event::query()->where('check_id', $check->id)->where('type', 'incident.opened')->sole();
}

it('emits reminders after one hour then four hours then daily with fresh snooze snapshots and global off', function (): void {
    Http::preventStrayRequests();
    Http::fake(['https://receiver.test/alerts' => Http::response([], 200)]);
    notificationRule(notificationWebhook());
    $event = reminderIncidentEvent();
    $event->check->update(['latest_run_result' => CheckResult::Failed]);
    dispatchNotifications();
    $this->travel(59)->minutes();
    dispatchNotifications();
    expect(Event::query()->where('type', 'incident.still_down')->count())->toBe(0);
    CheckSnooze::factory()->create(['check_id' => $event->check_id, 'starts_at' => now(), 'ends_at' => now()->addHour()]);
    $this->travel(1)->minutes();
    dispatchNotifications();
    dispatchNotifications();
    $reminder = Event::query()->where('type', 'incident.still_down')->sole();
    expect($reminder->suppressed)->toBeTrue()->and($reminder->suppression_reason)->not->toBeNull();
    foreach (['2026-10-05T05:29:59Z' => 1, '2026-10-05T05:30:00Z' => 2,
        '2026-10-06T05:29:59Z' => 2, '2026-10-06T05:30:00Z' => 3,
        '2026-10-07T05:29:59Z' => 3, '2026-10-07T05:30:00Z' => 4] as $at => $count) {
        $this->travelTo(CarbonImmutable::parse($at));
        dispatchNotifications();
        dispatchNotifications();
        expect(Event::query()->where('type', 'incident.still_down')->count())->toBe($count);
    }
    config(['notifications.renotify_intervals_seconds' => null]);
    $this->travel(2)->days();
    dispatchNotifications();
    expect(Event::query()->where('type', 'incident.still_down')->count())->toBe(4);
    Http::assertSentCount(4);
});

it('sends the first outage reminder during personal quiet but stops due reminders during incident snooze', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-03T00:30:00Z'));
    Mail::fake();
    $user = User::factory()->member()->create(['timezone' => 'Europe/Berlin']);
    $destination = notificationAccountChannel($user, [NotificationCategory::Outage]);
    notificationSchedule($destination, ['quiet_start' => '22:00', 'quiet_end' => '07:00', 'quiet_weekends' => true, 'digest_time' => '08:00']);
    $event = reminderIncidentEvent();
    $event->check->update(['latest_run_result' => CheckResult::Failed]);
    dispatchNotifications();
    $this->travel(1)->hours();
    dispatchNotifications();
    expect(Delivery::query()->where('state', 'sent')->count())->toBe(2);
    Mail::assertSentCount(2);
    $event->incident->update(['snoozed_until' => now()->addHours(6)]);
    $this->travel(4)->hours();
    dispatchNotifications();
    expect(Delivery::query()->where('state', 'sent')->count())->toBe(2);
    Mail::assertSentCount(2);
});
