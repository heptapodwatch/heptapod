<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Actions\ClaimNotificationDelivery;
use Heptapod\Actions\CreateEmailDestination;
use Heptapod\Actions\EmitNotificationDigests;
use Heptapod\Actions\EnsureAccountChannel;
use Heptapod\Actions\PlanNotificationEvent;
use Heptapod\Actions\SetNotificationDestinationEnabled;
use Heptapod\Actions\UpdateProfile;
use Heptapod\Addons\Channels\TemplateMail;
use Heptapod\Addons\Uptime\UptimeMetrics;
use Heptapod\Checks\Recording\RecordableRun;
use Heptapod\Checks\Recording\ResultRecorder;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckOutcome;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\Notifications\ChannelRegistry;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Contracts\RunResult;
use Heptapod\Enums\ClientScope;
use Heptapod\Enums\NotificationCategory;
use Heptapod\Enums\NotificationMode;
use Heptapod\Enums\RecipientKind;
use Heptapod\Events\Notifications\NotificationRecipients;
use Heptapod\Models\Check;
use Heptapod\Models\Client;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\Invitation;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\NotificationPreference;
use Heptapod\Models\User;
use Heptapod\Notifications\TeamInvitation;
use Heptapod\Settings\InstanceSettings;
use Heptapod\Testing\ClaimedRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/NotificationChannels.php';

beforeEach(function (): void {
    // A Wednesday 12:00 UTC; quiet-hour cases travel explicitly.
    $this->travelTo(CarbonImmutable::parse('2026-10-28T12:00:00Z'));
    config(['mail.default' => 'smtp']);
    $settings = app(InstanceSettings::class);
    $settings->timezone = 'UTC';
    $settings->alert_after_minutes = 1;
    $settings->alert_recovery_checks = 1;
    $settings->save();
});

function planningCheckEvent(CheckResult $result, CheckKey $type = CheckKey::Performance, ?Check $check = null): Event
{
    $check ??= Check::factory()->create(['type' => $type, 'latest_run_result' => $result]);

    return Event::factory()->create(['check_id' => $check->id, 'type' => 'check.result_changed',
        'from_result' => CheckResult::Succeeded, 'to_result' => $result]);
}

function planningFailingEvent(CheckResult $result): Event
{
    $event = notificationIncidentEvent(['to_result' => $result, 'cause' => $result === CheckResult::Errored ? 'errored' : 'failed']);
    $event->check->update(['type' => CheckKey::Performance]);
    $event->update(['check_type' => CheckKey::Performance]);

    return $event->fresh();
}

function planningOutage(): Event
{
    $check = Check::factory()->create(['failure_threshold' => 1]);
    $context = ClaimedRun::start($check, CarbonImmutable::now('UTC'));
    app(ResultRecorder::class)->record(new RecordableRun($check->id, $context->claimToken, $context->startedAt, CarbonImmutable::now('UTC'),
        new CheckOutcome(RunResult::Failed, 'uptime.status', [], new UptimeMetrics(503, null, null, null, null, 0.01,
            array_fill_keys(['namelookup_time', 'connect_time', 'appconnect_time', 'starttransfer_time', 'pretransfer_time', 'redirect_time', 'total_time', 'header_size', 'size_download', 'speed_download'], null), '2026-10-28'))));

    return Event::query()->where('check_id', $check->id)->where('type', 'incident.opened')->sole();
}

/** @return list<int> */
function plannedChannels(Event $event): array
{
    /** @var list<int> */
    return Delivery::query()->where('event_id', $event->id)->where('suppressed', false)->orderBy('destination_id')->pluck('destination_id')->all();
}

it('routes each event to the category its grid cell names, immediately or into the digest', function (): void {
    $immediate = notificationRoute(NotificationDestination::factory()->create(), [NotificationCategory::Warning]);
    $digest = notificationRoute(NotificationDestination::factory()->create(), [NotificationCategory::Warning], NotificationMode::Digest);
    $off = NotificationDestination::factory()->create();
    $warning = planningCheckEvent(CheckResult::Warning);

    dispatchNotifications();

    expect(plannedChannels($warning))->toBe([$immediate->id, $digest->id]);
    expect(Delivery::query()->where('destination_id', $immediate->id)->sole()->only(['digest', 'category']))->toBe(['digest' => false, 'category' => NotificationCategory::Warning]);
    $queued = Delivery::query()->where('destination_id', $digest->id)->sole();
    expect($queued->digest)->toBeTrue()->and($queued->next_attempt_at->toIso8601String())->toBe('2026-10-29T08:00:00+00:00');
    expect(Delivery::query()->where('destination_id', $off->id)->exists())->toBeFalse();
});

