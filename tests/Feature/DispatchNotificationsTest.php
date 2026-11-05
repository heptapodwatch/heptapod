<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Actions\ClaimNotificationDelivery;
use Heptapod\Actions\CompleteNotificationDelivery;
use Heptapod\Actions\PlanNotificationEvent;
use Heptapod\Addons\Channels\TemplateMail;
use Heptapod\Addons\Cron\CronDefinitions;
use Heptapod\Addons\Uptime\UptimeMetrics;
use Heptapod\Checks\Recording\RecordableRun;
use Heptapod\Checks\Recording\ResultRecorder;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckOutcome;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\ChromiumRenderer;
use Heptapod\Contracts\ExternalTimeoutException;
use Heptapod\Contracts\Notifications\ChannelRegistry;
use Heptapod\Contracts\Notifications\DeliveryResult;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Contracts\RunResult;
use Heptapod\Enums\NotificationMode;
use Heptapod\Jobs\SendNotification;
use Heptapod\Models\Check;
use Heptapod\Models\CheckSnooze;
use Heptapod\Models\Client;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Settings\InstanceSettings;
use Heptapod\Testing\ClaimedRun;
use Heptapod\Testing\FakeChromiumRenderer;
use Illuminate\Database\QueryException;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;
use Tests\Support\QueryPlanWork;

require_once __DIR__.'/../../addons/lighthouse/tests/Pest.php';

require_once __DIR__.'/../Support/NotificationChannels.php';

// Provider outcomes, not the local log/array mailer: phpunit's array mailer now records mail as logged (8e3a8e4e).
beforeEach(function (): void {
    config(['mail.default' => 'smtp']);
});

it('adds every channel that covers the site and lets a site mute silence one channel', function (): void {
    Http::preventStrayRequests();
    Http::fake(['https://receiver.test/alerts' => Http::response([], 200)]);
    $event = notificationIncidentEvent();
    $site = $event->site;
    $all = notificationRule(notificationWebhook());
    $client = notificationWebhook();
    $client->update(['scope_client_id' => $site->client_id]);
    notificationRule($client);
    $otherClient = notificationWebhook();
    $otherClient->update(['scope_client_id' => Client::factory()->create()->id]);
    notificationRule($otherClient);
    $muted = notificationRule(notificationWebhook());
    dispatchNotifications();
    expect(Delivery::query()->where('event_id', $event->id)->where('state', 'sent')->pluck('destination_id')->all())
        ->toEqualCanonicalizing([$all->id, $client->id, $muted->id]);

    notificationMuteSite($muted, $site->id);
    $second = Event::factory()->create(['site_id' => $site->id, 'check_id' => $event->check_id, 'type' => 'incident.opened', 'cause' => 'failed', 'to_result' => CheckResult::Failed]);
    dispatchNotifications();
    expect(Delivery::query()->where('event_id', $second->id)->pluck('destination_id')->all())->toEqualCanonicalizing([$all->id, $client->id]);
    Http::assertSentCount(5);
});

it('never sends a resolution without a successfully sent opener for that recipient', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05T00:30:00Z'));
    config(['notifications.renotify_intervals_seconds' => null]);
    Http::preventStrayRequests();
    Http::fake(['https://receiver.test/alerts' => Http::sequence()->push([], 503)->push([], 200)->push([], 200)]);
    $destination = notificationWebhook();
    notificationRule($destination, 'site.down');
    notificationRule($destination, 'site.recovered');
    $opened = notificationIncidentEvent();
    dispatchNotifications();
    expect(Delivery::query()->where('event_id', $opened->id)->sole()->state)->toBe(DeliveryState::Failed);
    $resolved = Event::factory()->create(['site_id' => $opened->site_id, 'check_id' => $opened->check_id,
        'incident_id' => $opened->incident_id, 'type' => 'incident.resolved', 'cause' => 'failed',
        'from_result' => CheckResult::Failed, 'to_result' => CheckResult::Succeeded]);
    dispatchNotifications();
    expect(Delivery::query()->where('event_id', $resolved->id)->sole()->state)->toBe(DeliveryState::Pending);
    Http::assertSentCount(1);
    $this->travel(1)->minutes();
    dispatchNotifications();
    expect(Delivery::query()->where('event_id', $resolved->id)->sole()->state)->toBe(DeliveryState::Sent);
    Http::assertSentCount(3);
});

