<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Actions\PlanNotificationEvent;
use Heptapod\Actions\SetNotificationDestinationEnabled;
use Heptapod\Actions\SnoozeCheck;
use Heptapod\Addons\Uptime\UptimeMetrics;
use Heptapod\Checks\Recording\RecordableRun;
use Heptapod\Checks\Recording\ResultRecorder;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckOutcome;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\Notifications\ChannelRegistry;
use Heptapod\Contracts\RunResult;
use Heptapod\Enums\ClientScope;
use Heptapod\Enums\NotificationCategory;
use Heptapod\Enums\NotificationMode;
use Heptapod\Enums\RecipientKind;
use Heptapod\Models\Check;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\User;
use Heptapod\Settings\InstanceSettings;
use Heptapod\Testing\ClaimedRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/../Support/NotificationChannels.php';

beforeEach(function (): void {
    // A Wednesday 10:00 UTC.
    $this->travelTo(CarbonImmutable::parse('2026-10-28T10:00:00Z'));
    config(['mail.default' => 'smtp']);
    $settings = app(InstanceSettings::class);
    $settings->timezone = 'UTC';
    $settings->alert_after_minutes = 10;
    $settings->alert_recovery_checks = 3;
    $settings->save();
});

function explainUser(string $role, array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $user->assignRole($role);

    return $user;
}

/** @return array<string, mixed> */
function explain(Check $check, NotificationCategory $category, ?CarbonImmutable $at = null, array $extra = []): array
{
    return test()->getJson(route('notifications.explain', ['check_id' => $check->id, 'category' => $category->value,
        'at' => ($at ?? CarbonImmutable::now('UTC'))->format('Y-m-d\TH:i:sP'), ...$extra]))->assertOk()->json();
}

/** @param array<string, mixed> $explanation
 * @return array<int, array{status: string, reason: string, due_at: string|null}>
 */
function explainedByChannel(array $explanation): array
{
    $rows = [];
    foreach ($explanation['recipients'] as $row) {
        $rows[$row['channel_id']] = ['status' => $row['status'], 'reason' => $row['reason'], 'due_at' => $row['due_at']];
    }

    return $rows;
}

function explainObservation(Check $check, RunResult $result = RunResult::Failed): void
{
    $at = CarbonImmutable::now('UTC');
    $context = ClaimedRun::start($check->fresh(), $at);
    app(ResultRecorder::class)->record(new RecordableRun($check->id, $context->claimToken, $at, $at,
        new CheckOutcome($result, 'uptime.status', [], new UptimeMetrics(null, null, null, null, null, 0.01,
            array_fill_keys(['namelookup_time', 'connect_time', 'appconnect_time', 'starttransfer_time', 'pretransfer_time', 'redirect_time', 'total_time', 'header_size', 'size_download', 'speed_download'], null), '2026-10-28'))));
}