it('separates Failure from Check error and an expiring domain from other warnings', function (): void {
    $failure = notificationRoute(NotificationDestination::factory()->create(), [NotificationCategory::Failure]);
    $checkError = notificationRoute(NotificationDestination::factory()->create(), [NotificationCategory::CheckError]);
    $expiring = notificationRoute(NotificationDestination::factory()->create(), [NotificationCategory::Expiring]);
    $failed = planningFailingEvent(CheckResult::Failed);
    $errored = planningFailingEvent(CheckResult::Errored);
    $domain = planningCheckEvent(CheckResult::Warning, CheckKey::Domain);
    $performance = planningCheckEvent(CheckResult::Warning);

    dispatchNotifications();

    expect(plannedChannels($failed))->toBe([$failure->id])
        ->and(plannedChannels($errored))->toBe([$checkError->id])
        ->and(plannedChannels($domain))->toBe([$expiring->id])
        ->and(plannedChannels($performance))->toBe([]);
});

it('keeps Changes off by default for team and user channels and routes them once turned on', function (): void {
    $member = User::factory()->member()->create();
    $team = app(CreateEmailDestination::class)(['address' => 'team@example.test', 'label' => 'Team', 'locale' => 'en']);
    $account = app(EnsureAccountChannel::class)($member);
    $check = Check::factory()->create(['type' => CheckKey::Dns]);
    $changed = Event::factory()->create(['check_id' => $check->id, 'type' => 'dns.records_changed', 'from_result' => CheckResult::Succeeded,
        'to_result' => CheckResult::Warning, 'payload' => ['result' => 'warning', 'from_result' => 'succeeded', 'types' => 'A', 'added' => 1, 'removed' => 0]]);

    dispatchNotifications();

    expect(plannedChannels($changed))->toBe([]);
    notificationRoute($team, [NotificationCategory::Changes]);
    $again = Event::factory()->create(['check_id' => $check->id, 'type' => 'dns.records_changed', 'from_result' => CheckResult::Succeeded,
        'to_result' => CheckResult::Warning, 'payload' => ['result' => 'warning', 'from_result' => 'succeeded', 'types' => 'A', 'added' => 1, 'removed' => 0]]);
    dispatchNotifications();
    expect(plannedChannels($again))->toBe([$team->id]);
    expect(DB::table('notification_preferences')->where('destination_id', $account->id)->where('category', 'changes')->value('mode'))->toBe('off');
});

it('skips a muted site, a paused channel and a channel scoped to another client', function (): void {
    $muted = notificationRoute(NotificationDestination::factory()->create(), [NotificationCategory::Warning]);
    $paused = notificationRoute(NotificationDestination::factory()->create(), [NotificationCategory::Warning]);
    $scopedAway = notificationRoute(NotificationDestination::factory()->create(['scope_client_id' => Client::factory()->create()->id]), [NotificationCategory::Warning]);
    $warning = planningCheckEvent(CheckResult::Warning);
    $scopedHere = notificationRoute(NotificationDestination::factory()->create(['scope_client_id' => $warning->client_id]), [NotificationCategory::Warning]);
    notificationMuteSite($muted, $warning->site_id);
    app(SetNotificationDestinationEnabled::class)($paused, false);

    dispatchNotifications();

    expect(plannedChannels($warning))->toBe([$scopedHere->id]);
    expect([$scopedAway->id, $muted->id, $paused->id])->not->toContain(...plannedChannels($warning));
});

