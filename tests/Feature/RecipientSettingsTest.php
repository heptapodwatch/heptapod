<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Actions\CreateOwner;
use Heptapod\Actions\EnsureAccountChannel;
use Heptapod\Actions\SetNotificationDestinationEnabled;
use Heptapod\Addons\Channels\CommunityChannel;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\Notifications\ChannelRegistry;
use Heptapod\Contracts\Notifications\ChannelType;
use Heptapod\Contracts\Schema\Schema as ChannelSchema;
use Heptapod\Enums\ClientScope;
use Heptapod\Enums\NotificationCategory;
use Heptapod\Enums\NotificationMode;
use Heptapod\Enums\RecipientKind;
use Heptapod\Models\Check;
use Heptapod\Models\Client;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\NotificationSchedule;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/NotificationChannels.php';

beforeEach(function (): void {
    // A Wednesday 12:00 UTC.
    $this->travelTo(CarbonImmutable::parse('2026-10-28T12:00:00Z'));
    config(['mail.default' => 'smtp']);
    $settings = app(InstanceSettings::class);
    $settings->timezone = 'UTC';
    $settings->save();
});

function recipientUser(string $role, array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $user->assignRole($role);

    return $user;
}

/** @return array<string, string> */
function recipientGrid(int $channelId): array
{
    /** @var array<string, string> */
    return DB::table('notification_preferences')->where('destination_id', $channelId)->orderBy('category')->pluck('mode', 'category')->all();
}

function recipientWebhook(): array
{
    return ['channel' => 'webhook', 'label' => 'Ops hook', 'config' => ['url' => 'https://receiver.test/alerts', 'secret' => 'private-webhook-secret']];
}

it('refuses to list an addon channel without its own description in both languages', function (string $missingLocale, string $providedLocale): void {
    $owner = recipientUser('Owner');
    Lang::addLines(['community.description' => 'Community channel description.'], $providedLocale);
    $channel = new CommunityChannel('community', 'notifications.channel.mail', 'community.description', 'mail', new ChannelSchema([]), app(MailChannel::class), 'email');
    app()->instance('community-channel', $channel);
    app()->tag(['community-channel'], ChannelType::class);
    Exceptions::fake();

    $this->actingAs($owner)->getJson(route('settings.notifications.team.data'))->assertInternalServerError();

    Exceptions::assertReported(fn (LogicException $exception): bool => $exception->getMessage() === 'Channel community description translation missing: community.description ('.$missingLocale.').');
})->with([
    'missing EN' => ['en', 'de'],
    'missing DE without fallback to EN' => ['de', 'en'],
]);

it('creates team, own and client channels with the design defaults and refuses a second mail address for a user', function (): void {
    $owner = recipientUser('Owner');
    $member = recipientUser('Member');
    $client = Client::factory()->create();

    $team = $this->actingAs($owner)->postJson(route('notification-channels.store'), ['recipient_kind' => 'team', 'channel' => 'mail', 'label' => 'Ops',
        'locale' => 'de', 'config' => ['email' => 'ops@example.test']])->assertCreated()->json('id');
    $clientChannel = $this->postJson(route('notification-channels.store'), ['recipient_kind' => 'client', 'client_id' => $client->id, 'channel' => 'mail',
        'label' => 'Shop office', 'config' => ['email' => 'office@shop.test']])->assertCreated()->json('id');
    $this->actingAs($member)->postJson(route('notification-channels.store'), ['recipient_kind' => 'user', 'channel' => 'mail', 'label' => 'Second',
        'config' => ['email' => 'second@example.test']])->assertUnprocessable()
        ->assertJsonValidationErrors(['channel' => 'Your email alerts use your account address.']);
    $own = $this->postJson(route('notification-channels.store'), ['recipient_kind' => 'user', ...recipientWebhook()])->assertCreated()->json('id');

    expect(NotificationDestination::query()->findOrFail($team)->recipient_kind)->toBe(RecipientKind::Team)
        ->and(NotificationDestination::query()->findOrFail($clientChannel)->client_id)->toBe($client->id)
        ->and(NotificationDestination::query()->findOrFail($own)->user_id)->toBe($member->id);
    // Design 1.3: team hears everything except Changes and System; clients nothing; a Member's channel outages, failures and recoveries.
    expect(recipientGrid($team))->toBe(['changes' => 'off', 'check_error' => 'immediate', 'expiring' => 'immediate', 'failure' => 'immediate',
        'outage' => 'immediate', 'recovery' => 'immediate', 'system' => 'off', 'warning' => 'immediate']);
    expect(array_unique(recipientGrid($clientChannel)))->toBe(['changes' => 'off']);
    expect(recipientGrid($own))->toBe(['changes' => 'off', 'check_error' => 'off', 'expiring' => 'off', 'failure' => 'immediate',
        'outage' => 'immediate', 'recovery' => 'immediate', 'system' => 'off', 'warning' => 'off']);
});

