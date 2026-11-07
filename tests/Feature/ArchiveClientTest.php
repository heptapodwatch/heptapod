<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Models\Check;
use Heptapod\Models\Client;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-07T12:00:00Z'));
    $this->withoutVite();
    Http::preventStrayRequests();
    Http::fake([]);
});

it('archives and restores only the sites that archiving paused', function (bool $resume): void {
    $owner = User::factory()->owner()->create();
    $client = Client::factory()->create();
    $running = Site::factory()->for($client)->create();
    $paused = Site::factory()->for($client)->create(['paused_at' => now()->subDay()]);
    Check::factory()->for($running)->create();

    $this->actingAs($owner)->post('/clients/'.$client->id.'/archive')->assertRedirect('/clients');
    expect($client->fresh()->archived_at)->not->toBeNull()
        ->and($running->fresh()->paused_at)->not->toBeNull()
        ->and($running->fresh()->paused_by_archive_at)->not->toBeNull()
        ->and($paused->fresh()->paused_by_archive_at)->toBeNull();
    $this->post('/clients/'.$client->id.'/restore', ['resume_sites' => $resume])->assertRedirect();

    expect($client->fresh()->archived_at)->toBeNull()
        ->and($running->fresh()->paused_at === null)->toBe($resume)
        ->and($running->fresh()->paused_by_archive_at)->toBeNull()
        ->and($paused->fresh()->paused_at?->toIso8601String())->toBe('2026-11-06T12:00:00+00:00');
})->with([true, false]);

it('restores a pre-release archived client without resuming its unrecorded sites', function (): void {
    $owner = User::factory()->owner()->create();
    $client = Client::factory()->create(['archived_at' => now()->subMonth()]);
    $site = Site::factory()->for($client)->create(['paused_at' => now()->subMonth()]);

    $this->actingAs($owner)->post('/clients/'.$client->id.'/restore', ['resume_sites' => true])->assertRedirect();

    expect($client->fresh()->archived_at)->toBeNull()->and($site->fresh()->paused_at)->not->toBeNull();
});

it('moves sites and history before archiving without changing monitoring state and ends digest recurrence', function (): void {
    $owner = User::factory()->owner()->create();
    $source = Client::factory()->create();
    $target = Client::factory()->create();
    $running = Site::factory()->for($source)->create();
    $paused = Site::factory()->for($source)->create(['paused_at' => now()->subDay()]);
    $source->update(['avatar_site_id' => $running->id]);
    $check = Check::factory()->for($running)->create();
    $event = Event::factory()->for($check)->create(['notification_routed_at' => now()]);
    $delivery = Delivery::query()->create(['event_id' => $event->id, 'destination_id' => NotificationDestination::factory()->create()->id,
        'state' => 'sent', 'digest' => true, 'next_attempt_at' => now(), 'sent_at' => now(), 'suppression_evaluated_at' => now()]);

    $this->actingAs($owner)->post('/clients/'.$source->id.'/archive', ['site_action' => 'move', 'client_id' => $target->id])
        ->assertRedirect('/clients')->assertSessionHasNoErrors();

    expect($source->fresh()->archived_at)->not->toBeNull()->and($source->sites()->count())->toBe(0)
        ->and($source->fresh()->avatar_site_id)->toBeNull()
        ->and($running->fresh()->client_id)->toBe($target->id)->and($running->fresh()->paused_at)->toBeNull()
        ->and($paused->fresh()->client_id)->toBe($target->id)->and($paused->fresh()->paused_at)->not->toBeNull()
        ->and($event->fresh()->client_id)->toBe($target->id)
        ->and($delivery->fresh()->client_id)->toBe($target->id)->and($delivery->fresh()->digest_recurrence_ended_at)->not->toBeNull();
});

it('reports up front which sites cannot move and why, only to managers', function (): void {
    $owner = User::factory()->owner()->create();
    $client = Client::factory()->create();
    $incident = Incident::factory()->for(Check::factory()->for(Site::factory()->for($client)->create(['friendly_name' => 'Down site'])))->create();
    $sending = Event::factory()->for(Check::factory()->for(Site::factory()->for($client)->create(['friendly_name' => 'Sending site'])))->create(['notification_routed_at' => now()]);
    Delivery::query()->create(['event_id' => $sending->id, 'destination_id' => NotificationDestination::factory()->create()->id,
        'state' => 'pending', 'next_attempt_at' => now(), 'suppression_evaluated_at' => now()]);
    $sent = Event::factory()->for(Check::factory()->for(Site::factory()->for($client)->create())->create())->create(['notification_routed_at' => now()]);
    Delivery::query()->create(['event_id' => $sent->id, 'destination_id' => NotificationDestination::factory()->create()->id,
        'state' => 'sent', 'next_attempt_at' => now(), 'sent_at' => now(), 'suppression_evaluated_at' => now()]);

    $this->actingAs($owner)->getJson('/clients/'.$client->id.'/move-blockers')->assertOk()->assertExactJson(['sites' => [
        ['id' => $incident->site_id, 'name' => 'Down site', 'reason' => 'open_incident'],
        ['id' => $sending->site_id, 'name' => 'Sending site', 'reason' => 'alerts_pending'],
    ]]);
    $this->actingAs(User::factory()->member()->create())->getJson('/clients/'.$client->id.'/move-blockers')->assertNotFound();
});