it('keeps client channels silent by default and routes an opted-in category to the client of the site only', function (): void {
    $warning = planningCheckEvent(CheckResult::Warning);
    $config = app(ChannelRegistry::class)->get('mail')->configSchema()->validateConfig(['email' => 'client@example.test']);
    $client = NotificationDestination::factory()->create(['recipient_kind' => RecipientKind::Client, 'client_id' => $warning->client_id, 'locale' => null, 'config' => $config]);
    $otherClient = NotificationDestination::factory()->create(['recipient_kind' => RecipientKind::Client, 'client_id' => Client::factory()->create()->id, 'locale' => null, 'config' => $config]);
    expect(array_unique(DB::table('notification_preferences')->where('destination_id', $client->id)->pluck('mode')->all()))->toBe(['off']);

    dispatchNotifications();
    expect(plannedChannels($warning))->toBe([]);

    notificationRoute($client, [NotificationCategory::Warning]);
    notificationRoute($otherClient, [NotificationCategory::Warning]);
    $again = planningCheckEvent(CheckResult::Warning, check: $warning->check);
    dispatchNotifications();
    expect(plannedChannels($again))->toBe([$client->id]);
    expect(NotificationPreference::defaults(RecipientKind::Client))->not->toContain(NotificationMode::Immediate);
});

it('suppresses a client channel delivery at claim when its site moved to another client', function (): void {
    Mail::fake();
    $warning = planningCheckEvent(CheckResult::Warning);
    $config = app(ChannelRegistry::class)->get('mail')->configSchema()->validateConfig(['email' => 'client@example.test']);
    $client = notificationRoute(NotificationDestination::factory()->create(['recipient_kind' => RecipientKind::Client, 'client_id' => $warning->client_id,
        'locale' => null, 'config' => $config]), [NotificationCategory::Warning]);
    app(PlanNotificationEvent::class)($warning->id);
    $delivery = Delivery::query()->where('destination_id', $client->id)->sole();
    // MoveSiteToClient refuses while work is queued; a retry that outlives a move is the case this guard covers.
    DB::table('sites')->where('id', $warning->site_id)->update(['client_id' => Client::factory()->create()->id]);

    app(ClaimNotificationDelivery::class)($delivery->id);

    expect($delivery->fresh()->suppression_reason)->toBe('client_site_moved');
    Mail::assertNothingSent();
});

it('stops a user channel for sites the user can no longer see', function (): void {
    $member = User::factory()->member()->create(['client_scope' => ClientScope::Restricted]);
    $warning = planningCheckEvent(CheckResult::Warning);
    $channel = notificationAccountChannel($member, [NotificationCategory::Warning]);

    dispatchNotifications();
    expect(plannedChannels($warning))->toBe([]);

    $member->clients()->sync([$warning->client_id]);
    $again = planningCheckEvent(CheckResult::Warning, check: $warning->check);
    dispatchNotifications();
    expect(plannedChannels($again))->toBe([$channel->id]);
});

it('lets Down bypass quiet hours while a warning waits for their end', function (): void {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-28T23:00:00Z'));
    $channel = notificationRoute(NotificationDestination::factory()->create(), [NotificationCategory::Failure, NotificationCategory::Warning]);
    notificationSchedule($channel, ['quiet_start' => '22:00', 'quiet_end' => '07:00']);
    $failed = planningFailingEvent(CheckResult::Failed);
    $warning = planningCheckEvent(CheckResult::Warning);

    dispatchNotifications();

    $down = Delivery::query()->where('event_id', $failed->id)->sole();
    expect($down->state)->toBe(DeliveryState::Sent)->and($down->sent_at->toIso8601String())->toBe('2026-10-28T23:00:00+00:00');
    expect(Delivery::query()->where('event_id', $warning->id)->sole()->only(['state', 'next_attempt_at']))
        ->toEqual(['state' => DeliveryState::Pending, 'next_attempt_at' => CarbonImmutable::parse('2026-10-29T07:00:00Z')]);
});