it('lets only the people who manage a recipient change its channels', function (): void {
    $owner = recipientUser('Owner');
    $admin = recipientUser('Admin');
    $member = recipientUser('Member');
    $client = Client::factory()->create();
    $hiddenClient = Client::factory()->create();
    $restricted = recipientUser('Member', ['client_scope' => ClientScope::Restricted]);
    $restricted->clients()->attach($client);
    $team = NotificationDestination::factory()->create();
    $clientChannel = NotificationDestination::factory()->create(['recipient_kind' => RecipientKind::Client, 'client_id' => $client->id, 'locale' => null]);
    $memberAccount = app(EnsureAccountChannel::class)($member);
    $ownerAccount = app(EnsureAccountChannel::class)($owner);
    $patch = fn (NotificationDestination $channel) => $this->patchJson(route('notification-channels.update', $channel), ['label' => 'Renamed']);

    $this->actingAs($member);
    $patch($team)->assertForbidden();
    $patch($clientChannel)->assertNotFound();
    $patch($ownerAccount)->assertForbidden();
    $this->postJson(route('notification-channels.store'), ['recipient_kind' => 'team', 'channel' => 'mail', 'label' => 'x', 'locale' => 'en',
        'config' => ['email' => 'x@example.test']])->assertForbidden();
    $this->postJson(route('notification-channels.store'), ['recipient_kind' => 'client', 'client_id' => $hiddenClient->id, 'channel' => 'mail',
        'label' => 'x', 'config' => ['email' => 'x@example.test']])->assertNotFound();
    $this->putJson(route('notification-channels.preferences', $team), ['preferences' => ['outage' => 'off']])->assertForbidden();
    $this->deleteJson(route('notification-channels.destroy', $team))->assertForbidden();
    $this->getJson(route('settings.notifications.team.data'))->assertForbidden();
    $this->getJson(route('clients.notifications.data', $client))->assertNotFound();
    $patch($memberAccount)->assertOk();
    $this->actingAs($restricted)->getJson(route('clients.notifications.data', $client))->assertNotFound();
    $this->actingAs($admin);
    $patch($team)->assertOk();
    $patch($clientChannel)->assertOk();
    $patch($memberAccount)->assertForbidden();
    $this->getJson(route('clients.notifications.data', $client))->assertOk();
    expect(DB::table('notification_preferences')->where('destination_id', $team->id)->where('category', 'outage')->value('mode'))->toBe('off');
});

it('lets a Member test their own account channel only, with the rate limit and the audit', function (): void {
    Mail::fake();
    $member = recipientUser('Member');
    $colleague = recipientUser('Member');
    $own = app(EnsureAccountChannel::class)($member);
    $other = app(EnsureAccountChannel::class)($colleague);
    $team = NotificationDestination::factory()->create();
    $this->actingAs($member);

    $this->postJson(route('notification-channels.test', $own))->assertOk()->assertJsonPath('status', 'sent');
    $this->postJson(route('notification-channels.test', $other))->assertForbidden();
    $this->postJson(route('notification-channels.test', $team))->assertForbidden();
    $audit = Activity::query()->where('event', 'notification.test_requested')->sole();
    expect($audit->causer_id)->toBe($member->id)->and($audit->subject_id)->toBe($own->id);
    for ($i = 0; $i < 5; $i++) {
        $this->postJson(route('notification-channels.test', $own))->assertOk();
    }
    $this->postJson(route('notification-channels.test', $own))->assertTooManyRequests();
});

it('edits a channel without echoing secrets and asks for the secret again before the target origin changes', function (): void {
    Http::preventStrayRequests();
    Http::fake();
    $owner = recipientUser('Owner');
    $id = $this->actingAs($owner)->postJson(route('notification-channels.store'), ['recipient_kind' => 'team', 'locale' => 'en', ...recipientWebhook()])->json('id');

    $this->patchJson(route('notification-channels.update', $id), ['label' => 'Renamed', 'config' => ['url' => 'https://receiver.test/alerts', 'secret' => '********']])->assertOk();
    expect(NotificationDestination::query()->findOrFail($id)->destinationConfig()->string('secret'))->toBe('private-webhook-secret');
    $this->patchJson(route('notification-channels.update', $id), ['config' => ['url' => 'https://elsewhere.test/hook', 'secret' => '********']])
        ->assertUnprocessable()->assertJsonValidationErrors('config.secret');
    $this->patchJson(route('notification-channels.update', $id), ['config' => ['url' => 'https://elsewhere.test/hook', 'secret' => 'fresh-secret']])->assertOk();
    $channel = NotificationDestination::query()->findOrFail($id);
    expect($channel->label)->toBe('Renamed')->and($channel->destinationConfig()->values())->toBe(['url' => 'https://elsewhere.test/hook', 'secret' => 'fresh-secret']);
    $page = $this->getJson(route('settings.notifications.team.data'))->assertOk();
    expect($page->getContent())->not->toContain('fresh-secret', 'private-webhook-secret');
    $account = app(EnsureAccountChannel::class)($owner);
    $this->patchJson(route('notification-channels.update', $account), ['config' => ['email' => 'other@example.test']])->assertUnprocessable();
});

