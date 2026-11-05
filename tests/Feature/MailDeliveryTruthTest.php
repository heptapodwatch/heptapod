<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Models\Delivery;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\User;
use Heptapod\Settings\InstanceSettings;
use Heptapod\SystemHealth\MailTransportRecord;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Monolog\Handler\TestHandler;

require_once __DIR__.'/../Support/NotificationChannels.php';

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-26T10:00:00Z'));
    $this->withoutVite();
    Http::preventStrayRequests();
    Http::fake([]);
    Cache::store('redis')->forget('heptapod:mail-transport:outcome');
    $settings = app(InstanceSettings::class);
    $settings->alert_after_minutes = 1;
    $settings->save();
});

afterEach(function (): void {
    Cache::store('redis')->forget('heptapod:mail-transport:outcome');
});

it('writes logged mail headers and text to stderr rather than losing the message', function (): void {
    config(['mail.default' => 'log']);
    Mail::purge();
    $handler = new TestHandler(bubble: false);
    Log::channel('stderr')->getLogger()->pushHandler($handler);

    Mail::raw('Mail channel proof', fn ($message) => $message->to('recipient@example.test')->subject('Logged mail proof'));

    expect($handler->getRecords())->toHaveCount(1);
    expect($handler->getRecords()[0]->message)->toContain('recipient@example.test', 'Logged mail proof', 'Mail channel proof');
    expect(app(MailTransportRecord::class)->read()->lastSuccessAt)->toBeNull();
});

it('records local mail as logged without accepting the incident or retrying it', function (string $mailer): void {
    config(['mail.default' => $mailer]);
    Mail::purge();
    Log::channel('stderr')->getLogger()->pushHandler(new TestHandler(bubble: false));
    $destination = NotificationDestination::factory()->create();
    notificationRule($destination);
    $event = notificationIncidentEvent();
    $event->incident->update(['evidence' => ['summary' => 'causes.http', 'summary_params' => ['status' => 503], 'run_ended_at' => '2026-10-26T09:59:00Z']]);

    dispatchNotifications();

    $delivery = Delivery::query()->where('event_id', $event->id)->sole();
    expect($delivery->state)->toBe(DeliveryState::Logged);
    expect($delivery->sent_at)->toBeNull();
    expect($destination->fresh()->last_outcome)->toBe('logged');
    expect($event->incident->fresh()->evidence['notification_opening_accepted'] ?? false)->toBeFalse();
    $owner = User::factory()->owner()->create();
    $page = $this->actingAs($owner)->get(route('incidents.show', $event->incident_id))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('timeline.1.notification_group.state', 'logged')
            ->where('timeline.1.notification_group.logged_count', 1))->inertiaProps();
    $this->get(route('incidents.show', ['incident' => $event->incident_id, 'notification_group' => $page['timeline'][1]['notification_group']['id']]))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('expanded_notifications.deliveries.0.state', 'logged'));
    $this->travel(2)->hours();
    dispatchNotifications();
    expect($delivery->fresh()->attempts)->toBe(1);
})->with(['log', 'array']);

it('tells an inviter that a local transport did not deliver the invitation', function (): void {
    config(['mail.default' => 'array']);
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)->postJson(route('settings.members.invite'), ['email' => 'invite@example.test', 'role' => 'Admin', 'client_ids' => []])
        ->assertCreated()
        ->assertJsonPath('message', 'Invitation created; email not delivered: mail is not configured');
});

it('warns about composite transports with a local member without claiming all messages are local', function (): void {
    config(['mail.default' => 'log']);
    Mail::fake();
    $owner = User::factory()->owner()->create();
    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();
    config(['mail.default' => 'failover']);

    $this->artisan('heptapod:evaluate-system-issues')->assertSuccessful();

    $this->assertDatabaseHas('system_issues', ['kind' => 'mail_log_only', 'resolved_at' => null, 'details->mode' => 'mixed']);
    $this->actingAs($owner)->get(route('sites.index'))->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->where('mailLogOnly', false)->where('mailMayOnlyBeLogged', true));
});

it('stores exactly the delivery states the deliveries_state_check constraint allows', function (): void {
    $definition = DB::scalar("SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = 'deliveries_state_check'");
    preg_match_all("/'([a-z_]+)'::character varying/", is_string($definition) ? $definition : '', $allowed);

    expect($allowed[1])->toBe(array_column(DeliveryState::cases(), 'value'));
});