it('holds a personal warning over a quiet weekend until Monday in the user time zone while a failure goes now', function (): void {
    Mail::fake();
    // Saturday 2026-10-31 10:00 UTC is 11:00 in Berlin.
    $this->travelTo(CarbonImmutable::parse('2026-10-31T10:00:00Z'));
    $member = User::factory()->admin()->create(['timezone' => 'Europe/Berlin']);
    $channel = notificationAccountChannel($member, [NotificationCategory::Failure, NotificationCategory::Warning]);
    notificationSchedule($channel, ['quiet_weekends' => true, 'quiet_start' => '22:00', 'quiet_end' => '07:30']);
    $failed = planningFailingEvent(CheckResult::Failed);
    $warning = planningCheckEvent(CheckResult::Warning);

    dispatchNotifications();

    expect(Delivery::query()->where('event_id', $failed->id)->sole()->state)->toBe(DeliveryState::Sent);
    // Monday 2026-11-02 07:30 in Berlin (after the switch to CET) is 06:30 UTC.
    expect(Delivery::query()->where('event_id', $warning->id)->sole()->only(['state', 'next_attempt_at', 'digest']))
        ->toEqual(['state' => DeliveryState::Pending, 'next_attempt_at' => CarbonImmutable::parse('2026-11-02T06:30:00Z'), 'digest' => false]);
});

it('reconciles an open outage when an Outage, Failure or Check error cell turns on or a channel resumes', function (NotificationCategory $category): void {
    Mail::fake();
    $opening = planningOutage();
    dispatchNotifications();
    $channel = NotificationDestination::factory()->create();
    expect($opening->incident->fresh()->routing_reconcile_requested_at)->toBeNull();

    notificationRoute($channel, [$category]);

    expect($opening->incident->fresh()->routing_reconcile_requested_at)->not->toBeNull();
    app(SetNotificationDestinationEnabled::class)($channel, false);
    DB::table('incidents')->update(['routing_reconcile_requested_at' => null]);
    app(SetNotificationDestinationEnabled::class)($channel, true);
    expect($opening->incident->fresh()->routing_reconcile_requested_at)->not->toBeNull();
    dispatchNotifications();
    expect($opening->incident->fresh()->routing_reconcile_requested_at)->toBeNull();
    // Only an Outage cell reaches an uptime outage opener; the others only request the recheck.
    expect(Delivery::query()->where('destination_id', $channel->id)->where('state', 'sent')->count())->toBe($category === NotificationCategory::Outage ? 1 : 0);
})->with([NotificationCategory::Outage, NotificationCategory::Failure, NotificationCategory::CheckError]);

it('does not request a reconciliation when a cell other than an opening category turns on', function (): void {
    $opening = planningOutage();
    dispatchNotifications();

    notificationRoute(NotificationDestination::factory()->create(), [NotificationCategory::Warning, NotificationCategory::Recovery]);

    expect($opening->incident->fresh()->routing_reconcile_requested_at)->toBeNull();
});

it('throws instead of guessing when a channel has no grid row for the category', function (): void {
    $channel = notificationRoute(NotificationDestination::factory()->create(), [NotificationCategory::Warning]);
    DB::table('notification_preferences')->where('destination_id', $channel->id)->where('category', 'warning')->delete();
    $warning = planningCheckEvent(CheckResult::Warning);

    expect(fn () => app(PlanNotificationEvent::class)($warning->id))
        ->toThrow(LogicException::class, 'Notification grid row missing for channel '.$channel->id.' and warning.');
    expect(Delivery::query()->count())->toBe(0);
});

it('holds planned account mail while a changed address is unverified, then sends it to the new address', function (): void {
    Mail::fake();
    $owner = User::factory()->owner()->create(['email' => 'old@example.test']);
    $channel = notificationAccountChannel($owner, [NotificationCategory::Warning]);
    notificationSchedule($channel, ['quiet_start' => '11:00', 'quiet_end' => '13:00']);
    $warning = planningCheckEvent(CheckResult::Warning);
    dispatchNotifications();
    $delivery = Delivery::query()->where('event_id', $warning->id)->sole();
    expect($delivery->state)->toBe(DeliveryState::Pending);
    app(UpdateProfile::class)($owner, ['email' => 'new@example.test']);
    $this->travelTo(CarbonImmutable::parse('2026-10-28T13:00:00Z'));

    dispatchNotifications();
    $delivery->refresh();
    expect($delivery->destination_id)->toBe($channel->id)
        ->and($delivery->state)->toBe(DeliveryState::Pending)->and($delivery->last_reason)->toBe('personal_email_unverified');
    Mail::assertNothingSent();

    $owner->forceFill(['email_verified_at' => now()])->save();
    $this->travel(1)->hours();
    dispatchNotifications();

    expect($delivery->fresh()->state)->toBe(DeliveryState::Sent);
    Mail::assertSent(TemplateMail::class, fn (TemplateMail $mail): bool => $mail->hasTo('new@example.test'));
    expect(json_decode($channel->fresh()->getAttributes()['config'], true))->toBe([]);
});