it('names an invalid channel setting by its translated label, never by its request path', function (string $locale, string $email, string $url): void {
    $owner = recipientUser('Owner', ['locale' => $locale]);
    $this->actingAs($owner)->postJson(route('notification-channels.store'), ['recipient_kind' => 'team', 'channel' => 'mail', 'label' => 'Ops',
        'locale' => 'en', 'config' => ['email' => 'not an email']])->assertUnprocessable()->assertJsonValidationErrors(['values.email' => $email]);
    $this->postJson(route('notification-channels.store'), ['recipient_kind' => 'team', 'locale' => 'en', 'channel' => 'webhook', 'label' => 'Hook',
        'config' => ['url' => 'http://receiver.test/alerts', 'secret' => 'private-webhook-secret']])->assertUnprocessable()->assertJsonValidationErrors(['values.url' => $url]);
})->with([
    'en' => ['en', 'The Email address field must be a valid email address.', 'The URL field must be a valid URL.'],
    'de' => ['de', 'E-Mail-Adresse muss eine gültige E-Mail-Adresse sein.', 'URL muss eine URL sein.'],
]);

it('reconciles open outages when repaired settings resume a channel that needed attention', function (): void {
    $owner = recipientUser('Owner');
    $id = $this->actingAs($owner)->postJson(route('notification-channels.store'), ['recipient_kind' => 'team', 'locale' => 'en', ...recipientWebhook()])->json('id');
    $check = Check::factory()->create();
    $incident = Incident::factory()->create(['check_id' => $check->id, 'alert_episode_started_at' => now()->subMinutes(2),
        'alert_after_minutes' => 10, 'alert_recovery_checks' => 3]);
    DB::table('notification_destinations')->where('id', $id)->update(['needs_attention' => true, 'enabled' => false,
        'attention_reason' => 'notifications.secret_too_short']);
    Incident::query()->whereKey($incident->id)->update(['routing_reconcile_requested_at' => null]);

    $this->patchJson(route('notification-channels.update', $id), ['label' => 'Renamed only'])->assertOk();
    expect($incident->fresh()->routing_reconcile_requested_at)->toBeNull();
    $this->patchJson(route('notification-channels.update', $id), ['config' => ['url' => 'https://receiver.test/alerts', 'secret' => 'renewed-secret']])->assertOk();

    $channel = NotificationDestination::query()->findOrFail($id);
    expect($channel->enabled)->toBeTrue()->and($channel->needs_attention)->toBeFalse();
    expect($incident->fresh()->routing_reconcile_requested_at)->not->toBeNull();
});

it('validates grid modes, keeps System locked for an Owner and hidden from Members and clients', function (): void {
    $owner = recipientUser('Owner');
    $member = recipientUser('Member');
    $ownerAccount = app(EnsureAccountChannel::class)($owner);
    $memberAccount = app(EnsureAccountChannel::class)($member);
    $client = Client::factory()->create();
    $clientChannel = NotificationDestination::factory()->create(['recipient_kind' => RecipientKind::Client, 'client_id' => $client->id, 'locale' => null]);
    $this->actingAs($owner);

    $this->putJson(route('notification-channels.preferences', $ownerAccount), ['preferences' => ['outage' => 'digest']])->assertUnprocessable();
    $this->putJson(route('notification-channels.preferences', $ownerAccount), ['preferences' => ['report' => 'immediate']])->assertUnprocessable();
    $this->putJson(route('notification-channels.preferences', $ownerAccount), ['preferences' => ['system' => 'off']])->assertUnprocessable();
    $this->putJson(route('notification-channels.preferences', $clientChannel), ['preferences' => ['system' => 'immediate']])->assertUnprocessable();
    $this->putJson(route('notification-channels.preferences', $clientChannel), ['preferences' => ['outage' => 'immediate', 'warning' => 'digest']])->assertNoContent();
    expect(recipientGrid($clientChannel->id))->toMatchArray(['outage' => 'immediate', 'warning' => 'digest', 'system' => 'off']);
    expect(Activity::query()->where('event', 'notification.preferences_updated')->sole()->subject_id)->toBe($clientChannel->id);

    // The System cell is on and locked on the Owner's account email only (SystemIssueMailer::systemLockedOn), even when the
    // stored cell says off, because claim sends it regardless; the Owner's other channels keep System editable.
    DB::table('notification_preferences')->where('destination_id', $ownerAccount->id)->where('category', 'system')->update(['mode' => 'off']);
    $ownSlack = $this->postJson(route('notification-channels.store'), ['recipient_kind' => 'user', ...recipientWebhook()])->assertCreated()->json('id');
    $ownerPage = $this->getJson(route('settings.notifications.data'))->assertOk();
    $ownerChannels = collect($ownerPage->json('channels'))->keyBy('id');
    expect(collect($ownerPage->json('grid'))->firstWhere('category', 'system'))->toBe(['category' => 'system', 'modes' => ['immediate', 'off']])
        ->and(collect($ownerPage->json('grid'))->firstWhere('category', 'warning')['modes'])->toBe(['immediate', 'digest', 'off'])
        ->and($ownerChannels[$ownerAccount->id]['system_locked'])->toBeTrue()
        ->and($ownerChannels[$ownerAccount->id]['preferences']['system'])->toBe('immediate')
        ->and($ownerChannels[$ownSlack]['system_locked'])->toBeFalse();
    // Pausing an Owner's account email still lets system issues through (claim ignores pause there); their other channels do not.
    $this->patchJson(route('notification-channels.enabled', $ownerAccount), ['enabled' => false])->assertOk();
    $this->patchJson(route('notification-channels.enabled', $ownSlack), ['enabled' => false])->assertOk();
    $paused = collect($this->getJson(route('settings.notifications.data'))->json('channels'))->keyBy('id');
    expect($paused[$ownerAccount->id])->toMatchArray(['status' => 'paused', 'system_bypasses_pause' => true])
        ->and($paused[$ownSlack])->toMatchArray(['status' => 'paused', 'system_bypasses_pause' => false]);
    // A person adds only non-mail channels (their mail is the account email); the team and clients get every type.
    expect(collect($ownerPage->json('channel_types'))->pluck('key'))->not->toContain('mail')->toContain('webhook')
        ->and(collect($this->getJson(route('clients.notifications.data', $client))->json('channel_types'))->pluck('key'))->toContain('mail');
    $this->putJson(route('notification-channels.preferences', $ownSlack), ['preferences' => ['system' => 'off']])->assertNoContent();
    expect(collect($this->getJson(route('clients.notifications.data', $client))->json('grid'))->pluck('category'))->not->toContain('system', 'report');
    $this->actingAs($member);
    expect(collect($this->getJson(route('settings.notifications.data'))->json('grid'))->pluck('category'))->not->toContain('system');
    $this->putJson(route('notification-channels.preferences', $memberAccount), ['preferences' => ['system' => 'immediate']])->assertUnprocessable();
});