it('does not recover a warning before its matching opening alert is accepted', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05T00:30:00Z'));
    $settings = app(InstanceSettings::class);
    $settings->timezone = 'Europe/Berlin';
    $settings->save();
    Mail::fake();
    $destination = NotificationDestination::factory()->create();
    notificationRule($destination, 'check.warning', NotificationMode::Digest);
    notificationRule($destination, 'check.recovered');
    $warning = Event::factory()->create(['from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Warning]);
    $warning->check->update(['latest_run_result' => CheckResult::Warning]);
    dispatchNotifications();
    expect(Delivery::query()->where('event_id', $warning->id)->sole()->state)->toBe(DeliveryState::Pending);
    $recovery = Event::factory()->create(['site_id' => $warning->site_id, 'check_id' => $warning->check_id,
        'from_result' => CheckResult::Warning, 'to_result' => CheckResult::Succeeded]);
    $warning->check->update(['latest_run_result' => CheckResult::Succeeded]);
    dispatchNotifications();
    expect(Delivery::query()->where('event_id', $recovery->id)->count())->toBe(0);
    Mail::assertNothingSent();
});

it('sends a warning recovery only after its daily digest was accepted', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05T07:00:00Z'));
    Mail::fake();
    $destination = NotificationDestination::factory()->create();
    notificationRule($destination, 'check.warning', NotificationMode::Digest);
    notificationRule($destination, 'check.recovered');
    $warning = Event::factory()->create(['from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Warning]);
    $warning->check->update(['latest_run_result' => CheckResult::Warning]);
    dispatchNotifications();
    $this->travel(1)->hours();
    dispatchNotifications();
    expect(Delivery::query()->where('event_id', $warning->id)->sole()->state)->toBe(DeliveryState::Sent);
    $recovery = Event::factory()->create(['site_id' => $warning->site_id, 'check_id' => $warning->check_id,
        'from_result' => CheckResult::Warning, 'to_result' => CheckResult::Succeeded]);
    $warning->check->update(['latest_run_result' => CheckResult::Succeeded]);
    dispatchNotifications();
    expect(Delivery::query()->where('event_id', $recovery->id)->sole()->state)->toBe(DeliveryState::Sent);
    Mail::assertSentCount(2);
});

it('uses the warning recovery title instead of inventing zero downtime in the mail subject', function (): void {
    Mail::fake();
    $destination = NotificationDestination::factory()->create();
    notificationRule($destination, 'check.warning');
    notificationRule($destination, 'check.recovered');
    $warning = Event::factory()->create(['from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Warning]);
    $warning->check->update(['latest_run_result' => CheckResult::Warning]);
    dispatchNotifications();
    $recovery = Event::factory()->create(['site_id' => $warning->site_id, 'check_id' => $warning->check_id,
        'from_result' => CheckResult::Warning, 'to_result' => CheckResult::Succeeded]);
    $warning->check->update(['latest_run_result' => CheckResult::Succeeded]);
    dispatchNotifications();
    $subject = Mail::sent(TemplateMail::class)->last()->envelope()->subject;
    expect($subject)->toStartWith('[Recovered]')->not->toContain('down 0 min');
    Mail::assertSentCount(2);
});

it('replays sent events without resending and stores safe failure diagnosis with due-only exponential retries', function (): void {
    $this->freezeTime();
    Http::preventStrayRequests();
    Http::fake(['https://receiver.test/alerts' => Http::sequence()->push([], 404)->push([], 200)]);
    $destination = notificationWebhook();
    notificationRule($destination);
    $event = notificationIncidentEvent();
    dispatchNotifications();
    $delivery = Delivery::query()->sole();
    expect($delivery->state)->toBe(DeliveryState::Failed)->and($delivery->attempts)->toBe(1);
    expect($delivery->last_diagnosis)->toContain('HTTP 404')->not->toContain('private-webhook-secret');
    expect($delivery->next_attempt_at->toIso8601String())->toBe(now()->addMinute()->toIso8601String());
    dispatchNotifications();
    $this->travel(1)->minutes();
    dispatchNotifications();
    $event->update(['notification_routed_at' => null]);
    dispatchNotifications();
    app()->call(new SendNotification($delivery->id)->handle(...));
    expect($delivery->refresh()->state)->toBe(DeliveryState::Sent)->and($delivery->attempts)->toBe(2);
    expect(Delivery::query()->count())->toBe(1);
    Http::assertSentCount(2);
    Http::assertSent(fn ($request): bool => $request->hasHeader('X-Heptapod-Delivery', $event->id.':'.$destination->id)
        && $request->hasHeader('Idempotency-Key', $event->id.':'.$destination->id));
});

it('fences a foreign acknowledgement and reclaims an abandoned committed lease', function (): void {
    $this->freezeTime();
    Http::preventStrayRequests();
    Http::fake(['https://receiver.test/alerts' => Http::response([], 200)]);
    notificationRule(notificationWebhook());
    $event = notificationIncidentEvent();
    app(PlanNotificationEvent::class)($event->id);
    $delivery = Delivery::query()->sole();
    $first = app(ClaimNotificationDelivery::class)($delivery->id);
    expect(app(ClaimNotificationDelivery::class)($delivery->id))->toBeNull();
    expect(app(CompleteNotificationDelivery::class)($delivery->id, '00000000-0000-0000-0000-000000000001', new DeliveryResult(DeliveryState::Sent)))->toBeFalse();
    $this->travel(91)->seconds();
    $second = app(ClaimNotificationDelivery::class)($delivery->id);
    expect($second->claim_token)->not->toBe($first->claim_token);
    expect(app(CompleteNotificationDelivery::class)($delivery->id, $first->claim_token, new DeliveryResult(DeliveryState::Sent)))->toBeFalse();
    expect(app(CompleteNotificationDelivery::class)($delivery->id, $second->claim_token, new DeliveryResult(DeliveryState::Failed, 'notifications.transport_failed', diagnosis: 'HTTP 503')))->toBeTrue();
    $this->travel(2)->minutes();
    dispatchNotifications();
    expect($delivery->refresh()->state)->toBe(DeliveryState::Sent)->and($delivery->attempts)->toBe(3);
    Http::assertSentCount(1);
});

it('closes a pending recovery when its opener is permanently rejected', function (): void {
    $this->freezeTime();
    Mail::fake();
    $destination = NotificationDestination::factory()->create();
    notificationRule($destination);
    notificationRule($destination, 'site.recovered');
    $opened = notificationIncidentEvent();
    app(PlanNotificationEvent::class)($opened->id);
    $opener = Delivery::query()->where('event_id', $opened->id)->sole();
    $claim = app(ClaimNotificationDelivery::class)($opener->id);
    app(CompleteNotificationDelivery::class)($opener->id, $claim->claim_token, new DeliveryResult(DeliveryState::Failed, 'notifications.transport_failed', diagnosis: 'SMTP 451 RCPT'));
    $resolved = Event::factory()->create(['site_id' => $opened->site_id, 'check_id' => $opened->check_id,
        'incident_id' => $opened->incident_id, 'type' => 'incident.resolved', 'from_result' => CheckResult::Failed,
        'to_result' => CheckResult::Succeeded]);
    dispatchNotifications();
    expect(Delivery::query()->where('event_id', $resolved->id)->sole()->state)->toBe(DeliveryState::Pending);
    $this->travel(1)->minutes();
    $claim = app(ClaimNotificationDelivery::class)($opener->id);
    app(CompleteNotificationDelivery::class)($opener->id, $claim->claim_token, new DeliveryResult(DeliveryState::Failed, 'notifications.transport_failed', diagnosis: 'SMTP 550 RCPT'));
    dispatchNotifications();
    $recovery = Delivery::query()->where('event_id', $resolved->id)->sole();
    expect($opener->refresh()->suppression_reason)->toBe('smtp_recipient_rejected')
        ->and($destination->refresh()->last_outcome)->toBe('rejected')
        ->and($recovery->suppression_reason)->toBe('opening_not_delivered');
    $this->travel(2)->hours();
    dispatchNotifications();
    expect($opener->refresh()->attempts)->toBe(2)
        ->and($recovery->refresh()->attempts)->toBe(0);
    Mail::assertNothingSent();
});

it('honors the original suppression snapshot even after the snooze ends', function (): void {
    Http::preventStrayRequests();
    Http::fake(['https://receiver.test/alerts' => Http::response([], 200)]);
    notificationRule(notificationWebhook());
    $event = notificationIncidentEvent(['suppressed' => true, 'suppression_reason' => 'snoozed', 'suppression_evaluated_at' => now()->subDay()]);
    dispatchNotifications();
    expect($event->refresh()->suppression_reason)->toBe('snoozed');
    expect(Delivery::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('does not alert below the engine failure threshold and sends one incident alert at threshold', function (): void {
    Http::preventStrayRequests();
    Http::fake(['https://receiver.test/alerts' => Http::response([], 200)]);
    // The engine threshold is under test, not the uptime alert delay (2a424618): one minute makes the opener due at once.
    $settings = app(InstanceSettings::class);
    $settings->alert_after_minutes = 1;
    $settings->save();
    notificationRule(notificationWebhook());
    $check = Check::factory()->create(['failure_threshold' => 2]);
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $context = ClaimedRun::start($check, CarbonImmutable::now('UTC')->subSecond());
        app(ResultRecorder::class)->record(new RecordableRun($check->id, $context->claimToken, $context->startedAt, CarbonImmutable::now('UTC'),
            new CheckOutcome(RunResult::Failed, 'uptime.status', [], new UptimeMetrics(null, null, null, null, null, 0.01,
                array_fill_keys(['namelookup_time', 'connect_time', 'appconnect_time', 'starttransfer_time', 'pretransfer_time', 'redirect_time', 'total_time', 'header_size', 'size_download', 'speed_download'], null), '2026-10-01'))));
        dispatchNotifications();
        expect(Delivery::query()->count())->toBe($attempt);
    }
    Http::assertSentCount(1);
});

it('does not remind for a non-uptime incident whether or not its capability is available', function (): void {
    $this->freezeTime();
    Http::preventStrayRequests();
    Http::fake(['https://receiver.test/alerts' => Http::response([], 200)]);
    notificationRule(notificationWebhook(), 'check.failing');
    $check = lighthouseCheck();
    $fake = new FakeChromiumRenderer;
    lighthouseReply($fake, new ExternalTimeoutException('Browser did not complete.'));
    app()->instance(ChromiumRenderer::class, $fake);
    $queue = app('queue');
    runLighthouse($check);
    Queue::swap($queue);
    dispatchNotifications();
    Http::assertSentCount(1);
    expect(Incident::query()->whereNull('resolved_at')->count())->toBe(1);

    config(['heptapod.browser.enabled' => false]);
    $this->travel(61)->minutes();
    dispatchNotifications();
    expect(Event::query()->where('type', 'incident.still_down')->count())->toBe(0);
    Http::assertSentCount(1);

    // Only uptime Down incidents emit reminders (d2ff0ba0, docs/notifications.md), so a returning capability adds none.
    config(['heptapod.browser.enabled' => true]);
    dispatchNotifications();
    expect(Event::query()->where('type', 'incident.still_down')->count())->toBe(0);
    Http::assertSentCount(1);
});

it('defers overnight warnings to local window end across DST and bypasses quiet hours for new critical alerts', function (string $now, string $due): void {
    $this->travelTo(CarbonImmutable::parse($now));
    config(['notifications.renotify_intervals_seconds' => null]);
    Http::preventStrayRequests();
    Http::fake(['https://receiver.test/alerts' => Http::response([], 200)]);
    app(InstanceSettings::class)->timezone = 'Europe/Berlin';
    app(InstanceSettings::class)->save();
    $destination = notificationWebhook();
    notificationSchedule($destination, ['quiet_start' => '22:00', 'quiet_end' => '07:00']);
    notificationRule($destination, 'check.warning');
    notificationRule($destination);
    $warning = Event::factory()->create(['to_result' => CheckResult::Warning]);
    dispatchNotifications();
    $delivery = Delivery::query()->sole();
    expect($delivery->state)->toBe(DeliveryState::Pending)->and($delivery->next_attempt_at->toIso8601String())->toBe($due);
    notificationIncidentEvent();
    dispatchNotifications();
    Http::assertSentCount(1);
    $this->travelTo(CarbonImmutable::parse($due));
    dispatchNotifications();
    expect($delivery->refresh()->state)->toBe(DeliveryState::Sent);
    Http::assertSentCount(2);
})->with([
    'ordinary midnight' => ['2026-10-01T23:00:00Z', '2026-10-02T05:00:00+00:00'],
    'spring DST' => ['2026-03-28T22:00:00Z', '2026-03-29T05:00:00+00:00'],
    'autumn DST' => ['2026-10-24T21:00:00Z', '2026-10-25T06:00:00+00:00'],
]);

it('resolves quiet-hour gap and fold boundaries without early leakage', function (string $start, string $end, string $now, string $due): void {
    $this->travelTo(CarbonImmutable::parse($now));
    Http::preventStrayRequests();
    Http::fake(['https://receiver.test/alerts' => Http::response([], 200)]);
    app(InstanceSettings::class)->timezone = 'Europe/Berlin';
    app(InstanceSettings::class)->save();
    $destination = notificationWebhook();
    notificationSchedule($destination, ['quiet_start' => $start, 'quiet_end' => $end]);
    notificationRule($destination, 'check.warning');
    Event::factory()->create(['to_result' => CheckResult::Warning]);
    dispatchNotifications();
    $delivery = Delivery::query()->sole();
    expect(($delivery->sent_at ?? $delivery->next_attempt_at)->toIso8601String())->toBe($due);
    Http::assertSentCount($due === $now ? 1 : 0);
    $this->travelTo(CarbonImmutable::parse($due));
    dispatchNotifications();
    expect($delivery->refresh()->state)->toBe(DeliveryState::Sent);
    Http::assertSentCount(1);
})->with([
    'gap start normalises forward' => ['02:30', '04:00', '2026-03-29T01:15:00+00:00', '2026-03-29T01:15:00+00:00'],
    'gap start at normalised instant' => ['02:30', '04:00', '2026-03-29T01:30:00+00:00', '2026-03-29T02:00:00+00:00'],
    'gap end normalises forward' => ['01:00', '02:30', '2026-03-29T01:15:00+00:00', '2026-03-29T01:30:00+00:00'],
    'fold start uses first occurrence' => ['02:30', '04:00', '2026-10-25T00:45:00+00:00', '2026-10-25T03:00:00+00:00'],
    'fold end uses second occurrence' => ['01:00', '02:30', '2026-10-25T01:15:00+00:00', '2026-10-25T01:30:00+00:00'],
]);

it('sends a daily digest as one own event and leaves freshly suppressed sources unsent', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-01T07:00:00Z'));
    Http::preventStrayRequests();
    Http::fake(['https://receiver.test/alerts' => Http::response([], 200)]);
    $destination = notificationWebhook();
    notificationRule($destination, 'check.warning', NotificationMode::Digest);
    $warnings = Event::factory()->count(3)->create(['to_result' => CheckResult::Warning]);
    foreach ($warnings as $warning) {
        $warning->check->update(['latest_run_result' => CheckResult::Warning]);
    }
    $warnings[0]->site->update(['friendly_name' => null, 'url' => 'https://user:pass@example.com:8443/shop/?email=alice@other.test#part']);
    $warnings[1]->site->update(['friendly_name' => 'Named digest site']);
    dispatchNotifications();
    Http::assertNothingSent();
    CheckSnooze::factory()->create(['check_id' => $warnings->last()->check_id, 'starts_at' => now(), 'ends_at' => now()->addDay()]);
    $this->travel(1)->hours();
    dispatchNotifications();
    dispatchNotifications();
    $digest = Event::query()->where('type', 'notification.digest')->sole();
    expect($digest->payload['count'])->toBe(2)->and($digest->site_id)->toBeNull()->and($digest->suppressed)->toBeFalse();
    expect($digest->payload['sites'])->toBe('example.com:8443/shop, Named digest site');
    expect(Delivery::query()->where('digest', true)->where('state', 'sent')->count())->toBe(2);
    expect(Delivery::query()->where('suppressed', true)->where('state', 'sent')->count())->toBe(0);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => $request['event_id'] === $digest->id && str_contains($request['summary'], '2 open items'));
});

it('sends only open warnings and check errors at 08:00 instance time once per recipient each day', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05T00:30:00Z'));
    $settings = app(InstanceSettings::class);
    $settings->timezone = 'Europe/Berlin';
    $settings->save();
    config(['notifications.renotify_intervals_seconds' => null]);
    Http::preventStrayRequests();
    Http::fake(['https://receiver.test/alerts' => Http::response([], 200)]);
    $destination = notificationWebhook();
    foreach (['check.warning', 'check.failing'] as $eventType) {
        notificationRule($destination, $eventType, NotificationMode::Digest);
    }
    $warning = Event::factory()->create(['to_result' => CheckResult::Warning]);
    $warning->check->update(['latest_run_result' => CheckResult::Warning]);
    $stale = Event::factory()->create(['to_result' => CheckResult::Warning]);
    $stale->check->update(['latest_run_result' => CheckResult::Succeeded]);
    $error = notificationIncidentEvent(['cause' => 'errored', 'to_result' => CheckResult::Errored]);
    $error->check->update(['latest_run_result' => CheckResult::Errored]);
    dispatchNotifications();
    expect(Delivery::query()->where('digest', true)->count())->toBe(3);
    Http::assertNothingSent();
    $this->travelTo(CarbonImmutable::parse('2026-10-05T05:59:59Z'));
    dispatchNotifications();
    Http::assertNothingSent();
    $this->travel(1)->seconds();
    dispatchNotifications();
    expect(Event::query()->where('type', 'notification.digest')->sole()->payload['count'])->toBe(2);
    expect(Delivery::query()->where('suppression_reason', 'digest_no_longer_open')->count())->toBe(1);
    Http::assertSentCount(1);
    $second = Event::factory()->create(['to_result' => CheckResult::Warning]);
    $second->check->update(['latest_run_result' => CheckResult::Warning]);
    app(PlanNotificationEvent::class)($second->id);
    $this->travel(1)->days();
    dispatchNotifications();
    expect(Event::query()->where('type', 'notification.digest')->count())->toBe(2);
    Http::assertSentCount(2);
});

it('schedules the instance 08:00 digest on both DST transition days', function (string $now, string $due): void {
    $this->travelTo(CarbonImmutable::parse($now));
    $settings = app(InstanceSettings::class);
    $settings->timezone = 'Europe/Berlin';
    $settings->save();
    Http::preventStrayRequests();
    Http::fake(['https://receiver.test/alerts' => Http::response([], 200)]);
    $destination = notificationWebhook();
    notificationRule($destination, 'check.warning', NotificationMode::Digest);
    $event = Event::factory()->create(['to_result' => CheckResult::Warning]);
    $event->check->update(['latest_run_result' => CheckResult::Warning]);
    dispatchNotifications();
    expect(Delivery::query()->where('event_id', $event->id)->sole()->next_attempt_at->toIso8601String())->toBe($due);
    $this->travelTo(CarbonImmutable::parse($due)->subSecond());
    dispatchNotifications();
    Http::assertNothingSent();
    $this->travel(1)->seconds();
    dispatchNotifications();
    expect(Event::query()->where('type', 'notification.digest')->sole()->payload['count'])->toBe(1);
    Http::assertSentCount(1);
})->with([
    'spring-forward' => ['2026-03-28T22:00:00Z', '2026-03-29T06:00:00+00:00'],
    'fall-back' => ['2026-10-24T21:00:00Z', '2026-10-25T07:00:00+00:00'],
]);

it('repeats all still-open warnings in each daily digest and omits resolved ones', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05T07:00:00Z'));
    config(['notifications.renotify_intervals_seconds' => null]);
    Http::preventStrayRequests();
    Http::fake(['https://receiver.test/alerts' => Http::response([], 200)]);
    $destination = notificationWebhook();
    notificationRule($destination, 'check.warning', NotificationMode::Digest);
    $first = Event::factory()->create(['to_result' => CheckResult::Warning]);
    $first->check->update(['latest_run_result' => CheckResult::Warning]);
    dispatchNotifications();
    $this->travel(1)->hours();
    dispatchNotifications();
    expect(Event::query()->where('type', 'notification.digest')->sole()->payload['count'])->toBe(1);
    $second = Event::factory()->create(['to_result' => CheckResult::Warning]);
    $second->check->update(['latest_run_result' => CheckResult::Warning]);
    app(PlanNotificationEvent::class)($second->id);
    $this->travel(1)->days();
    dispatchNotifications();
    expect(Event::query()->where('type', 'notification.digest')->orderByDesc('id')->firstOrFail()->payload['count'])->toBe(2);
    $second->check->update(['latest_run_result' => CheckResult::Succeeded]);
    $this->travel(1)->days();
    dispatchNotifications();
    expect(Event::query()->where('type', 'notification.digest')->orderByDesc('id')->firstOrFail()->payload['count'])->toBe(1);
    $first->check->update(['latest_run_result' => CheckResult::Succeeded]);
    $this->travel(1)->days();
    dispatchNotifications();
    expect(Event::query()->where('type', 'notification.digest')->count())->toBe(3);
    Http::assertSentCount(3);
});

it('renders the opened reminder recovered and digest email copy without internal notes', function (string $locale): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05T00:30:00Z'));
    $settings = app(InstanceSettings::class);
    $settings->timezone = 'Europe/Berlin';
    $settings->save();
    $transport = new ArrayTransport;
    Mail::mailer()->setSymfonyTransport($transport);
    $destination = NotificationDestination::factory()->create(['locale' => $locale, 'label' => 'Ops',
        'config' => app(ChannelRegistry::class)->get('mail')->configSchema()->validateConfig(['email' => 'ops@example.test'])]);
    foreach (['site.down', 'site.recovered', 'check.warning'] as $type) {
        notificationRule($destination, $type, $type === 'check.warning' ? NotificationMode::Digest : NotificationMode::Immediate);
    }
    $opened = notificationIncidentEvent();
    $opened->site->client->update(['name' => 'Example Agency']);
    $opened->site->update(['friendly_name' => 'api.example.test', 'url' => 'https://api.example.test']);
    $opened->incident->update(['notes' => 'private incident note']);
    $opened->check->update(['latest_run_result' => CheckResult::Failed]);
    dispatchNotifications();
    $this->travel(1)->hours();
    dispatchNotifications();
    notificationRecoveryEvent($opened);
    $opened->incident->update(['resolved_at' => now()]);
    dispatchNotifications();
    $warning = Event::factory()->create(['site_id' => $opened->site_id, 'check_id' => $opened->check_id,
        'to_result' => CheckResult::Warning]);
    $opened->check->update(['latest_run_result' => CheckResult::Warning]);
    dispatchNotifications();
    $this->travelTo(CarbonImmutable::parse('2026-10-05T06:00:00Z'));
    dispatchNotifications();
    expect($transport->messages())->toHaveCount(4);
    $incidentId = (string) $opened->incident_id;
    $checkPath = '/sites/'.$opened->site_id.'/checks/'.$opened->check_id;
    $rendered = $transport->messages()->map(static function (SentMessage $sent) use ($incidentId, $checkPath): string {
        $message = $sent->getOriginalMessage();
        if (! $message instanceof Email) {
            throw new LogicException('Rendered alert must be an email.');
        }
        $copy = $message->getSubject()."\n-- PLAIN --\n".$message->getTextBody()."\n-- HTML --\n".$message->getHtmlBody();
        $publicCopy = str_replace(config()->string('app.url'), 'https://monitor.example.test', $copy);

        // Ids come from shared sequences; replace only this fixture's own values so any other id stays visible.
        // The structured mail (d4023e31) also prints the incident as #id.
        return preg_replace(['#/incidents/'.$incidentId.'\b#', '/#'.$incidentId.'\b/'], ['/incidents/{id}', '#{id}'], str_replace($checkPath, '/sites/{site}/checks/{check}', $publicCopy))
            ?? throw new LogicException('Could not normalise incident link.');
    })->implode("\n---\n");
    expect($rendered)->not->toContain('private incident note');
    expect($rendered)->toMatchSnapshot();
})->with(['en', 'de']);

it('renders failed check incidents separately from site outages through dispatch and recovery', function (string $locale, CheckKey $type, string $openingSubject, string $recoverySubject, string $openingTitle, string $recoveryTitle, string $durationLabel, string $machineDurationLabel): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-01T12:00:00Z'));
    $this->withoutVite();
    Http::preventStrayRequests();
    Http::fake([
        'https://receiver.test/alerts' => Http::response([], 200),
        'https://discord.com/api/channels/recovery-review/messages' => Http::response(['id' => 'accepted'], 200),
    ]);
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $transport = new ArrayTransport;
    Mail::mailer()->setSymfonyTransport($transport);
    $destination = NotificationDestination::factory()->create(['locale' => $locale]);
    $webhook = notificationWebhook($locale);
    $chat = NotificationDestination::factory()->create(['channel' => 'discord', 'locale' => $locale,
        'config' => app(ChannelRegistry::class)->get('discord')->configSchema()->validateConfig(['channel' => 'recovery-review', 'token' => 'private-discord-review-token']),
    ]);
    foreach (['site.down', 'site.recovered', 'check.failing', 'check.recovered'] as $key) {
        foreach ([$destination, $webhook, $chat] as $recipient) {
            notificationRule($recipient, $key);
        }
    }
    $opened = notificationIncidentEvent();
    $opened->site->update(['friendly_name' => 'PORT Magazine']);
    $opened->site->client->update(['name' => 'Internal']);
    $opened->check->update(['type' => $type, 'latest_run_result' => CheckResult::Failed]);
    $opened->incident->update(['first_failure_at' => now()]);

    dispatchNotifications();
    $this->travel(7)->minutes();
    $opened->check->update(['latest_run_result' => CheckResult::Succeeded]);
    $opened->incident->update(['resolved_at' => now()]);
    $resolved = notificationRecoveryEvent($opened);
    dispatchNotifications();

    expect(Delivery::query()->where('state', 'sent')->count())->toBe(6);
    $emails = $transport->messages()->map(static function (SentMessage $sent): Email {
        $email = $sent->getOriginalMessage();
        if (! $email instanceof Email) {
            throw new LogicException('Rendered alert must be an email.');
        }

        return $email;
    });
    // Structured mail (d4023e31, 9fc5ddaf): front-loaded subjects; every recovery names how long it was broken.
    expect($emails->map(fn (Email $email): ?string => $email->getSubject())->all())->toBe([$openingSubject, $recoverySubject]);
    foreach ([$openingTitle, $recoveryTitle] as $index => $title) {
        expect($emails[$index]->getTextBody())->toContain($title);
        expect($emails[$index]->getHtmlBody())->toContain($title);
    }
    if ($type !== CheckKey::Uptime) {
        expect($emails[1]->getTextBody())->not->toContain('downtime', 'Ausfallzeit', 'down ', 'ausgefallen');
    }
    $preview = $this->actingAs($owner)->get('/incidents/'.$opened->incident_id.'/alerts/'.$resolved->id)
        ->assertJsonPath('subject', $recoverySubject);
    expect($preview->json('html'))->toContain($recoveryTitle);
    Http::assertSent(fn ($request): bool => $request->url() === 'https://discord.com/api/channels/recovery-review/messages'
        && str_contains($request['embeds'][0]['description'], $durationLabel));
    Http::assertSent(fn ($request): bool => $request->url() === 'https://receiver.test/alerts'
        && $request['event_id'] === $resolved->id && $request['severity'] === 'recovered'
        && $request['downtime_seconds'] === 420 && str_contains($request['summary'], $machineDurationLabel));
    Http::assertSentCount(4);
})->with([
    'broken links EN' => ['en', CheckKey::BrokenLinks, '[Failed] PORT Magazine — Broken links: HTTP 503', '[Recovered] PORT Magazine — No broken links found',
        'Oct 1, 2026, 12:00 UTC', 'Recovered after 7 min', 'Total duration: 420 seconds', 'Total downtime: 420 seconds'],
    'broken links DE' => ['de', CheckKey::BrokenLinks, '[Fehlgeschlagen] PORT Magazine — Defekte Links: HTTP 503', '[Entwarnung] PORT Magazine — Keine defekten Links gefunden',
        '1. Okt 2026, 12:00 UTC', 'Entwarnung nach 7 Min.', 'Gesamtdauer: 420 Sekunden', 'Gesamte Ausfallzeit: 420 Sekunden'],
    'uptime EN' => ['en', CheckKey::Uptime, '[Down] PORT Magazine — HTTP 503 since 12:00 UTC', '[Recovered] PORT Magazine after 7 min',
        'PORT Magazine is down', 'PORT Magazine is up again after 7 min', 'Total downtime: 420 seconds', 'Total downtime: 420 seconds'],
    'uptime DE' => ['de', CheckKey::Uptime, '[Ausgefallen] PORT Magazine — HTTP 503 seit 12:00 UTC', '[Entwarnung] PORT Magazine nach 7 Min.',
        'PORT Magazine ist ausgefallen', 'PORT Magazine ist nach 7 Min. wieder erreichbar', 'Gesamte Ausfallzeit: 420 Sekunden', 'Gesamte Ausfallzeit: 420 Sekunden'],
]);