it('gives invited Admins and Members the outage, failure and recovery defaults and System per role', function (string $role, string $system): void {
    Notification::fake();
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner)->postJson(route('settings.members.invite'), ['email' => 'new@example.test', 'role' => $role,
        'client_ids' => $role === 'Member' ? [Client::factory()->create()->id] : []])->assertCreated();
    $url = null;
    Notification::assertSentOnDemand(TeamInvitation::class, function (TeamInvitation $notification) use (&$url): bool {
        $url = $notification->actionUrl();

        return true;
    });
    $this->post(route('logout'));

    $this->post(route('invitations.accept', basename((string) parse_url((string) $url, PHP_URL_PATH))), ['name' => 'New', 'password' => 'password',
        'password_confirmation' => 'password'])->assertRedirect();

    $user = User::query()->where('email', 'new@example.test')->sole();
    $channel = NotificationDestination::query()->where('user_id', $user->id)->sole();
    expect($channel->account_mail)->toBeTrue()->and($channel->recipient_kind)->toBe(RecipientKind::User);
    expect(DB::table('notification_preferences')->where('destination_id', $channel->id)->orderBy('category')->pluck('mode', 'category')->all())
        ->toBe(['changes' => 'off', 'check_error' => 'off', 'expiring' => 'off', 'failure' => 'immediate', 'outage' => 'immediate',
            'recovery' => 'immediate', 'system' => $system, 'warning' => 'off']);
    expect(Invitation::query()->exists())->toBeFalse();
})->with(['Admin' => ['Admin', 'off'], 'Member' => ['Member', 'off']]);

it('keeps queued manual sends and account System mail when a channel is paused and still sends them', function (): void {
    Mail::fake();
    $owner = User::factory()->owner()->create();
    $channel = notificationAccountChannel($owner, [NotificationCategory::Warning]);
    $warning = planningCheckEvent(CheckResult::Warning);
    app(PlanNotificationEvent::class)($warning->id);
    $automatic = Delivery::query()->where('event_id', $warning->id)->sole();
    $manualEvent = planningCheckEvent(CheckResult::Warning);
    $manual = Delivery::query()->create(['event_id' => $manualEvent->id, 'destination_id' => $channel->id, 'next_attempt_at' => now(),
        'suppression_evaluated_at' => now(), 'origin' => 'manual', 'actor_id' => $owner->id, 'actor_name' => $owner->name,
        'recipient_snapshot' => ['address' => $owner->email, 'name' => $owner->name, 'locale' => 'en']]);
    $systemEvent = planningCheckEvent(CheckResult::Warning);
    $system = Delivery::query()->create(['event_id' => $systemEvent->id, 'destination_id' => $channel->id, 'next_attempt_at' => now(),
        'suppression_evaluated_at' => now(), 'category' => NotificationCategory::System]);
    $team = notificationRoute(NotificationDestination::factory()->create(), [NotificationCategory::Warning]);
    $teamSystem = Delivery::query()->create(['event_id' => $systemEvent->id, 'destination_id' => $team->id, 'next_attempt_at' => now(),
        'suppression_evaluated_at' => now(), 'category' => NotificationCategory::System]);

    expect(app(SetNotificationDestinationEnabled::class)($channel, false))->toBe(1);
    expect(app(SetNotificationDestinationEnabled::class)($team, false))->toBe(1);

    expect($automatic->fresh()->suppression_reason)->toBe('destination_disabled');
    expect($teamSystem->fresh()->suppression_reason)->toBe('destination_disabled');
    foreach ([$manual, $system] as $kept) {
        expect($kept->fresh()->suppressed)->toBeFalse();
        $claimed = app(ClaimNotificationDelivery::class)($kept->id);
        expect($claimed?->state)->toBe(DeliveryState::Sending);
    }
});

