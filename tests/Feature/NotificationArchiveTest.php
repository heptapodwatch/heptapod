<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Actions\ArchiveNotificationChannel;
use Heptapod\Actions\ClaimNotificationDelivery;
use Heptapod\Actions\CompleteNotificationDelivery;
use Heptapod\Actions\EmitNotificationDigests;
use Heptapod\Actions\EnsureAccountChannel;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\Notifications\ChannelRegistry;
use Heptapod\Contracts\Notifications\DeliveryResult;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Enums\NotificationCategory;
use Heptapod\Enums\NotificationMode;
use Heptapod\Enums\RecipientKind;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/../Support/NotificationChannels.php';

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-28T12:00:00Z'));
    config(['mail.default' => 'smtp']);
});

/** @param array<string, mixed> $attributes */
function archiveDelivery(NotificationDestination $channel, array $attributes = []): Delivery
{
    $event = Event::factory()->create(['from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Warning]);
    $event->check->update(['latest_run_result' => CheckResult::Warning]);

    return Delivery::query()->create(['event_id' => $event->id, 'destination_id' => $channel->id, 'next_attempt_at' => now(),
        'suppression_evaluated_at' => now(), ...$attributes]);
}

function archiveClientChannel(): NotificationDestination
{
    $client = Event::factory()->create()->client_id;

    return NotificationDestination::factory()->create(['recipient_kind' => RecipientKind::Client, 'client_id' => $client, 'locale' => null,
        'config' => app(ChannelRegistry::class)->get('mail')->configSchema()->validateConfig(['email' => 'client@example.test'])]);
}

it('suppresses queued work, ends digest recurrence and keeps history when a channel is archived', function (string $kind): void {
    $actor = User::factory()->owner()->create();
    $channel = match ($kind) {
        'team' => NotificationDestination::factory()->create(),
        'client' => archiveClientChannel(),
        'user' => app(EnsureAccountChannel::class)(User::factory()->member()->create()),
    };
    $pending = archiveDelivery($channel);
    $failed = archiveDelivery($channel, ['state' => 'failed', 'attempts' => 1]);
    $expired = archiveDelivery($channel, ['state' => 'sending', 'lease_until' => now()->subSecond(), 'claim_token' => '00000000-0000-4000-8000-000000000001']);
    $leased = archiveDelivery($channel, ['state' => 'sending', 'lease_until' => now()->addMinute(), 'claim_token' => '00000000-0000-4000-8000-000000000002']);
    $digestSource = archiveDelivery($channel, ['digest' => true]);
    $recurring = archiveDelivery($channel, ['state' => 'sent', 'sent_at' => now()->subDay(), 'digest' => true]);
    $sent = archiveDelivery($channel, ['state' => 'sent', 'sent_at' => now()->subHour()]);

    $count = app(ArchiveNotificationChannel::class)($channel, ArchiveNotificationChannel::Archived, $actor);

    expect($count)->toBe(4);
    foreach ([$pending, $failed, $expired, $digestSource] as $delivery) {
        expect($delivery->fresh()->only(['suppressed', 'suppression_reason']))->toBe(['suppressed' => true, 'suppression_reason' => 'destination_archived']);
    }
    expect($leased->fresh()->only(['state', 'suppressed']))->toBe(['state' => DeliveryState::Sending, 'suppressed' => false]);
    expect($recurring->fresh()->digest_recurrence_ended_at)->not->toBeNull();
    expect($sent->fresh()->only(['state', 'suppressed']))->toBe(['state' => DeliveryState::Sent, 'suppressed' => false]);
    $archived = $channel->fresh();
    expect($archived->removed_at)->not->toBeNull()->and($archived->removed_reason)->toBe('archived');
    expect(DB::table('activity_log')->where('event', 'notification.channel_archived')->where('subject_id', $channel->id)->sole()->properties)
        ->toContain('"suppressed_delivery_count":4');
    // Idempotent: a second archive changes nothing.
    expect(app(ArchiveNotificationChannel::class)($channel, ArchiveNotificationChannel::Archived, $actor))->toBe(0);
})->with(['team', 'client', 'user']);

it('suppresses a deleted users account channel work and keeps the address in history', function (): void {
    $owner = User::factory()->owner()->create();
    $user = User::factory()->admin()->create(['email' => 'leaving@example.test', 'password' => 'password']);
    $channel = notificationAccountChannel($user, [NotificationCategory::Warning]);
    $pending = archiveDelivery($channel);
    $sent = archiveDelivery($channel, ['state' => 'sent', 'sent_at' => now()->subHour()]);

    $this->actingAs($user)->delete(route('profile.destroy'), ['password' => 'password'])->assertRedirect();

    expect(User::query()->whereKey($user->id)->exists())->toBeFalse();
    $archived = NotificationDestination::query()->whereKey($channel->id)->sole();
    expect($archived->user_id)->toBeNull()->and($archived->removed_reason)->toBe('recipient_deleted')
        ->and($archived->destinationConfig()->string('email'))->toBe('leaving@example.test');
    expect($pending->fresh()->suppression_reason)->toBe('recipient_deleted');
    expect($sent->fresh()->only(['state', 'destination_id']))->toBe(['state' => DeliveryState::Sent, 'destination_id' => $channel->id]);
    expect($owner->fresh())->not->toBeNull();
});

it('suppresses a row created after archiving at claim, for every origin', function (string $origin): void {
    Mail::fake();
    $channel = notificationRoute(NotificationDestination::factory()->create(), [NotificationCategory::Warning]);
    app(ArchiveNotificationChannel::class)($channel, ArchiveNotificationChannel::Archived, null);
    $late = archiveDelivery($channel, ['origin' => $origin, 'actor_name' => $origin === 'manual' ? 'Owner' : null,
        'recipient_snapshot' => $origin === 'manual' ? ['address' => 'client@example.test', 'name' => 'Client', 'locale' => 'en'] : null]);

    expect(app(ClaimNotificationDelivery::class)($late->id))->toBeNull();

    expect($late->fresh()->only(['suppressed', 'suppression_reason', 'state']))->toBe(['suppressed' => true, 'suppression_reason' => 'destination_archived', 'state' => DeliveryState::Pending]);
    Mail::assertNothingSent();
})->with(['system', 'manual']);

it('lets a send that was already leased complete after the channel is archived', function (): void {
    Mail::fake();
    $channel = notificationRoute(NotificationDestination::factory()->create(), [NotificationCategory::Warning]);
    $delivery = archiveDelivery($channel);
    $claimed = app(ClaimNotificationDelivery::class)($delivery->id);
    expect($claimed?->state)->toBe(DeliveryState::Sending);

    app(ArchiveNotificationChannel::class)($channel, ArchiveNotificationChannel::Archived, null);
    app(CompleteNotificationDelivery::class)($delivery->id, $claimed->claim_token ?? '', new DeliveryResult(DeliveryState::Sent));

    expect($delivery->fresh()->only(['state', 'suppressed']))->toBe(['state' => DeliveryState::Sent, 'suppressed' => false]);
});

it('skips archived channels in the digest emitter and suppresses their retained sources', function (): void {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-28T07:00:00Z'));
    $kept = notificationRoute(NotificationDestination::factory()->create(), [NotificationCategory::Warning], NotificationMode::Digest);
    $archived = notificationRoute(NotificationDestination::factory()->create(), [NotificationCategory::Warning], NotificationMode::Digest);
    $keptSource = archiveDelivery($kept, ['digest' => true, 'next_attempt_at' => now()]);
    $archivedSource = archiveDelivery($archived, ['digest' => true, 'next_attempt_at' => now()]);
    // The archive happened before this source was written (a racing planner); the emitter must still drop it.
    DB::table('notification_destinations')->where('id', $archived->id)->update(['removed_at' => now(), 'removed_reason' => 'archived']);
    $this->travelTo(CarbonImmutable::parse('2026-10-28T08:00:00Z'));

    app(EmitNotificationDigests::class)();

    $digests = Event::query()->where('type', 'notification.digest')->get();
    expect($digests)->toHaveCount(1)->and($digests->sole()->payload['delivery_ids'])->toBe([$keptSource->id]);
    expect(Delivery::query()->where('event_id', $digests->sole()->id)->sole()->destination_id)->toBe($kept->id);
    expect($archivedSource->fresh()->suppression_reason)->toBe('destination_archived');
});