it('labels dispatched reminders and previews from the historical event without calling failed checks down', function (string $locale, CheckKey $type, CheckResult $result, string $label, string $title): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-01T10:00:00Z'));
    $this->withoutVite();
    $transport = new ArrayTransport;
    Mail::mailer()->setSymfonyTransport($transport);
    $owner = User::factory()->create(['locale' => $locale]);
    $owner->assignRole('Owner');
    $destination = NotificationDestination::factory()->create(['locale' => $locale]);
    notificationRule($destination, 'site.down');
    notificationRule($destination, 'check.failing');
    $cause = $result === CheckResult::Errored ? 'errored' : 'failed';
    $opened = notificationIncidentEvent(['cause' => $cause, 'to_result' => $result]);
    $opened->site->update(['friendly_name' => 'PORT Magazine']);
    $opened->check->update(['type' => $type, 'latest_run_result' => $result]);
    $opened->incident->update(['cause' => $cause]);
    dispatchNotifications();
    $this->travel(1)->hours();
    if ($type !== CheckKey::Uptime || $result === CheckResult::Errored) {
        // Only uptime Down incidents emit reminders since d2ff0ba0; older non-uptime reminders remain as retained events (8acfcc18).
        Event::factory()->create(['site_id' => $opened->site_id, 'check_id' => $opened->check_id, 'incident_id' => $opened->incident_id,
            'type' => 'incident.still_down', 'cause' => $cause, 'to_result' => $result]);
    }
    dispatchNotifications();

    $reminder = Event::query()->where('type', 'incident.still_down')->sole();
    $delivery = Delivery::query()->where('event_id', $reminder->id)->sole();
    expect($delivery->state)->toBe(DeliveryState::Sent);
    expect($transport->messages())->toHaveCount(2);
    $mail = $transport->messages()->last()->getOriginalMessage();
    expect($mail)->toBeInstanceOf(Email::class);
    expect($mail->getSubject())->toBe($title);
    if ($type !== CheckKey::Uptime || $result === CheckResult::Errored) {
        // Retained non-uptime and errored reminders get the retained-reminder block mail, never a new opener.
        expect($mail->getTextBody())->toContain($locale === 'en' ? 'A reminder for the same open incident.' : 'Eine Erinnerung für dieselbe offene Störung.');
    }

    // Current incident cause must not relabel an already dispatched reminder.
    $opened->incident->update(['cause' => $result === CheckResult::Errored ? 'failed' : 'errored']);
    $group = collect($this->actingAs($owner)->get('/incidents/'.$opened->incident_id)->inertiaProps()['timeline'])
        ->firstWhere('notification_group.event_type', 'incident.still_down')['notification_group'];
    expect($group['event_label'])->toBe($label)->and($group['state'])->toBe('accepted');
    $this->get('/incidents/'.$opened->incident_id.'?notification_group='.$group['id'])->assertInertia(fn (Assert $page): Assert => $page
        ->where('expanded_notifications.deliveries.0.event_type', 'incident.still_down')
        ->where('expanded_notifications.deliveries.0.event_label', $label)
        ->where('expanded_notifications.deliveries.0.state', 'accepted')
        ->where('expanded_notifications.deliveries.0.can_preview', true));
    $this->get('/incidents/'.$opened->incident_id.'/alerts/'.$reminder->id)
        ->assertJsonPath('subject', $title);
    expect($reminder->refresh()->cause->value)->toBe($cause);
    expect($delivery->refresh()->state)->toBe(DeliveryState::Sent);
})->with([
    'failed broken links EN' => ['en', CheckKey::BrokenLinks, CheckResult::Failed, 'incident.still_failing', '[Still failing] PORT Magazine — Broken links'],
    'failed broken links DE' => ['de', CheckKey::BrokenLinks, CheckResult::Failed, 'incident.still_failing', '[Weiterhin fehlgeschlagen] PORT Magazine — Defekte Links'],
    'down uptime EN' => ['en', CheckKey::Uptime, CheckResult::Failed, 'incident.still_down', '[Down] PORT Magazine — still down for 1 h 1 min since 09:59 UTC'],
    'down uptime DE' => ['de', CheckKey::Uptime, CheckResult::Failed, 'incident.still_down', '[Ausgefallen] PORT Magazine — nach 1 Std. 1 Min. weiter ausgefallen, seit 09:59 UTC'],
    // A check error is not a failure: errored reminders read "Still not checkable" (abb2f84b).
    'errored uptime EN' => ['en', CheckKey::Uptime, CheckResult::Errored, 'incident.still_not_checkable', '[Still a check error] PORT Magazine — Uptime'],
    'errored uptime DE' => ['de', CheckKey::Uptime, CheckResult::Errored, 'incident.still_not_checkable', '[Weiterhin Prüffehler] PORT Magazine — Erreichbarkeit'],
    'errored broken links EN' => ['en', CheckKey::BrokenLinks, CheckResult::Errored, 'incident.still_not_checkable', '[Still a check error] PORT Magazine — Broken links'],
    'errored broken links DE' => ['de', CheckKey::BrokenLinks, CheckResult::Errored, 'incident.still_not_checkable', '[Weiterhin Prüffehler] PORT Magazine — Defekte Links'],
]);