it('reconciles open outages when an outage cell turns on and when a site mute is removed', function (): void {
    $owner = recipientUser('Owner');
    $channel = NotificationDestination::factory()->create();
    $check = Check::factory()->create();
    $incident = Incident::factory()->create(['check_id' => $check->id, 'alert_episode_started_at' => now()->subMinutes(2),
        'alert_after_minutes' => 10, 'alert_recovery_checks' => 3]);
    $this->actingAs($owner);

    $this->putJson(route('notification-channels.preferences', $channel), ['preferences' => ['warning' => 'immediate']])->assertNoContent();
    expect($incident->fresh()->routing_reconcile_requested_at)->toBeNull();
    $this->putJson(route('notification-channels.site-mutes.store', [$channel, $check->site_id]))->assertNoContent();
    $this->putJson(route('notification-channels.preferences', $channel), ['preferences' => ['outage' => 'immediate']])->assertNoContent();
    expect($incident->fresh()->routing_reconcile_requested_at)->not->toBeNull();
    Incident::query()->whereKey($incident->id)->update(['routing_reconcile_requested_at' => null]);

    $this->deleteJson(route('notification-channels.site-mutes.destroy', [$channel, $check->site_id]))->assertNoContent();
    expect($incident->fresh()->routing_reconcile_requested_at)->not->toBeNull();
    expect(Activity::query()->whereIn('event', ['notification.site_muted', 'notification.site_unmuted'])->orderBy('id')->pluck('event')->all())
        ->toBe(['notification.site_muted', 'notification.site_unmuted']);
});

it('refuses a client channel mute on another client and a mute on a site the actor cannot see', function (): void {
    $admin = recipientUser('Admin');
    $client = Client::factory()->create();
    $clientChannel = NotificationDestination::factory()->create(['recipient_kind' => RecipientKind::Client, 'client_id' => $client->id, 'locale' => null]);
    $foreign = Site::factory()->create();
    $member = recipientUser('Member', ['client_scope' => ClientScope::Restricted]);
    $memberHook = NotificationDestination::factory()->create(['recipient_kind' => RecipientKind::User, 'user_id' => $member->id, 'locale' => null,
        'channel' => 'webhook', 'config' => app(ChannelRegistry::class)->get('webhook')->configSchema()->validateConfig(recipientWebhook()['config'])]);

    $this->actingAs($admin)->putJson(route('notification-channels.site-mutes.store', [$clientChannel, $foreign]))->assertUnprocessable();
    $this->actingAs($member)->putJson(route('notification-channels.site-mutes.store', [$memberHook, $foreign]))->assertNotFound();
    expect(DB::table('notification_site_mutes')->count())->toBe(0);
});