it('explains each channel exactly as planning then schedules the same event', function (): void {
    Mail::fake();
    $owner = explainUser('Owner');
    $check = Check::factory()->create(['type' => CheckKey::Performance, 'latest_run_result' => CheckResult::Warning]);
    $now = notificationRoute(NotificationDestination::factory()->create(), [NotificationCategory::Warning]);
    $digest = notificationRoute(NotificationDestination::factory()->create(), [NotificationCategory::Warning], NotificationMode::Digest);
    $member = explainUser('Member', ['timezone' => 'Asia/Tokyo']);
    // A personal schedule: 10:00 UTC is 19:00 in Tokyo, inside 18:00–08:00 quiet hours.
    notificationSchedule(notificationAccountChannel($member, [NotificationCategory::Warning]), ['quiet_start' => '18:00', 'quiet_end' => '08:00']);
    $off = NotificationDestination::factory()->create();
    $paused = notificationRoute(NotificationDestination::factory()->create(), [NotificationCategory::Warning]);
    app(SetNotificationDestinationEnabled::class)($paused, false);
    $muted = notificationRoute(NotificationDestination::factory()->create(), [NotificationCategory::Warning]);
    notificationMuteSite($muted, $check->site_id);

    $this->actingAs($owner);
    $explanation = explain($check, NotificationCategory::Warning);
    $event = Event::factory()->create(['check_id' => $check->id, 'type' => 'check.result_changed', 'from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Warning]);
    // Planning only, at the explained instant: sending would move due times on.
    app(PlanNotificationEvent::class)($event->id);

    expect($explanation['status'])->toBe('explained');
    $explained = explainedByChannel($explanation);
    $accountChannel = NotificationDestination::query()->where('user_id', $member->id)->sole();
    $planned = Delivery::query()->where('event_id', $event->id)->get()->keyBy('destination_id');
    // Every channel the explanation would reach got a delivery due at the explained time; nothing else did.
    foreach ($explained as $channelId => $row) {
        if ($row['status'] === 'not_sent') {
            expect($planned->has($channelId))->toBeFalse();

            continue;
        }
        $delivery = $planned->get($channelId) ?? throw new LogicException('Channel '.$channelId.' was explained as reached but not planned.');
        expect($delivery->next_attempt_at->toIso8601String())->toBe(CarbonImmutable::parse($row['due_at'])->toIso8601String());
        expect($delivery->digest)->toBe($row['status'] === 'digest');
    }
    expect($explained[$now->id])->toMatchArray(['status' => 'now', 'reason' => 'now']);
    expect($explained[$digest->id])->toMatchArray(['status' => 'digest', 'due_at' => '2026-10-29T08:00:00+00:00']);
    expect($explained[$accountChannel->id])->toMatchArray(['status' => 'at', 'reason' => 'quiet_hours', 'due_at' => '2026-10-28T23:00:00+00:00']);
    expect($explained[$off->id])->toMatchArray(['status' => 'not_sent', 'reason' => 'category_off']);
    expect($explained[$paused->id])->toMatchArray(['status' => 'not_sent', 'reason' => 'paused']);
    expect($explained[$muted->id])->toMatchArray(['status' => 'not_sent', 'reason' => 'site_muted']);
});

it('marks mail that would only be logged, a delayed outage opener and a stopped check like planning does', function (): void {
    Mail::fake();
    config(['mail.default' => 'array']);
    $owner = explainUser('Owner');
    $check = Check::factory()->create(['failure_threshold' => 1]);
    $mail = notificationRoute(NotificationDestination::factory()->create(), [NotificationCategory::Outage]);
    $webhook = notificationRoute(notificationWebhook(), [NotificationCategory::Outage]);
    $this->actingAs($owner);

    expect(explainedByChannel(explain($check, NotificationCategory::Outage))[$mail->id])->toMatchArray(['status' => 'logged', 'reason' => 'mail_log_only']);
    explainObservation($check);
    // The real episode opening is explained: deferred until the saved alert deadline, the same time planning stores.
    $open = explainedByChannel(explain($check, NotificationCategory::Outage));
    dispatchNotifications();
    $opening = Event::query()->where('check_id', $check->id)->where('type', 'incident.opened')->sole();
    foreach ([$mail, $webhook] as $channel) {
        expect($open[$channel->id])->toMatchArray(['status' => 'at', 'reason' => 'notifications.preview_alert_delay', 'due_at' => '2026-10-28T10:09:00+00:00']);
        expect(Delivery::query()->where('event_id', $opening->id)->where('destination_id', $channel->id)->sole()->next_attempt_at->toIso8601String())
            ->toBe('2026-10-28T10:09:00+00:00');
    }
    $check->site->update(['paused_at' => now()]);
    expect(explainedByChannel(explain($check->fresh(), NotificationCategory::Outage))[$mail->id])
        ->toMatchArray(['status' => 'not_sent', 'reason' => 'monitoring_stopped']);
});

it('drops an episode opener already sent and defers one muted by a snooze', function (): void {
    Mail::fake();
    $owner = explainUser('Owner');
    $check = Check::factory()->create(['failure_threshold' => 1]);
    $first = notificationRoute(NotificationDestination::factory()->create(), [NotificationCategory::Outage]);
    explainObservation($check);
    dispatchNotifications();
    $this->travelTo(CarbonImmutable::parse('2026-10-28T10:09:00Z'));
    dispatchNotifications();
    $late = notificationRoute(NotificationDestination::factory()->create(), [NotificationCategory::Outage]);
    $this->actingAs($owner);

    $explained = explainedByChannel(explain($check, NotificationCategory::Outage));
    expect($explained[$first->id])->toMatchArray(['status' => 'not_sent', 'reason' => 'notifications.suppression.episode_already_alerted']);
    expect($explained[$late->id])->toMatchArray(['status' => 'now']);
    app(SnoozeCheck::class)($check->fresh(), $owner, CarbonImmutable::now('UTC')->addHour());
    expect(explainedByChannel(explain($check, NotificationCategory::Outage))[$late->id])
        ->toMatchArray(['status' => 'at', 'reason' => 'notifications.preview_opening_muted']);
});

it('keeps recoveries conditional without an incident and resolves them per channel for an open one', function (): void {
    Mail::fake();
    $owner = explainUser('Owner');
    $check = Check::factory()->create(['failure_threshold' => 1]);
    $alerted = notificationRoute(NotificationDestination::factory()->create(), [NotificationCategory::Outage, NotificationCategory::Recovery]);
    $this->actingAs($owner);

    expect(explainedByChannel(explain($check, NotificationCategory::Recovery))[$alerted->id])
        ->toMatchArray(['status' => 'conditional', 'reason' => 'recovery_needs_opener']);
    explainObservation($check);
    dispatchNotifications();
    $waiting = notificationRoute(NotificationDestination::factory()->create(), [NotificationCategory::Recovery]);
    expect(explainedByChannel(explain($check, NotificationCategory::Recovery))[$alerted->id])->toMatchArray(['status' => 'conditional', 'reason' => 'opener_pending']);
    $this->travelTo(CarbonImmutable::parse('2026-10-28T10:09:00Z'));
    dispatchNotifications();

    $explained = explainedByChannel(explain($check, NotificationCategory::Recovery, extra: ['recovery_origin' => CheckResult::Failed->value]));
    expect($explained[$alerted->id]['status'])->toBe('now');
    expect($explained[$waiting->id])->toMatchArray(['status' => 'not_sent', 'reason' => 'no_opener']);
});

it('explains a recovery after pause and resume exactly as planning admits it', function (): void {
    Mail::fake();
    $owner = explainUser('Owner');
    $check = Check::factory()->create(['failure_threshold' => 1]);
    $paused = notificationRoute(NotificationDestination::factory()->create(), [NotificationCategory::Outage, NotificationCategory::Recovery]);
    $kept = notificationRoute(NotificationDestination::factory()->create(), [NotificationCategory::Outage, NotificationCategory::Recovery]);
    explainObservation($check);
    dispatchNotifications();
    // Paused during the alert delay: the waiting opener is discarded; resuming restores nothing.
    app(SetNotificationDestinationEnabled::class)($paused, false);
    app(SetNotificationDestinationEnabled::class)($paused, true);
    $this->actingAs($owner);

    $explained = explainedByChannel(explain($check, NotificationCategory::Recovery, extra: ['recovery_origin' => CheckResult::Failed->value]));
    $incident = $check->incidents()->sole();
    $incident->update(['resolved_at' => now()]);
    $recovery = notificationRecoveryEvent(Event::query()->where('incident_id', $incident->id)->where('type', 'incident.opened')->sole());
    app(PlanNotificationEvent::class)($recovery->id);
    $planned = Delivery::query()->where('event_id', $recovery->id)->pluck('destination_id')->all();

    expect($explained[$paused->id])->toMatchArray(['status' => 'not_sent', 'reason' => 'no_opener'])
        ->and($planned)->not->toContain($paused->id);
    expect($explained[$kept->id])->toMatchArray(['status' => 'conditional', 'reason' => 'opener_pending']);
});

it('reports categories a check cannot emit as not applicable', function (CheckKey $type, NotificationCategory $category): void {
    $owner = explainUser('Owner');
    notificationRoute(NotificationDestination::factory()->create(), [$category]);
    $this->actingAs($owner);

    expect(explain(Check::factory()->create(['type' => $type]), $category))
        ->toBe(['status' => 'not_applicable', 'category' => $category->value, 'exemplar' => null, 'recipients' => [], 'timezone' => 'UTC']);
})->with([
    'expiry on DNS' => [CheckKey::Dns, NotificationCategory::Expiring],
    'changes on uptime' => [CheckKey::Uptime, NotificationCategory::Changes],
]);

it('masks other recipients addresses for Members and hides checks they cannot see', function (): void {
    $member = explainUser('Member', ['client_scope' => ClientScope::Restricted]);
    $check = Check::factory()->create(['type' => CheckKey::CertificateHealth]);
    $member->clients()->attach($check->site->client_id);
    $team = notificationRoute(NotificationDestination::factory()->create(), [NotificationCategory::Expiring]);
    $own = notificationAccountChannel($member, [NotificationCategory::Expiring]);
    $hidden = Check::factory()->create();
    $this->actingAs($member);

    // A client contact migrated without a name is labelled with its address (migration 2026_10_30_000003).
    $contact = NotificationDestination::factory()->create(['recipient_kind' => RecipientKind::Client, 'client_id' => $check->site->client_id,
        'locale' => null, 'label' => 'contact@client.test',
        'config' => app(ChannelRegistry::class)->get('mail')->configSchema()->validateConfig(['email' => 'contact@client.test'])]);

    $response = $this->getJson(route('notifications.explain', ['check_id' => $check->id, 'category' => 'expiring', 'at' => '2026-10-28T10:00:00+00:00']))->assertOk();
    $rows = collect($response->json('recipients'))->keyBy('channel_id');
    expect($rows[$team->id])->toMatchArray(['address' => 'Hidden recipient', 'label' => 'Hidden recipient'])
        ->and($rows[$own->id]['address'])->toBe($member->email);
    expect($rows[$contact->id])->toMatchArray(['address' => 'Hidden recipient', 'label' => 'Hidden recipient', 'status' => 'not_sent']);
    expect($response->getContent())->not->toContain('contact@client.test', $team->destinationConfig()->string('email'));
    $this->getJson(route('notifications.explain', ['check_id' => $hidden->id, 'category' => 'outage', 'at' => '2026-10-28T10:00:00+00:00']))->assertNotFound();
    $this->getJson(route('notifications.explain', ['check_id' => $check->id, 'category' => 'system', 'at' => '2026-10-28T10:00:00+00:00']))->assertUnprocessable();
    $this->getJson(route('notifications.explain', ['check_id' => $check->id, 'category' => 'outage', 'at' => '2026-10-28T10:00:00+00:00',
        'recovery_origin' => 'failed']))->assertUnprocessable();
    $this->actingAs(explainUser('Owner'));
    expect(collect(explain($check, NotificationCategory::Expiring)['recipients'])->keyBy('channel_id')[$team->id]['address'])
        ->toBe($team->destinationConfig()->string('email'));
});

it('explains a failure grouped under an uptime outage as not sent, the same decision planning makes', function (): void {
    $owner = explainUser('Owner');
    $uptime = Check::factory()->create(['type' => CheckKey::Uptime, 'latest_run_result' => CheckResult::Failed]);
    $outage = Incident::factory()->for($uptime)->create(['first_failure_at' => now()->subMinutes(10), 'opened_at' => now()->subMinutes(10)]);
    $lighthouse = Check::factory()->for($uptime->site)->create(['type' => CheckKey::Lighthouse, 'latest_run_result' => CheckResult::Failed]);
    $dependent = Incident::factory()->for($lighthouse)->create(['first_failure_at' => now()->subMinutes(5), 'opened_at' => now()->subMinutes(5),
        'evidence' => ['uptime_parent_incident_id' => $outage->id]]);
    $channel = notificationRoute(NotificationDestination::factory()->create(), [NotificationCategory::Failure]);
    $this->actingAs($owner);

    $explained = explainedByChannel(explain($lighthouse, NotificationCategory::Failure));
    $opened = Event::factory()->create(['check_id' => $lighthouse->id, 'incident_id' => $dependent->id, 'type' => 'incident.opened', 'cause' => 'failed',
        'to_result' => CheckResult::Failed]);
    app(PlanNotificationEvent::class)($opened->id);

    expect($explained[$channel->id])->toMatchArray(['status' => 'not_sent', 'reason' => 'uptime_dependency']);
    expect(Delivery::query()->where('event_id', $opened->id)->sole()->only(['suppressed', 'suppression_reason']))
        ->toBe(['suppressed' => true, 'suppression_reason' => 'uptime_dependency']);
});

it('matches a recovery to openers of its own kind, never to another incidents accepted opener', function (): void {
    Mail::fake();
    $owner = explainUser('Owner');
    $check = Check::factory()->create(['type' => CheckKey::Performance, 'latest_run_result' => CheckResult::Failed]);
    $channel = notificationRoute(NotificationDestination::factory()->create(), [NotificationCategory::Failure, NotificationCategory::Warning, NotificationCategory::Recovery]);
    $failure = notificationIncidentEvent(['check_id' => $check->id, 'site_id' => $check->site_id]);
    Incident::query()->whereKey($failure->incident_id)->update(['check_id' => $check->id, 'site_id' => $check->site_id]);
    Delivery::query()->create(['event_id' => $failure->id, 'destination_id' => $channel->id, 'state' => 'sent', 'sent_at' => now(),
        'next_attempt_at' => now(), 'suppression_evaluated_at' => now()]);
    $this->actingAs($owner);

    // The open failed incident's accepted opener authorizes its own recovery only.
    expect(explainedByChannel(explain($check, NotificationCategory::Recovery, extra: ['recovery_origin' => 'failed']))[$channel->id]['status'])->toBe('now');
    expect(explainedByChannel(explain($check, NotificationCategory::Recovery, extra: ['recovery_origin' => 'errored-or-timed-out']))[$channel->id])
        ->toMatchArray(['status' => 'conditional', 'reason' => 'recovery_needs_opener']);
    expect(explainedByChannel(explain($check, NotificationCategory::Recovery, extra: ['recovery_origin' => 'warning']))[$channel->id])
        ->toMatchArray(['status' => 'conditional', 'reason' => 'recovery_needs_opener']);
    // A warning recovery needs an opener in its own warning episode: with one accepted, it is resolved.
    $warning = Event::factory()->create(['check_id' => $check->id, 'type' => 'check.result_changed', 'from_result' => CheckResult::Succeeded,
        'to_result' => CheckResult::Warning, 'occurred_at' => now()->subMinute()]);
    Delivery::query()->create(['event_id' => $warning->id, 'destination_id' => $channel->id, 'state' => 'sent', 'sent_at' => now(),
        'next_attempt_at' => now(), 'suppression_evaluated_at' => now()]);
    expect(explainedByChannel(explain($check, NotificationCategory::Recovery, extra: ['recovery_origin' => 'warning']))[$channel->id]['status'])->toBe('now');
});

it('explains many channels with a fixed number of queries', function (): void {
    Mail::fake();
    $owner = explainUser('Owner');
    $check = Check::factory()->create(['failure_threshold' => 1]);
    $addChannels = static function (int $count): void {
        for ($i = 0; $i < $count; $i++) {
            notificationRoute(NotificationDestination::factory()->create(), [NotificationCategory::Outage, NotificationCategory::Recovery]);
        }
    };
    $addChannels(2);
    explainObservation($check);
    dispatchNotifications();
    $this->actingAs($owner);
    $measure = function (NotificationCategory $category) use ($check): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        explain($check, $category);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };
    $measure(NotificationCategory::Outage);
    $few = [$measure(NotificationCategory::Outage), $measure(NotificationCategory::Recovery)];
    $addChannels(10);

    expect([$measure(NotificationCategory::Outage), $measure(NotificationCategory::Recovery)])->toBe($few);
});