it('renders errors without down wording and recoveries with total downtime in each locale', function (string $locale, string $error, string $recovery): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-01T12:00:00Z'));
    Http::preventStrayRequests();
    Http::fake(['https://receiver.test/alerts' => Http::response([], 200)]);
    $destination = notificationWebhook($locale);
    notificationRule($destination, 'check.failing');
    notificationRule($destination, 'site.recovered');
    $opened = notificationIncidentEvent(['cause' => 'errored', 'to_result' => CheckResult::Errored]);
    $event = Event::factory()->create(['site_id' => $opened->site_id, 'check_id' => $opened->check_id,
        'incident_id' => $opened->incident_id, 'type' => 'incident.resolved', 'to_result' => CheckResult::Succeeded]);
    $opened->incident->update(['first_failure_at' => now()->subMinutes(7), 'resolved_at' => now()]);
    dispatchNotifications();
    Http::assertSentCount(2);
    Http::assertSent(fn ($request): bool => $request['severity'] === 'error' && str_contains($request['summary'], $error)
        && ! str_contains($request['title'], ' is down'));
    Http::assertSent(fn ($request): bool => $request['severity'] === 'recovered' && $request['downtime_seconds'] === 420 && str_contains($request['title'], $recovery));
})->with([
    ['en', 'Check could not run (error)', 'has recovered'],
    ['de', 'Prüfung konnte nicht ausgeführt werden (Fehler)', 'wieder erreichbar'],
]);