it('sends waiting digest items on their immediate schedule when a Digest cell becomes Immediate', function (): void {
    $owner = recipientUser('Owner');
    $channel = notificationRoute(NotificationDestination::factory()->create(), [NotificationCategory::Warning, NotificationCategory::CheckError], NotificationMode::Digest);
    notificationSchedule($channel, ['quiet_start' => '22:00', 'quiet_end' => '07:00']);
    $warning = Event::factory()->create(['check_id' => Check::factory()->create(['type' => CheckKey::Performance, 'latest_run_result' => CheckResult::Warning])->id,
        'type' => 'check.result_changed', 'from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Warning]);
    $error = notificationIncidentEvent(['to_result' => CheckResult::Errored, 'cause' => 'errored']);
    $error->check->update(['type' => CheckKey::Performance]);
    dispatchNotifications();
    $warningItem = Delivery::query()->where('event_id', $warning->id)->sole();
    $errorItem = Delivery::query()->where('event_id', $error->id)->sole();
    expect($warningItem->digest)->toBeTrue()->and($errorItem->digest)->toBeTrue();
    expect($warningItem->next_attempt_at->toIso8601String())->toBe('2026-10-29T08:00:00+00:00');

    $this->travelTo(CarbonImmutable::parse('2026-10-28T23:00:00Z'));
    $this->actingAs($owner)->putJson(route('notification-channels.preferences', $channel), ['preferences' => ['warning' => 'immediate']])->assertNoContent();

    // A warning respects quiet hours; the check error item stays in the digest because its cell did not change.
    expect($warningItem->fresh()->digest)->toBeFalse()->and($warningItem->fresh()->next_attempt_at->toIso8601String())->toBe('2026-10-29T07:00:00+00:00');
    expect($errorItem->fresh()->digest)->toBeTrue()->and($errorItem->fresh()->next_attempt_at->toIso8601String())->toBe('2026-10-29T08:00:00+00:00');
});

it('pauses, resumes and archives channels with the queued count, keeping the account channel', function (): void {
    $owner = recipientUser('Owner');
    $channel = notificationRoute(NotificationDestination::factory()->create(), [NotificationCategory::Warning]);
    $event = Event::factory()->create();
    // A planned item waiting for its time: what the pause discards and the archive would suppress.
    Delivery::query()->create(['event_id' => $event->id, 'destination_id' => $channel->id, 'next_attempt_at' => now()->addHour(),
        'suppression_evaluated_at' => now()]);
    $this->actingAs($owner);
    expect(collect($this->getJson(route('settings.notifications.team.data'))->json('channels'))->firstWhere('id', $channel->id))
        ->toMatchArray(['status' => 'active', 'queued_count' => 1, 'can_remove' => true]);

    $this->patchJson(route('notification-channels.enabled', $channel), ['enabled' => false])->assertOk()->assertJsonPath('suppressed_count', 1);
    expect(collect($this->getJson(route('settings.notifications.team.data'))->json('channels'))->firstWhere('id', $channel->id))
        ->toMatchArray(['status' => 'paused', 'queued_count' => 0]);
    $this->patchJson(route('notification-channels.enabled', $channel), ['enabled' => true])->assertOk()->assertJsonPath('enabled', true);
    $this->deleteJson(route('notification-channels.destroy', $channel))->assertOk()->assertJsonPath('suppressed_count', 0);
    expect($channel->fresh()->removed_reason)->toBe('archived')->and(Delivery::query()->where('event_id', $event->id)->exists())->toBeTrue();
    $this->patchJson(route('notification-channels.update', $channel), ['label' => 'Back'])->assertNotFound();
    expect(collect($this->getJson(route('settings.notifications.team.data'))->json('channels'))->pluck('id'))->not->toContain($channel->id);
    $account = app(EnsureAccountChannel::class)($owner);
    $this->deleteJson(route('notification-channels.destroy', $account))->assertUnprocessable();
    expect($account->fresh()->removed_at)->toBeNull();
});

it('saves each recipients schedule and rejects incomplete or impossible ones', function (): void {
    $owner = recipientUser('Owner');
    $member = recipientUser('Member', ['timezone' => 'Europe/Berlin']);
    $client = Client::factory()->create(['timezone' => 'America/New_York']);
    $this->actingAs($owner);

    $this->patchJson(route('notification-schedules.update', ['kind' => 'team']), ['quiet_start' => '22:00'])->assertUnprocessable();
    $this->patchJson(route('notification-schedules.update', ['kind' => 'team']), ['quiet_weekends' => true, 'digest_weekdays' => [6, 7]])
        ->assertUnprocessable()->assertJsonValidationErrors('digest_weekdays');
    $this->patchJson(route('notification-schedules.update', ['kind' => 'team']), ['quiet_start' => '22:00', 'quiet_end' => '07:00', 'digest_time' => '09:30'])
        ->assertOk()->assertJsonPath('digest_time', '09:30');
    $this->patchJson(route('notification-schedules.update', ['kind' => 'client', 'id' => $client->id]), ['digest_weekdays' => [5, 1]])
        ->assertOk()->assertJsonPath('digest_weekdays', [1, 5]);
    $this->patchJson(route('notification-schedules.update', ['kind' => 'user', 'id' => $member->id]), ['digest_time' => '06:00'])->assertForbidden();
    $this->actingAs($member)->patchJson(route('notification-schedules.update', ['kind' => 'user']), ['quiet_start' => '21:00', 'quiet_end' => '06:00'])->assertOk();
    $this->patchJson(route('notification-schedules.update', ['kind' => 'team']), ['digest_time' => '06:00'])->assertForbidden();
    $this->patchJson(route('notification-schedules.update', ['kind' => 'client', 'id' => $client->id]), ['digest_time' => '06:00'])->assertNotFound();

    expect(NotificationSchedule::query()->where('recipient_kind', 'team')->sole()->only(['quiet_start', 'quiet_end', 'digest_time']))
        ->toBe(['quiet_start' => '22:00', 'quiet_end' => '07:00', 'digest_time' => '09:30']);
    expect(NotificationSchedule::query()->where('user_id', $member->id)->sole()->quiet_start)->toBe('21:00');
    expect($this->getJson(route('settings.notifications.data'))->json('schedule'))->toMatchArray(['quiet_start' => '21:00', 'timezone' => 'Europe/Berlin']);
    expect($this->actingAs($owner)->getJson(route('clients.notifications.data', $client))->json('schedule'))
        ->toMatchArray(['digest_weekdays' => [1, 5], 'timezone' => 'America/New_York']);
});

it('tells the shell whether anyone would hear about an outage, counting only reachable recipients', function (string $case, bool $covered): void {
    $owner = recipientUser('Owner');
    $user = recipientUser('Member');
    $site = Site::factory()->create();
    $channel = notificationAccountChannel($user, [NotificationCategory::Outage]);
    match ($case) {
        'verified active user' => null,
        'muted site' => notificationMuteSite($channel, $site->id),
        'muted site beside an unmuted one' => [notificationMuteSite($channel, $site->id), Site::factory()->create()],
        'restricted member without the client' => $user->forceFill(['client_scope' => ClientScope::Restricted])->save(),
        'restricted member of the client' => [$user->forceFill(['client_scope' => ClientScope::Restricted])->save(), $user->clients()->attach($site->client_id)],
        'unverified address' => $user->forceFill(['email_verified_at' => null])->save(),
        'disabled user' => $user->forceFill(['disabled_at' => now()])->save(),
        'paused channel' => app(SetNotificationDestinationEnabled::class)($channel, false),
        'needs attention' => DB::table('notification_destinations')->where('id', $channel->id)->update(['needs_attention' => true, 'attention_reason' => 'notifications.secret_too_short']),
        'outage off' => DB::table('notification_preferences')->where('destination_id', $channel->id)->update(['mode' => 'off']),
    };
    // The Owner's own account channel never hears outages here.
    DB::table('notification_preferences')->where('destination_id', app(EnsureAccountChannel::class)($owner)->id)->update(['mode' => 'off']);

    $this->actingAs($owner)->get('/sites')->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('overallHealth.notifications_covered', $covered));
})->with([
    'verified active user' => ['verified active user', true],
    'muted site' => ['muted site', false],
    'muted site beside an unmuted one' => ['muted site beside an unmuted one', true],
    'restricted member without the client' => ['restricted member without the client', false],
    'restricted member of the client' => ['restricted member of the client', true],
    'unverified address' => ['unverified address', false],
    'disabled user' => ['disabled user', false],
    'paused channel' => ['paused channel', false],
    'needs attention' => ['needs attention', false],
    'outage off' => ['outage off', false],
]);

it('counts every channel type in the incident timeline and lists repairs only to who can make them', function (): void {
    $owner = recipientUser('Owner');
    $member = recipientUser('Member');
    $check = Check::factory()->create();
    $incident = Incident::factory()->create(['check_id' => $check->id, 'opened_at' => now()->subMinutes(5), 'first_failure_at' => now()->subMinutes(5)]);
    $opened = Event::factory()->create(['check_id' => $check->id, 'incident_id' => $incident->id, 'type' => 'incident.opened', 'cause' => 'failed',
        'occurred_at' => now()->subMinutes(5), 'to_result' => CheckResult::Failed]);
    $mail = NotificationDestination::factory()->create();
    $hook = notificationWebhook();
    foreach ([$mail, $hook] as $channel) {
        Delivery::query()->create(['event_id' => $opened->id, 'destination_id' => $channel->id, 'state' => 'sent', 'sent_at' => now(),
            'next_attempt_at' => now(), 'suppression_evaluated_at' => now()]);
    }
    $broken = notificationWebhook();
    $own = NotificationDestination::factory()->create(['recipient_kind' => RecipientKind::User, 'user_id' => $member->id, 'locale' => null,
        'channel' => 'webhook', 'config' => app(ChannelRegistry::class)->get('webhook')->configSchema()->validateConfig(recipientWebhook()['config'])]);
    DB::table('notification_destinations')->whereIn('id', [$broken->id, $own->id])->update(['needs_attention' => true, 'enabled' => false,
        'attention_reason' => 'notifications.secret_too_short']);

    $this->actingAs($owner)->get('/incidents/'.$incident->id)->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->where('timeline.1.notification_group.recipient_count', 2)->where('timeline.1.notification_group.accepted_count', 2)
        ->where('timeline.1.notification_group.recipients', fn ($recipients): bool => collect($recipients)->sort()->values()->all()
            === collect([$mail->destinationConfig()->string('email'), $hook->label])->sort()->values()->all())
        ->where('notificationAttention', fn ($rows): bool => collect($rows)->pluck('id')->sort()->values()->all() === [$broken->id]));
    $this->actingAs($member)->get('/incidents/'.$incident->id)->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        // Labels can hold addresses: a Member sees neither the team mail address nor the team webhook's label.
        ->where('timeline.1.notification_group.recipients', fn ($recipients): bool => collect($recipients)->all() === ['Hidden recipient', 'Hidden recipient'])
        ->where('notificationAttention', fn ($rows): bool => collect($rows)->pluck('id')->all() === [$own->id]
            && collect($rows)->first()['recipient_kind'] === 'user'));
});

