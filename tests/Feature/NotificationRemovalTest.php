<?php

declare(strict_types=1);

use Heptapod\Actions\PlanNotificationEvent;
use Heptapod\Actions\SendNotificationDelivery;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Enums\NotificationCategory;
use Heptapod\Models\Delivery;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/../Support/NotificationChannels.php';

it('refuses a second mail address for a user without creating a channel or sending verification', function (mixed $address): void {
    Notification::fake();
    $user = User::factory()->create(['email' => 'account@example.test']);
    $user->assignRole('Member');

    $this->actingAs($user)->postJson(route('notification-channels.store'), ['recipient_kind' => 'user', 'channel' => 'mail', 'label' => 'Second',
        'config' => ['email' => $address]])->assertUnprocessable()->assertJsonValidationErrors('channel');

    expect(NotificationDestination::query()->where('user_id', $user->id)->exists())->toBeFalse();
    Notification::assertNothingSent();
})->with(['extra address' => ['extra@example.test'], 'account address supplied' => ['account@example.test'], 'null address' => [null], 'empty address' => [''], 'array address' => [[]]]);

it('keeps the removed site exception, secondary verification and Follow routes gone without removing account verification', function (): void {
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $site = Site::factory()->create();
    $this->actingAs($owner);

    $this->getJson('/sites/'.$site->id.'/notifications')->assertNotFound();
    $this->patchJson('/sites/'.$site->id.'/notifications', ['mode' => 'inherit'])->assertNotFound();
    $this->getJson('/sites/'.$site->id.'/notifications/preview')->assertNotFound();
    $this->postJson('/sites/'.$site->id.'/follow')->assertNotFound();
    $this->getJson('/settings/notifications/verify/1/'.str_repeat('a', 64))->assertNotFound();
    expect(app('router')->has('verification.verify'))->toBeTrue();
});

it('delivers a Member account channel alert only to the account address through the real mail channel', function (): void {
    $transport = Mail::mailer()->getSymfonyTransport();
    expect($transport)->toBeInstanceOf(ArrayTransport::class);
    $transport->flush();
    $member = User::factory()->create(['email' => 'account-follow@example.test']);
    $member->assignRole('Member');
    $channel = notificationAccountChannel($member, [NotificationCategory::Outage]);
    $event = notificationIncidentEvent();

    app(PlanNotificationEvent::class)($event->id);
    $delivery = Delivery::query()->where('event_id', $event->id)->sole();
    app(SendNotificationDelivery::class)($delivery->id);

    // The array mailer records mail as logged, not provider-accepted (8e3a8e4e).
    expect($delivery->refresh()->state)->toBe(DeliveryState::Logged)->and($transport->messages())->toHaveCount(1);
    expect(array_map(fn ($recipient): string => $recipient->getAddress(), $transport->messages()->first()->getEnvelope()->getRecipients()))
        ->toBe(['account-follow@example.test']);
    expect($delivery->destination_id)->toBe($channel->id);
});

it('does not plan for a paused, unverified or disabled account channel', function (string $change): void {
    $member = User::factory()->create();
    $member->assignRole('Member');
    $channel = notificationAccountChannel($member, [NotificationCategory::Outage]);
    match ($change) {
        'none' => null,
        'channel' => $channel->update(['enabled' => false]),
        'verification' => $member->forceFill(['email_verified_at' => null])->save(),
        'account' => $member->forceFill(['disabled_at' => now()])->save(),
    };
    $event = notificationIncidentEvent();

    app(PlanNotificationEvent::class)($event->id);

    expect(Delivery::query()->where('event_id', $event->id)->exists())->toBe($change === 'none');
})->with(['none', 'channel', 'verification', 'account']);