it('discovers addon-specific routing and keeps unmapped declared addon events routable', function (): void {
    Http::preventStrayRequests();
    Http::fake(['https://receiver.test/alerts' => Http::response([], 200)]);
    $destination = notificationWebhook();
    notificationRule($destination, 'certificate.expiring');
    notificationRule($destination, 'certificate_health.certificate_changed');
    $check = Check::factory()->create(['type' => CheckKey::CertificateHealth]);
    Event::factory()->create(['check_id' => $check->id, 'site_id' => $check->site_id, 'to_result' => CheckResult::Warning]);
    Event::factory()->create(['check_id' => $check->id, 'site_id' => $check->site_id, 'type' => 'certificate_health.certificate_changed']);
    dispatchNotifications();
    Http::assertSentCount(2);
});

it('ignores unselected operational events while still routing qualified engine errors', function (): void {
    Http::preventStrayRequests();
    Http::fake(['https://receiver.test/alerts' => Http::response([], 200)]);
    notificationRule(notificationWebhook(), 'check.failing');
    $check = Check::factory()->create(['type' => CheckKey::Domain]);
    $ignored = Event::factory()->create(['site_id' => $check->site_id, 'check_id' => $check->id, 'type' => 'check.requested']);
    Event::factory()->create(['site_id' => $check->site_id, 'check_id' => $check->id, 'type' => 'incident.opened', 'to_result' => CheckResult::Errored, 'cause' => 'errored']);
    dispatchNotifications();
    expect($ignored->refresh()->notification_routed_at)->not->toBeNull();
    expect(Delivery::query()->where('state', 'sent')->count())->toBe(1);
    Http::assertSentCount(1);
});