it('reads the settings page with a fixed number of queries and exposes the stored migration report on the team page only', function (): void {
    $owner = recipientUser('Owner');
    $site = Site::factory()->create();
    $addChannels = static function (int $count) use ($site): void {
        for ($i = 0; $i < $count; $i++) {
            $channel = notificationRoute(NotificationDestination::factory()->create(), [NotificationCategory::Outage]);
            notificationMuteSite($channel, $site->id);
            Delivery::query()->create(['event_id' => Event::factory()->create()->id, 'destination_id' => $channel->id, 'next_attempt_at' => now(),
                'suppression_evaluated_at' => now()]);
        }
    };
    $measure = function (): array {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $page = $this->getJson(route('settings.notifications.team.data'))->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$page, $queries];
    };
    activity('audit')->event('notifications.migrated')->withProperties(['lines' => ['Team channel 1: rule window dropped']])->log('notifications.migrated');
    $this->actingAs($owner);
    $addChannels(2);
    $measure();
    [$few, $fewQueries] = $measure();
    $addChannels(10);

    [$many, $manyQueries] = $measure();

    expect($few->json('channels'))->toHaveCount(2)->and($many->json('channels'))->toHaveCount(12);
    expect($many->json('channels.11'))->toMatchArray(['muted_site_ids' => [$site->id], 'queued_count' => 1]);
    // Site exceptions carry what tells same-named sites apart: client, host and icon.
    expect(collect($many->json('sites'))->firstWhere('id', $site->id))->toMatchArray(['client_name' => $site->client?->name,
        'host' => parse_url($site->url, PHP_URL_HOST), 'url' => $site->url, 'icon_backing' => 'none']);
    expect($manyQueries)->toBe($fewQueries);
    expect($many->json('migration_report'))->toBe(['Team channel 1: rule window dropped']);
    expect($this->getJson(route('settings.notifications.data'))->json('migration_report'))->toBeNull();
});