it('names the blocked site and completed moves and converges on retry after pending alerts finish', function (string $locale, string $message): void {
    $owner = User::factory()->owner()->create(['locale' => $locale]);
    $source = Client::factory()->create();
    $target = Client::factory()->create();
    $first = Site::factory()->for($source)->create();
    $second = Site::factory()->for($source)->create(['friendly_name' => 'Blocked site']);
    $check = Check::factory()->for($second)->create();
    $event = Event::factory()->for($check)->create();

    $this->actingAs($owner)->postJson('/clients/'.$source->id.'/archive', ['site_action' => 'move', 'client_id' => $target->id])
        ->assertUnprocessable()->assertJsonValidationErrors(['site_action' => $message])->assertJsonMissingValidationErrors('client_id');

    expect($source->fresh()->archived_at)->toBeNull()->and($first->fresh()->client_id)->toBe($target->id)
        ->and($second->fresh()->client_id)->toBe($source->id)->and($first->fresh()->paused_at)->toBeNull();
    $event->update(['notification_routed_at' => now()]);
    $this->postJson('/clients/'.$source->id.'/archive', ['site_action' => 'move', 'client_id' => $target->id])->assertRedirect();
    expect($source->fresh()->archived_at)->not->toBeNull()->and($source->sites()->count())->toBe(0)
        ->and($first->fresh()->client_id)->toBe($target->id)->and($second->fresh()->client_id)->toBe($target->id);
})->with([
    'en' => ['en', 'Could not move Blocked site; 1 site already moved. Alerts for this site are still being sent; try again in a few minutes.'],
    'de' => ['de', 'Blocked site konnte nicht verschoben werden; 1 Website bereits verschoben. Benachrichtigungen für diese Website werden noch versendet; versuchen Sie es in einigen Minuten erneut.'],
]);

it('refuses an archived move target and an internal archive without writing', function (): void {
    $owner = User::factory()->owner()->create();
    $source = Client::factory()->create();
    $site = Site::factory()->for($source)->create();
    $target = Client::factory()->create(['archived_at' => now()]);
    $internal = Client::query()->where('is_internal', true)->firstOrFail();

    $this->actingAs($owner)->postJson('/clients/'.$source->id.'/archive', ['site_action' => 'move', 'client_id' => $target->id])
        ->assertUnprocessable()->assertJsonValidationErrors('client_id');
    $this->postJson('/clients/'.$internal->id.'/archive')->assertForbidden();

    expect($source->fresh()->archived_at)->toBeNull()->and($site->fresh()->client_id)->toBe($source->id)
        ->and($internal->fresh()->archived_at)->toBeNull();
});

it('refuses Members for archive and restore', function (): void {
    $member = User::factory()->member()->create();
    $client = Client::factory()->create();
    $this->actingAs($member)->postJson('/clients/'.$client->id.'/archive')->assertNotFound();
    $client->update(['archived_at' => now()]);
    $this->postJson('/clients/'.$client->id.'/restore')->assertNotFound();
    expect($client->fresh()->archived_at)->not->toBeNull();
});

it('refuses to archive if a site appears after the move snapshot and converges on retry', function (): void {
    $owner = User::factory()->owner()->create();
    $source = Client::factory()->create();
    $target = Client::factory()->create();
    $site = Site::factory()->for($source)->create();
    $added = null;
    Illuminate\Support\Facades\Event::listen('eloquent.updated: '.Site::class, function (Site $updated) use ($site, $source, &$added): void {
        if ($updated->id === $site->id && $updated->wasChanged('client_id') && $added === null) {
            $added = Site::factory()->for($source)->create();
        }
    });

    $this->actingAs($owner)->postJson('/clients/'.$source->id.'/archive', ['site_action' => 'move', 'client_id' => $target->id])
        ->assertUnprocessable()->assertJsonValidationErrors(['site_action' => __('Sites were added while archiving; try again.')]);

    expect($source->fresh()->archived_at)->toBeNull()->and($site->fresh()->client_id)->toBe($target->id)
        ->and($added?->client_id)->toBe($source->id);
    $this->postJson('/clients/'.$source->id.'/archive', ['site_action' => 'move', 'client_id' => $target->id])->assertRedirect();
    expect($source->fresh()->archived_at)->not->toBeNull()->and($source->sites()->count())->toBe(0);
});