it('drops component recoveries without a matching accepted opening alert', function (?string $from): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-01T23:00:00Z'));
    Http::preventStrayRequests();
    Http::fake(['https://receiver.test/alerts' => Http::response([], 200)]);
    app(InstanceSettings::class)->timezone = 'UTC';
    app(InstanceSettings::class)->save();
    $destination = notificationWebhook();
    notificationSchedule($destination, ['quiet_start' => '22:00', 'quiet_end' => '07:00']);
    notificationRule($destination, 'check.recovered');
    $check = Check::factory()->create(['type' => CheckKey::Cron]);
    $payload = ['result' => 'succeeded'];
    if ($from !== null) {
        $payload['from_result'] = $from;
    }
    Event::factory()->create(['site_id' => $check->site_id, 'check_id' => $check->id, 'type' => 'cron.task_result_changed', 'payload' => $payload]);
    dispatchNotifications();
    Http::assertNothingSent();
    $this->travelTo(CarbonImmutable::parse('2026-10-02T07:00:00Z'));
    dispatchNotifications();
    Http::assertNothingSent();
    expect(Delivery::query()->where('state', 'sent')->count())->toBe(0);
})->with([
    'first observation' => ['pending'],
    'warning recovery' => ['warning'],
    'failure recovery' => ['failed'],
    'old payload without history' => [null],
]);

it('recovers only the component whose warning opening was accepted', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05T10:00:00Z'));
    Http::preventStrayRequests();
    Http::fake(['https://receiver.test/alerts' => Http::response([], 200)]);
    $destination = notificationWebhook();
    notificationRule($destination, 'check.warning');
    notificationRule($destination, 'check.recovered');
    $check = Check::factory()->create(['type' => CheckKey::Cron]);
    // A component episode starts after its last success, matched on to_result or payload result (35ea9462);
    // the factory's default to_result Succeeded would make this warning its own boundary.
    $warning = Event::factory()->create(['check_id' => $check->id, 'site_id' => $check->site_id,
        'type' => 'cron.task_result_changed', 'component_type' => 'cron', 'component_id' => 1,
        'from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Warning,
        'payload' => ['name' => 'Task A', 'result' => 'warning']]);
    dispatchNotifications();
    expect(Delivery::query()->where('event_id', $warning->id)->sole()->state)->toBe(DeliveryState::Sent);
    foreach ([2, 1] as $componentId) {
        $recovered = Event::factory()->create(['check_id' => $check->id, 'site_id' => $check->site_id,
            'type' => 'cron.task_result_changed', 'component_type' => 'cron', 'component_id' => $componentId,
            'payload' => ['name' => 'Task '.$componentId, 'from_result' => 'warning', 'result' => 'succeeded']]);
        dispatchNotifications();
        expect(Delivery::query()->where('event_id', $recovered->id)->count())->toBe($componentId === 1 ? 1 : 0);
    }
    Http::assertSentCount(2);
});

/** @param list<string> $arguments */
function notificationProcess(array $arguments): Process
{
    return new Process([PHP_BINARY, __DIR__.'/Fixtures/notification-process.php', ...$arguments], base_path(), [
        'APP_ENV' => 'testing', 'DB_DATABASE' => config('database.connections.pgsql.database'), 'DB_URL' => '',
    ], timeout: 20);
}