it('shows the stored migration report until the Owner dismisses it for the instance', function (): void {
    activity('audit')->event('notifications.migrated')->withProperties(['lines' => ['Team channel 1: rule window dropped']])->log('notifications.migrated');
    $reports = Activity::query()->where('event', 'notifications.migrated')->count();
    $owner = recipientUser('Owner');
    $admin = recipientUser('Admin');
    $dismiss = route('settings.notifications.team.migration-report.dismiss');

    $this->actingAs($admin)->getJson(route('settings.notifications.team.data'))->assertOk()->assertJsonPath('migration_report', ['Team channel 1: rule window dropped']);
    $this->deleteJson($dismiss)->assertForbidden();
    $this->actingAs(recipientUser('Member'))->deleteJson($dismiss)->assertForbidden();
    $this->actingAs($owner)->deleteJson($dismiss)->assertNoContent();
    // A repeated dismissal (a second tab, a double click) records nothing more.
    $this->deleteJson($dismiss)->assertNoContent();

    $this->getJson(route('settings.notifications.team.data'))->assertOk()->assertJsonPath('migration_report', null);
    $this->actingAs($admin)->get(route('settings.notifications.team'))->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->where('settings.migration_report', null));
    expect(Activity::query()->where('event', 'notifications.migration_report_dismissed')->sole()->causer_id)->toBe($owner->id)
        ->and(Activity::query()->where('event', 'notifications.migrated')->count())->toBe($reports);
});

