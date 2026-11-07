<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Actions\EmitNotificationDigests;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\Http\FetchResult;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Enums\ClientScope;
use Heptapod\Models\Check;
use Heptapod\Models\CheckSnooze;
use Heptapod\Models\Client;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Testing\FakeOutboundHttp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-27T12:00:00Z'));
    $this->withoutVite();
    Http::preventStrayRequests();
    Http::fake([]);
    $this->app->instance(OutboundHttp::class, (new FakeOutboundHttp)->respondUsing(static fn (): FetchResult => new FetchResult(status: 200)));
});

it('transfers the whole site and retained history through settings with an actor audit', function (string $role): void {
    $owner = User::factory()->create()->assignRole($role);
    $site = Site::factory()->create(['paused_at' => now()]);
    $source = $site->client;
    $source->update(['avatar_site_id' => $site->id]);
    $target = Client::factory()->create();
    $check = Check::factory()->for($site)->create();
    $incident = Incident::factory()->for($check)->create(['resolved_at' => now(), 'notes' => 'Retained note']);
    $snooze = CheckSnooze::factory()->for($check)->create();
    $event = Event::factory()->for($check)->create(['type' => 'incident.resolved', 'incident_id' => $incident->id, 'notification_routed_at' => now()]);
    $delivery = Delivery::query()->create(['event_id' => $event->id, 'destination_id' => NotificationDestination::factory()->create()->id,
        'state' => 'sent', 'next_attempt_at' => now(), 'sent_at' => now(), 'suppression_evaluated_at' => now()]);

    $otherSite = Site::factory()->for($source, 'client')->create(['paused_at' => now()]);
    $otherCheck = Check::factory()->for($otherSite)->create();
    $otherEvent = Event::factory()->for($otherCheck)->create(['notification_routed_at' => now()]);
    $otherDelivery = Delivery::query()->create(['event_id' => $otherEvent->id, 'destination_id' => $delivery->destination_id,
        'state' => 'sent', 'next_attempt_at' => now(), 'sent_at' => now(), 'suppression_evaluated_at' => now()]);

    $this->actingAs($owner)->from('/sites/'.$site->id.'/settings')->patch('/sites/'.$site->id, ['client_id' => $target->id])
        ->assertRedirect('/sites/'.$site->id.'/settings')->assertSessionHasNoErrors();

    expect($site->fresh()->client_id)->toBe($target->id)
        ->and($site->fresh()->paused_at)->not->toBeNull()
        ->and($event->fresh()->client_id)->toBe($target->id)
        ->and($event->fresh()->client_name)->toBe($source->name)
        ->and($delivery->fresh()->client_id)->toBe($target->id)
        ->and($delivery->fresh()->client_name)->toBe($source->name)
        ->and($source->fresh()->avatar_site_id)->toBeNull()
        ->and($check->fresh()->site_id)->toBe($site->id)
        ->and($incident->fresh()->notes)->toBe('Retained note')
        ->and($snooze->fresh()->check_id)->toBe($check->id);
    expect($otherEvent->fresh()->client_id)->toBe($source->id)
        ->and($otherDelivery->fresh()->client_id)->toBe($source->id);
    $audit = Activity::query()->where('subject_type', $site->getMorphClass())->where('subject_id', $site->id)->where('event', 'updated')->latest('id')->firstOrFail();
    expect($audit->causer_id)->toBe($owner->id)
        ->and($audit->attribute_changes['old']['client_id'])->toBe($source->id)
        ->and($audit->attribute_changes['attributes']['client_id'])->toBe($target->id);
    $oldMember = User::factory()->member()->create(['client_scope' => ClientScope::Restricted]);
    $oldMember->clients()->attach($source);
    $newMember = User::factory()->member()->create(['client_scope' => ClientScope::Restricted]);
    $newMember->clients()->attach($target);
    $this->actingAs($oldMember)->get('/sites/'.$site->id)->assertNotFound();
    $this->actingAs($newMember)->get('/sites/'.$site->id)->assertOk();
    $this->actingAs($owner)->delete('/sites/'.$site->id, ['name' => $site->displayName()])->assertRedirect();
    $this->actingAs($oldMember)->get('/activity')->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->has('events', 0));
    $this->actingAs($newMember)->get('/activity')->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->has('events', 1));
})->with(['Owner', 'Admin']);