/** Commit only this test's rows so separate PostgreSQL sessions can see them. */
function committedNotificationEvent(Closure $test): void
{
    $destination = notificationRule(notificationWebhook());
    $event = notificationIncidentEvent();
    $clientId = $event->site->client_id;
    DB::commit();
    try {
        $test($event);
    } finally {
        DB::table('sites')->where('id', $event->site_id)->delete();
        DB::table('clients')->where('id', $clientId)->where('is_internal', false)->delete();
        DB::table('deliveries')->where('destination_id', $destination->id)->delete();
        DB::table('notification_destinations')->where('id', $destination->id)->delete();
        DB::beginTransaction();
    }
}

it('overlapping independent dispatchers plan one pair and send once while provider I/O holds no transaction', function (): void {
    committedNotificationEvent(function (Event $event): void {
        $receipts = tempnam(sys_get_temp_dir(), 'notification-receipts-');
        $signal = $receipts.'-signal';
        $release = $receipts.'-release';
        $first = notificationProcess(['dispatch-hold', $receipts, $signal, $release]);
        try {
            $first->start();
            $deadline = hrtime(true) + 10_000_000_000;
            while (! file_exists($signal) && $first->isRunning() && hrtime(true) < $deadline) {
                usleep(10000);
            }
            expect(file_exists($signal))->toBeTrue($first->getErrorOutput().$first->getOutput());
            // The committed sending row is visible while the provider is still blocked.
            expect(Delivery::query()->where('event_id', $event->id)->sole()->state)->toBe(DeliveryState::Sending);
            notificationProcess(['dispatch', $receipts])->mustRun();
            file_put_contents($release, 'release');
            expect($first->wait())->toBe(0);
            expect(file_get_contents($receipts))->toBe("sent\n");
            expect(Delivery::query()->where('event_id', $event->id)->sole()->state)->toBe(DeliveryState::Sent);
        } finally {
            $first->stop(0, SIGKILL);
            foreach ([$receipts, $signal, $release] as $path) {
                if (file_exists($path)) {
                    unlink($path);
                }
            }
        }
    });
});

it('recovers a SIGKILL after delivery claim commit and sends through the dispatcher after lease expiry', function (): void {
    committedNotificationEvent(function (Event $event): void {
        app(PlanNotificationEvent::class)($event->id);
        $delivery = Delivery::query()->where('event_id', $event->id)->sole();
        $worker = notificationProcess(['claim-kill', (string) $delivery->id]);
        expect(fn (): int => $worker->run())->toThrow(ProcessSignaledException::class);
        expect($worker->getTermSignal())->toBe(SIGKILL);
        expect($delivery->refresh()->state)->toBe(DeliveryState::Sending)->and($delivery->attempts)->toBe(1);
        DB::update("UPDATE deliveries SET lease_until = CURRENT_TIMESTAMP - INTERVAL '1 second' WHERE id = ?", [$delivery->id]);
        $receipts = tempnam(sys_get_temp_dir(), 'notification-receipts-');
        try {
            notificationProcess(['dispatch', $receipts])->mustRun();
            expect($delivery->refresh()->state)->toBe(DeliveryState::Sent)->and($delivery->attempts)->toBe(2);
            expect(file_get_contents($receipts))->toBe("sent\n");
        } finally {
            unlink($receipts);
        }
    });
});

it('sends one bounded digest for 120 long site labels with its full count omission summary and link', function (string $locale, string $more): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-01T07:00:00Z'));
    Http::preventStrayRequests();
    Http::fake(['https://api.telegram.org/botdigest-token/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
    $destination = NotificationDestination::factory()->create(['channel' => 'telegram', 'locale' => $locale,
        'config' => app(ChannelRegistry::class)->get('telegram')->configSchema()->validateConfig(['chat_id' => '123', 'token' => 'digest-token'])]);
    notificationRule($destination, 'check.warning', NotificationMode::Digest);
    for ($site = 0; $site < 120; $site++) {
        $check = Check::factory()->for(Site::factory()->state(['friendly_name' => 'Site '.$site.' '.str_repeat('Ä', 200)]))
            ->create(['latest_run_result' => CheckResult::Warning]);
        Event::factory()->create(['site_id' => $check->site_id, 'check_id' => $check->id, 'to_result' => CheckResult::Warning]);
    }
    dispatchNotifications();
    dispatchNotifications();
    $this->travel(1)->hours();
    dispatchNotifications();
    $request = Http::recorded()->sole()[0];
    expect(mb_strlen($request['text'], 'UTF-8'))->toBeLessThanOrEqual(4096);
    // The digest links the Sites landing page since ecdfcfc0 (/dashboard only redirects there).
    expect($request['text'])->toContain('120', $more, route('sites.index'));
    expect(Event::query()->where('type', 'notification.digest')->sole()->payload['count'])->toBe(120);
    Http::assertSentCount(1);
})->with([['en', 'and 100 more'], ['de', 'und 100 weitere']]);

function recordNotificationCron(Check $check): void
{
    $at = CarbonImmutable::now('UTC');
    $context = ClaimedRun::start($check, $at);
    $outcome = app(CheckRegistry::class)->get(CheckKey::Cron)->execute($context);
    app(ResultRecorder::class)->record(new RecordableRun($check->id, $context->claimToken, $at, $at, $outcome));
}

it('names the recovered cron task and its own five minute duration while the aggregate remains failed', function (string $locale, string $durationLabel, string $machineDurationLabel): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-01T10:00:00Z'));
    $transport = new ArrayTransport;
    Mail::mailer()->setSymfonyTransport($transport);
    Http::preventStrayRequests();
    Http::fake(['https://receiver.test/alerts' => Http::response([], 200)]);
    $destination = notificationWebhook($locale);
    $mail = NotificationDestination::factory()->create(['locale' => $locale]);
    foreach ([$destination, $mail] as $recipient) {
        notificationRule($recipient, 'check.recovered');
        notificationRule($recipient, 'scheduled_task.missed');
    }
    $check = Check::factory()->create(['type' => CheckKey::Cron]);
    $definitions = app(CronDefinitions::class);
    $a = $definitions->create($check->id, ['name' => 'Task A', 'type' => 'simple', 'frequency_in_minutes' => 60, 'grace_time_in_minutes' => 0]);
    $b = $definitions->create($check->id, ['name' => 'Task B', 'type' => 'simple', 'frequency_in_minutes' => 60, 'grace_time_in_minutes' => 0]);
    $this->postJson(route('cron.ping', ['uuid' => $a->uuid, 'phase' => 'failed']))->assertNoContent();
    recordNotificationCron($check);
    dispatchNotifications();
    $this->travel(5)->minutes();
    $this->postJson(route('cron.ping', ['uuid' => $b->uuid, 'phase' => 'failed']))->assertNoContent();
    recordNotificationCron($check);
    dispatchNotifications();
    $this->travel(5)->minutes();
    $this->postJson(route('cron.ping', ['uuid' => $b->uuid, 'phase' => 'finished']))->assertNoContent();
    recordNotificationCron($check);
    dispatchNotifications();
    $request = Http::recorded()->last()[0];
    expect($request['title'])->toContain('Task B')->not->toContain('has recovered');
    expect($request['downtime_seconds'])->toBe(300);
    expect($request['since'])->toBe('2026-10-01T10:05:00+00:00');
    expect($request['summary'])->toContain('2026-10-01T10:10:00+00:00', $machineDurationLabel);
    expect($transport->messages())->toHaveCount(3);
    $email = $transport->messages()->last()->getOriginalMessage();
    expect($email)->toBeInstanceOf(Email::class);
    expect($email->getTextBody())->toContain($durationLabel);
    expect($email->getHtmlBody())->toContain($durationLabel);
    expect($check->refresh()->latest_run_result)->toBe(CheckResult::Failed);
    expect($a->refresh()->evaluated_failure_at->toIso8601String())->toBe('2026-10-01T10:00:00+00:00');
    expect($b->refresh()->evaluated_failure_at)->toBeNull();
    expect(Incident::query()->where('check_id', $check->id)->sole()->resolved_at)->toBeNull();
    Http::assertSentCount(3);
})->with([
    'EN' => ['en', 'Recovered after 5 min', 'Total downtime: 300 seconds'],
    'DE' => ['de', 'Entwarnung nach 5 Min.', 'Gesamte Ausfallzeit: 300 Sekunden'],
]);