it('exposes the grid each channel started with as its defaults, for every recipient kind and the first Owner', function (): void {
    $firstOwner = app(CreateOwner::class)('first@example.test', 'First Owner', 'Local-test-password-2026!');
    $owner = recipientUser('Owner');
    $member = recipientUser('Member');
    app(EnsureAccountChannel::class)($owner);
    app(EnsureAccountChannel::class)($member);
    $this->actingAs($owner);
    $team = $this->postJson(route('notification-channels.store'), ['recipient_kind' => 'team', 'channel' => 'mail', 'label' => 'Ops',
        'locale' => 'en', 'config' => ['email' => 'ops@example.test']])->assertCreated()->json('id');
    $this->putJson(route('notification-channels.preferences', $team), ['preferences' => ['warning' => 'digest']])->assertNoContent();
    $factoryMade = NotificationDestination::factory()->create();
    $now = 'immediate';

    $teamChannels = collect($this->getJson(route('settings.notifications.team.data'))->json('channels'))->keyBy('id');
    // Design 1.3; a changed cell leaves the baseline alone, and a channel made outside the actions has none.
    expect($teamChannels[$team]['defaults'])->toBe(['changes' => 'off', 'check_error' => $now, 'expiring' => $now, 'failure' => $now,
        'outage' => $now, 'recovery' => $now, 'system' => 'off', 'warning' => $now])
        ->and($teamChannels[$team]['preferences']['warning'])->toBe('digest')
        ->and($teamChannels[$factoryMade->id]['defaults'])->toBeNull();
    expect($this->getJson(route('settings.notifications.data'))->json('channels.0.defaults'))->toBe(['changes' => 'off', 'check_error' => 'off',
        'expiring' => 'off', 'failure' => $now, 'outage' => $now, 'recovery' => $now, 'system' => $now, 'warning' => 'off']);
    expect($this->actingAs($firstOwner)->getJson(route('settings.notifications.data'))->json('channels.0.defaults'))->toBe(['changes' => 'off',
        'check_error' => $now, 'expiring' => $now, 'failure' => $now, 'outage' => $now, 'recovery' => $now, 'system' => $now, 'warning' => $now]);
    // A Member never sees System, so their defaults leave it out like their grid does.
    expect($this->actingAs($member)->getJson(route('settings.notifications.data'))->json('channels.0.defaults'))->toBe(['changes' => 'off',
        'check_error' => 'off', 'expiring' => 'off', 'failure' => $now, 'outage' => $now, 'recovery' => $now, 'warning' => 'off']);
});

it('keeps the grid every existing channel has as its baseline when the defaults column arrives', function (): void {
    $converted = notificationRoute(NotificationDestination::factory()->create(), [NotificationCategory::Outage, NotificationCategory::System]);
    $path = 'database/migrations/2026_10_30_000005_add_default_preferences_to_notification_destinations.php';
    Schema::table('notification_destinations', function (Blueprint $table): void {
        $table->dropColumn('default_preferences');
    });
    DB::table('migrations')->where('migration', basename($path, '.php'))->delete();

    expect(Artisan::call('migrate', ['--path' => $path, '--force' => true, '--no-interaction' => true]))->toBe(0);

    expect($converted->refresh()->default_preferences)->toEqual(recipientGrid($converted->id))
        ->and($converted->default_preferences['system'])->toBe('immediate');
    expect(fn () => (require base_path($path))->down())->toThrow(LogicException::class);
});

it('words schedule and channel validation with the form labels in the reader language', function (string $locale, string $days, string $quiet, string $label): void {
    $this->actingAs(recipientUser('Owner', ['locale' => $locale]));

    $this->patchJson(route('notification-schedules.update', ['kind' => 'team']), ['digest_weekdays' => []])
        ->assertUnprocessable()->assertJsonValidationErrors(['digest_weekdays' => $days]);
    $this->patchJson(route('notification-schedules.update', ['kind' => 'team']), ['quiet_start' => '22:00'])
        ->assertUnprocessable()->assertJsonValidationErrors(['quiet_end' => $quiet]);
    $this->postJson(route('notification-channels.store'), ['recipient_kind' => 'team', 'channel' => 'mail', 'label' => '', 'locale' => 'en',
        'config' => ['email' => 'ops@example.test']])->assertUnprocessable()->assertJsonValidationErrors(['label' => $label]);
})->with([
    'en' => ['en', 'Choose at least one digest day.', 'The Quiet until field is required when Quiet from is present.', 'The Name field is required.'],
    'de' => ['de', 'Wählen Sie mindestens einen Tag für die Zusammenfassung.', 'Ruhe bis muss ausgefüllt werden, wenn Ruhe ab ausgefüllt wurde.', 'Name muss ausgefüllt werden.'],
]);

it('supplies distinct site labels and picker details across disambiguation stages', function (): void {
    $owner = recipientUser('Owner');
    $client = Client::factory()->create(['name' => 'Kestrel']);
    $first = Site::factory()->for($client)->create(['friendly_name' => 'A', 'url' => 'https://foo.test']);
    $second = Site::factory()->for($client)->create(['friendly_name' => 'A', 'url' => 'https://bar.test']);
    $literal = Site::factory()->for($client)->create(['friendly_name' => 'A (foo.test)', 'url' => 'https://literal.test']);
    $cascade = Site::factory()->for($client)->create(['friendly_name' => 'A (Kestrel · foo.test)', 'url' => 'https://cascade.test']);

    $sites = collect($this->actingAs($owner)->getJson(route('settings.notifications.team.data'))->assertOk()->json('sites'))->keyBy('id');
    expect($sites[$first->id]['identity'] ?? null)->toBe(['label' => 'A (Kestrel · foo.test/)', 'detail' => 'Kestrel · foo.test/'])
        ->and($sites[$second->id]['identity'] ?? null)->toBe(['label' => 'A (bar.test)', 'detail' => 'Kestrel · bar.test'])
        ->and($sites[$literal->id]['identity'] ?? null)->toBe(['label' => 'A (foo.test) (literal.test)', 'detail' => 'Kestrel · literal.test'])
        ->and($sites[$cascade->id]['identity'] ?? null)->toBe(['label' => 'A (Kestrel · foo.test) (cascade.test)', 'detail' => 'Kestrel · cascade.test']);
});