it('lets a user test their own account channel and Owners and Admins a colleagues, rate limited and audited', function (): void {
    Mail::fake();
    $colleague = User::factory()->member()->create();
    $channel = app(EnsureAccountChannel::class)($colleague);
    $other = User::factory()->member()->create();
    $admin = User::factory()->admin()->create();
    $this->actingAs($other)->postJson(route('notification-channels.test', $channel))->assertForbidden();
    $this->actingAs($colleague)->postJson(route('notification-channels.test', $channel))->assertOk();
    $own = Activity::query()->where('event', 'notification.test_requested')->latest('id')->firstOrFail();
    expect($own->causer_id)->toBe($colleague->id);
    $this->actingAs($admin);
    for ($i = 0; $i < 6; $i++) {
        // The limit is per actor and channel: the colleague's own test does not count against the Admin.
        $this->postJson(route('notification-channels.test', $channel))->assertOk();
    }
    $this->postJson(route('notification-channels.test', $channel))->assertTooManyRequests();
    $audit = Activity::query()->where('event', 'notification.test_requested')->latest('id')->firstOrFail();
    expect($audit->causer_id)->toBe($admin->id)->and($audit->subject_id)->toBe($channel->id);
    Mail::assertSent(TemplateMail::class, fn (TemplateMail $mail): bool => $mail->hasTo($colleague->email));
});

it('drops revoked client sources from a mixed-client account digest', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05T00:30:00Z'));
    $a = Check::factory()->create();
    $b = Check::factory()->create();
    $member = User::factory()->member()->create(['client_scope' => ClientScope::Restricted]);
    $member->clients()->attach([$a->site->client_id, $b->site->client_id]);
    $channel = notificationAccountChannel($member, [NotificationCategory::Warning]);
    DB::table('notification_preferences')->where('destination_id', $channel->id)->where('category', 'warning')->update(['mode' => 'digest']);
    foreach ([$a, $b] as $check) {
        $check->update(['latest_run_result' => CheckResult::Warning]);
        $event = Event::factory()->create(['site_id' => $check->site_id, 'check_id' => $check->id,
            'type' => 'check.result_changed', 'from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Warning]);
        app(PlanNotificationEvent::class)($event->id);
    }
    $this->travelTo(CarbonImmutable::parse('2026-10-05T08:00:00Z'));
    app(EmitNotificationDigests::class)();
    $digest = Event::query()->where('type', 'notification.digest')->sole();
    expect($digest->payload['count'])->toBe(2);
    $member->clients()->detach($b->site->client_id);
    $delivery = Delivery::query()->where('event_id', $digest->id)->sole();

    expect(app(ClaimNotificationDelivery::class)($delivery->id))->not->toBeNull()
        ->and($digest->refresh()->payload['count'])->toBe(1)
        ->and($digest->payload['sites'])->toBe($a->site->displayName());
    $revoked = Delivery::query()->where('destination_id', $channel->id)
        ->whereHas('event', fn ($query) => $query->where('site_id', $b->site_id))->sole();
    expect($revoked->refresh()->suppression_reason)->toBe('personal_site_access_revoked');
});

it('routes an event to many channels with a bounded number of queries', function (int $channels): void {
    $warning = planningCheckEvent(CheckResult::Warning);
    for ($i = 0; $i < $channels; $i++) {
        $user = User::factory()->member()->create();
        notificationSchedule(notificationAccountChannel($user, [NotificationCategory::Warning]), ['quiet_start' => '22:00', 'quiet_end' => '07:00']);
        notificationRoute(NotificationDestination::factory()->create(), [NotificationCategory::Warning]);
    }
    $event = $warning->fresh(['site', 'check', 'incident']);
    DB::flushQueryLog();
    DB::enableQueryLog();

    $routes = app(NotificationRecipients::class)->routes($event, CarbonImmutable::now('UTC'));

    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();
    expect($routes)->toHaveCount(2 * $channels);
    // Grid, channels, users, site access and schedules: independent of the number of channels.
    expect($queries)->toBeLessThanOrEqual(6);
})->with([2, 20]);