it('rejects Members even when they can access both clients', function (bool $archived): void {
    $member = User::factory()->member()->create();
    $client = Client::factory()->create(['archived_at' => $archived ? now() : null]);
    $site = Site::factory()->for($client)->create();
    $target = Client::factory()->create();
    $this->actingAs($member)->patchJson('/sites/'.$site->id, ['client_id' => $target->id])->assertForbidden();
    expect($site->fresh()->client_id)->toBe($site->client_id);
})->with(['active source' => false, 'archived source' => true]);

it('rejects an archived target without changing any site fields', function (): void {
    $owner = User::factory()->owner()->create();
    $site = Site::factory()->create();
    $target = Client::factory()->create(['archived_at' => now()]);
    $this->actingAs($owner)->patchJson('/sites/'.$site->id, ['client_id' => $target->id, 'friendly_name' => 'Not saved'])
        ->assertUnprocessable()->assertJsonValidationErrors(['client_id' => __('sites.client_archived')]);
    expect($site->fresh()->client_id)->toBe($site->client_id)->and($site->fresh()->friendly_name)->toBe($site->friendly_name);
});

it('does not disclose a nonexistent target', function (): void {
    $owner = User::factory()->owner()->create();
    $site = Site::factory()->create();
    $this->actingAs($owner)->patchJson('/sites/'.$site->id, ['client_id' => 999999])->assertNotFound();
    expect($site->fresh()->client_id)->toBe($site->client_id);
});

it('moves a site out of an archived client but refuses other edits until it has moved', function (string $role): void {
    $owner = User::factory()->create()->assignRole($role);
    $site = Site::factory()->create(['paused_at' => now(), 'paused_by_archive_at' => now()]);
    $site->client->update(['archived_at' => now()]);
    $target = Client::factory()->create();
    $this->actingAs($owner)->patchJson('/sites/'.$site->id, ['client_id' => $target->id, 'friendly_name' => 'Forbidden'])->assertForbidden();
    expect($site->fresh()->client_id)->toBe($site->client_id);
    $this->patchJson('/sites/'.$site->id, ['client_id' => $target->id])->assertRedirect();
    expect($site->fresh()->client_id)->toBe($target->id)->and($site->fresh()->paused_at)->not->toBeNull()
        ->and($site->fresh()->paused_by_archive_at)->toBeNull();
})->with(['Owner', 'Admin']);

it('waits for open incidents but allows saving the unchanged client', function (): void {
    $owner = User::factory()->owner()->create();
    $incident = Incident::factory()->create();
    $site = $incident->site;
    $target = Client::factory()->create();
    $this->actingAs($owner)->patchJson('/sites/'.$site->id, ['client_id' => $target->id])
        ->assertUnprocessable()->assertJsonValidationErrors(['client_id' => __('sites.client_open_incident')]);
    expect($site->fresh()->client_id)->toBe($site->client_id);
    $this->patchJson('/sites/'.$site->id, ['client_id' => $site->client_id, 'friendly_name' => 'Still editable'])->assertRedirect();
    expect($site->fresh()->friendly_name)->toBe('Still editable');
});

it('waits for unrouted events before moving', function (): void {
    $owner = User::factory()->owner()->create();
    $event = Event::factory()->create();
    $site = $event->site;
    $target = Client::factory()->create();
    $this->actingAs($owner)->patchJson('/sites/'.$site->id, ['client_id' => $target->id])
        ->assertUnprocessable()->assertJsonValidationErrors(['client_id' => __('sites.client_alerts_pending')]);
    expect($site->fresh()->client_id)->toBe($site->client_id);
});

it('waits for pending sends and future retries including digest source items', function (string $state, bool $digest): void {
    $owner = User::factory()->owner()->create();
    $event = Event::factory()->create(['notification_routed_at' => now()]);
    $site = $event->site;
    $target = Client::factory()->create();
    Delivery::query()->create(['event_id' => $event->id, 'destination_id' => NotificationDestination::factory()->create()->id,
        'state' => $state, 'digest' => $digest, 'attempts' => 100, 'next_attempt_at' => now()->addHour(), 'suppression_evaluated_at' => now()]);
    $this->actingAs($owner)->patchJson('/sites/'.$site->id, ['client_id' => $target->id])
        ->assertUnprocessable()->assertJsonValidationErrors(['client_id' => __('sites.client_alerts_pending')]);
    expect($site->fresh()->client_id)->toBe($site->client_id);
})->with(['pending' => ['pending', false], 'sending' => ['sending', false], 'retry' => ['failed', false], 'digest' => ['pending', true]]);