it('does not borrow aggregate downtime for a legacy component recovery without timestamps', function (): void {
    Http::preventStrayRequests();
    Http::fake(['https://receiver.test/alerts' => Http::response([], 200)]);
    $destination = notificationWebhook();
    notificationRule($destination, 'check.recovered');
    notificationRule($destination, 'scheduled_task.missed');
    $opening = notificationIncidentEvent(['type' => 'cron.task_result_changed', 'component_type' => 'cron', 'component_id' => 1,
        'payload_version' => 2, 'payload' => ['name' => 'Legacy task', 'result' => 'failed']]);
    $opening->check->update(['type' => CheckKey::Cron]);
    dispatchNotifications();
    $event = Event::factory()->create(['site_id' => $opening->site_id, 'check_id' => $opening->check_id, 'incident_id' => $opening->incident_id,
        'type' => 'cron.task_result_changed', 'component_type' => 'cron', 'component_id' => 1,
        'payload_version' => 2, 'payload' => ['name' => 'Legacy task', 'from_result' => 'failed', 'result' => 'succeeded']]);
    dispatchNotifications();
    $request = Http::recorded()->last()[0];
    expect($request['downtime_seconds'])->toBeNull();
    expect($request['title'])->toContain('Legacy task');
    expect($request['since'])->toBe($event->occurred_at->toIso8601String());
    Http::assertSentCount(2);
});

it('uses an index to read the latest reminder event from retained history', function (): void {
    $target = notificationIncidentEvent();
    $other = notificationIncidentEvent();
    // Events snapshot their site identity since ab2baec2; the bulk rows copy it from the other incident's opener.
    DB::insert("INSERT INTO events (site_id, check_id, incident_id, type, occurred_at, payload, suppressed, suppression_evaluated_at,
            site_name, site_url, client_name, client_id, check_type)
        SELECT ?, ?, ?, 'incident.still_down', CURRENT_TIMESTAMP - (n * INTERVAL '1 second'), '{}'::jsonb, false, CURRENT_TIMESTAMP, ?, ?, ?, ?, ?
        FROM generate_series(1, 20000) AS n", [$other->site_id, $other->check_id, $other->incident_id,
        $other->site_name, $other->site_url, $other->client_name, $other->client_id, $other->check_type?->value]);
    DB::statement('ANALYZE events');
    $rows = QueryPlanWork::scannedRows("SELECT * FROM events WHERE incident_id = ?
        AND type IN ('incident.opened', 'incident.cause_changed', 'incident.still_down') ORDER BY occurred_at DESC LIMIT 1", [$target->incident_id]);
    expect($rows)->toBeLessThan(200);
});

it('requires a site on every event except instance digests and system issues', function (): void {
    // The model refuses first since ab2baec2; the database constraint still backs raw writes.
    expect(fn () => Event::factory()->create(['site_id' => null]))->toThrow(LogicException::class, 'Only digest and system issue events can be created without a site.');
    DB::beginTransaction();
    try {
        expect(fn () => DB::table('events')->insert(['type' => 'check.result_changed', 'occurred_at' => now(), 'payload' => '{}',
            'suppressed' => false, 'suppression_evaluated_at' => now()]))->toThrow(QueryException::class, 'events_site_required');
    } finally {
        DB::rollBack();
    }
});

it('uses the instance timezone for team quiet hours and digests', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-01T23:00:00Z'));
    $instance = app(InstanceSettings::class);
    $instance->timezone = 'Europe/Berlin';
    $instance->save();
    Http::preventStrayRequests();
    Http::fake(['https://receiver.test/alerts' => Http::response([], 200)]);
    $destination = notificationWebhook()->refresh();
    expect($destination->timezone())->toBe('Europe/Berlin');
    notificationSchedule($destination, ['quiet_start' => '22:00', 'quiet_end' => '07:00', 'digest_time' => '08:00']);
    notificationRule($destination, 'check.warning', NotificationMode::Digest);
    $warning = Event::factory()->create(['to_result' => CheckResult::Warning]);
    $warning->check->update(['latest_run_result' => CheckResult::Warning]);
    dispatchNotifications();
    expect(Delivery::query()->sole()->next_attempt_at->toIso8601String())->toBe('2026-10-02T06:00:00+00:00');
    $this->travelTo(CarbonImmutable::parse('2026-10-02T05:59:59Z'));
    dispatchNotifications();
    Http::assertNothingSent();
    $this->travel(1)->seconds();
    dispatchNotifications();
    Http::assertSentCount(1);
    expect(Event::query()->where('type', 'notification.digest')->count())->toBe(1);
});

it('suppresses only the API snoozed cron task through the recorder and notification dispatcher', function (): void {
    Http::preventStrayRequests();
    Http::fake(['https://receiver.test/alerts' => Http::response([], 200)]);
    notificationRule(notificationWebhook(), 'scheduled_task.missed');
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $this->withToken($owner->createToken('cron-snooze', ['*'])->plainTextToken);
    $check = Check::factory()->create(['type' => CheckKey::Cron]);
    $url = '/api/monitors/'.$check->site_id.'/cron-checks';
    $a = $this->postJson($url, ['name' => 'Quiet task', 'type' => 'simple', 'frequency_in_minutes' => 60])->assertCreated()->json();
    $b = $this->postJson($url, ['name' => 'Loud task', 'type' => 'simple', 'frequency_in_minutes' => 60])->assertCreated()->json();
    $this->postJson('/api/cron-checks/'.$a['id'].'/snooze', ['minutes' => 5])->assertOk();
    $this->postJson($a['ping_url'].'/failed')->assertNoContent();
    $this->postJson($b['ping_url'].'/failed')->assertNoContent();
    recordNotificationCron($check);
    dispatchNotifications();
    expect(Event::query()->where('component_id', $a['id'])->where('component_type', 'cron')->sole()->suppressed)->toBeTrue();
    expect(Event::query()->where('component_id', $b['id'])->where('component_type', 'cron')->sole()->suppressed)->toBeFalse();
    $requests = Http::recorded()->map(fn (array $pair): string => $pair[0]['title'])->all();
    expect(implode(' ', $requests))->toContain('Loud task')->not->toContain('Quiet task');
    $this->postJson('/api/cron-checks/'.$a['id'].'/unsnooze')->assertOk();
    $this->postJson($a['ping_url'])->assertNoContent();
    recordNotificationCron($check);
    $this->postJson($a['ping_url'].'/failed')->assertNoContent();
    recordNotificationCron($check);
    dispatchNotifications();
    expect(Event::query()->where('component_id', $a['id'])->where('component_type', 'cron')->where('suppressed', false)->count())->toBe(2);
});
