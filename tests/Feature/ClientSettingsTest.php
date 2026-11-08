<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Enums\ClientScope;
use Heptapod\Models\Client;
use Heptapod\Models\Invitation;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Notifications\TeamInvitation;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-07T12:00:00Z'));
    $this->withoutVite();
    Http::preventStrayRequests();
    Http::fake([]);
});

it('refuses internal name changes but saves other fields with an unchanged stored name', function (): void {
    $owner = User::factory()->owner()->create();
    $client = Client::query()->where('is_internal', true)->firstOrFail();
    $client->update(['name' => 'Previously renamed']);

    $this->actingAs($owner)->patchJson('/clients/'.$client->id, ['name' => 'New name', 'timezone' => 'Europe/Berlin'])
        ->assertUnprocessable()->assertJsonValidationErrors('name');
    expect($client->fresh()->name)->toBe('Previously renamed')->and($client->fresh()->timezone)->toBe('UTC');
    $this->patchJson('/clients/'.$client->id, ['name' => 'Previously renamed', 'timezone' => 'Europe/Berlin'])->assertRedirect();
    expect($client->fresh()->name)->toBe('Previously renamed')->and($client->fresh()->timezone)->toBe('Europe/Berlin');
});

it('shows the translated No client identity in lists pickers and trails without changing the public API', function (string $locale, string $label): void {
    $owner = User::factory()->owner()->create(['locale' => $locale]);
    $client = Client::query()->where('is_internal', true)->firstOrFail();
    $client->update(['name' => 'Previously renamed']);
    $site = Site::factory()->for($client)->create();
    $other = Client::factory()->create();

    $rows = $this->actingAs($owner)->get('/clients')->assertOk()->inertiaProps()['clients'];
    expect(collect($rows)->firstWhere('client.id', $client->id)['client']['name'])->toBe($label);
    $this->get('/clients/'.$other->id.'/settings')->assertInertia(fn (Assert $page): Assert => $page->where('clients.0.name', $label)->where('clients.0.is_internal', true));
    $this->get('/sites/'.$site->id)->assertInertia(fn (Assert $page): Assert => $page->where('client.name', $label));
    $this->get('/clients/'.$client->id.'/settings')->assertInertia(fn (Assert $page): Assert => $page->where('client.name', $label));
    $token = $owner->createToken('reader', ['monitors:read'])->plainTextToken;
    $this->withToken($token)->getJson('/api/monitors/'.$site->id)->assertOk()->assertJsonPath('client_name', 'Previously renamed');
    expect($client->fresh()->name)->toBe('Previously renamed');
})->with(['en' => ['en', 'No client'], 'de' => ['de', 'Ohne Kunde']]);

it('names No client in invitation mail in the mail language rather than exposing the stored bucket name', function (string $locale, string $label): void {
    $client = Client::query()->where('is_internal', true)->firstOrFail();
    $client->update(['name' => 'Previously renamed']);
    $invitation = Invitation::factory()->create(['client_scope' => ClientScope::Restricted, 'client_ids' => [$client->id]]);
    $transport = new ArrayTransport;
    Mail::mailer()->setSymfonyTransport($transport);

    Notification::route('mail', $invitation->email)->notify(new TeamInvitation($invitation, 'https://monitor.example.test/invitations/token')->locale($locale));

    $mail = $transport->messages()->sole()->getOriginalMessage();
    expect($mail->getTextBody())->toContain($label)->not->toContain('Previously renamed');
})->with(['en' => ['en', 'No client'], 'de' => ['de', 'Ohne Kunde']]);