it('does not block forever on permanently rejected delivery history', function (): void {
    $owner = User::factory()->owner()->create();
    $event = Event::factory()->create(['notification_routed_at' => now()]);
    $target = Client::factory()->create();
    $delivery = Delivery::query()->create(['event_id' => $event->id, 'destination_id' => NotificationDestination::factory()->create()->id,
        'state' => 'failed', 'suppressed' => true, 'suppression_reason' => 'smtp_recipient_rejected', 'next_attempt_at' => now(), 'suppression_evaluated_at' => now()]);
    $this->actingAs($owner)->patchJson('/sites/'.$event->site_id, ['client_id' => $target->id])->assertRedirect();
    expect($event->site->fresh()->client_id)->toBe($target->id)->and($delivery->fresh()->suppression_reason)->toBe('smtp_recipient_rejected');
});

it('ends daily recurrence on a moved Warning without relabelling accepted digest history', function (): void {
    $owner = User::factory()->owner()->create();
    $check = Check::factory()->create(['type' => CheckKey::Uptime, 'latest_run_result' => CheckResult::Warning]);
    $incident = Incident::factory()->for($check)->create(['opened_at' => now()->subDays(2), 'resolved_at' => now()->subDay()]);
    $event = Event::factory()->for($check)->create(['incident_id' => $incident->id, 'from_result' => CheckResult::Succeeded,
        'to_result' => CheckResult::Warning, 'occurred_at' => now()->subDay(), 'notification_routed_at' => now()->subDay()]);
    $destination = NotificationDestination::factory()->create();
    DB::table('notification_preferences')->where('destination_id', $destination->id)->where('category', 'warning')->update(['mode' => 'digest']);
    $delivery = Delivery::query()->create(['event_id' => $event->id, 'destination_id' => $destination->id,
        'state' => 'sent', 'digest' => true, 'sent_at' => now()->subDay(), 'next_attempt_at' => now()->subDay(), 'suppression_evaluated_at' => now()->subDay()]);
    $target = Client::factory()->create();

    $this->actingAs($owner)->patchJson('/sites/'.$check->site_id, ['client_id' => $target->id])->assertRedirect();
    app(EmitNotificationDigests::class)();

    expect(Event::query()->where('type', 'notification.digest')->count())->toBe(0)
        ->and($delivery->fresh()->digest_recurrence_ended_at)->not->toBeNull()
        ->and($check->fresh()->latest_run_result)->toBe(CheckResult::Warning);
    $this->get('/incidents/'.$incident->id)->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->where('other_deliveries.0.state', 'accepted')->where('other_deliveries.0.digest', true)->where('other_deliveries.0.can_preview', false));
});

it('rolls back the transfer if another settings field fails validation', function (): void {
    $owner = User::factory()->owner()->create();
    $site = Site::factory()->create();
    $target = Client::factory()->create();
    $this->actingAs($owner)->patchJson('/sites/'.$site->id, ['client_id' => $target->id, 'url' => 'not a URL'])
        ->assertUnprocessable()->assertJsonValidationErrors('url');
    expect($site->fresh()->client_id)->toBe($site->client_id);
    expect(Activity::query()->where('subject_type', $site->getMorphClass())->where('subject_id', $site->id)->where('event', 'updated')->count())->toBe(0);
});

it('keeps client reassignment unavailable on the public monitor update API', function (): void {
    $owner = User::factory()->owner()->create();
    $site = Site::factory()->create();
    $target = Client::factory()->create();
    $token = $owner->createToken('monitor writer', ['monitors:write'])->plainTextToken;
    $this->withToken($token)->putJson('/api/monitors/'.$site->id, ['client_id' => $target->id])
        ->assertUnprocessable()->assertJsonValidationErrors('client_id');
    expect($site->fresh()->client_id)->toBe($site->client_id);
});
