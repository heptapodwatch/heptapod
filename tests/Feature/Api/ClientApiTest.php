<?php

declare(strict_types=1);

use cebe\openapi\Reader;
use Heptapod\Actions\CreateApiToken;
use Heptapod\Enums\ClientScope;
use Heptapod\Models\Client;
use Heptapod\Models\Event;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Spectator\Validation\ResponseValidator;

function internalClient(): Client
{
    return Client::query()->where('is_internal', true)->firstOrFail();
}

/**
 * ApiTestCase validates against the pinned Oh Dear spec, which has no client endpoints; call the kernel and validate
 * every response, refusals included, against the published contract instead.
 *
 * @param  array<string, mixed>  $body
 */
function clientApi(string $method, string $uri, string $token, array $body = []): TestResponse
{
    app('auth')->forgetGuards();
    $response = app(HttpKernel::class)->handle(Request::create($uri, $method, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token,
        'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'], content: $body === [] ? null : json_encode($body, JSON_THROW_ON_ERROR)));
    $path = preg_replace('~^/api/clients/[0-9]+~', '/clients/{client}', (string) parse_url($uri, PHP_URL_PATH));
    $path = $path === '/api/clients' ? '/clients' : $path;
    ResponseValidator::validate($response, Reader::readFromJsonFile(base_path('docs/openapi.json'))->paths[$path]->{strtolower($method)}, '3.1.2');

    return TestResponse::fromBaseResponse($response);
}

/** @param list<string> $abilities */
function clientToken(User $user, array $abilities, ?int $clientId = null): string
{
    return app(CreateApiToken::class)($user, 'clients', $abilities, $clientId);
}

it('lets an Owner token create, read, change, archive with a move and restore clients and place and move monitors', function (): void {
    $owner = User::factory()->owner()->create();
    $token = clientToken($owner, ['clients:read', 'clients:write', 'monitors:write']);
    $internal = internalClient()->id;

    $id = clientApi('POST', '/api/clients', $token, ['name' => 'Acme Ltd', 'short_name' => 'ACME', 'locale' => 'de', 'timezone' => 'Europe/Berlin'])
        ->assertCreated()->assertJsonPath('name', 'Acme Ltd')->assertJsonPath('is_internal', false)->assertJsonPath('archived', false)->json('id');
    clientApi('GET', '/api/clients', $token)->assertOk()->assertJsonPath('data.*.id', [$internal, $id])->assertJsonPath('data.0.is_internal', true)->assertJsonPath('meta.total', 2);
    clientApi('PUT', '/api/clients/'.$id, $token, ['name' => 'Acme GmbH'])->assertOk()->assertJsonPath('name', 'Acme GmbH')->assertJsonPath('short_name', 'ACME');
    clientApi('GET', '/api/clients/'.$id, $token)->assertOk()->assertJsonPath('name', 'Acme GmbH')->assertJsonPath('timezone', 'Europe/Berlin');

    $this->withToken($token);
    $inAcme = $this->postJson('/api/monitors', ['team_id' => 1, 'type' => 'http', 'url' => 'https://acme.test', 'checks' => [], 'client_id' => $id])
        ->assertCreated()->assertJsonPath('client_ref', 'client:'.$id)->json('id');
    $unassigned = $this->postJson('/api/monitors', ['team_id' => 1, 'type' => 'http', 'url' => 'https://plain.test', 'checks' => []])
        ->assertCreated()->assertJsonPath('client_ref', 'client:'.$internal)->json('id');
    $this->putJson('/api/monitors/'.$unassigned, ['client_id' => $id, 'friendly_name' => 'Moved'])->assertOk()
        ->assertJsonPath('client_ref', 'client:'.$id)->assertJsonPath('label', 'Moved');

    clientApi('POST', '/api/clients/'.$id.'/archive', $token, ['move_to_client_id' => $internal])->assertOk()->assertJsonPath('archived', true);
    expect(Site::query()->whereKey([$inAcme, $unassigned])->pluck('client_id')->unique()->all())->toBe([$internal]);
    clientApi('GET', '/api/clients', $token)->assertOk()->assertJsonPath('data.*.id', [$internal]);
    clientApi('GET', '/api/clients?filter[include_archived]=true', $token)->assertOk()->assertJsonPath('data.*.id', [$internal, $id]);
    clientApi('POST', '/api/clients/'.$id.'/restore', $token)->assertOk()->assertJsonPath('archived', false);
});

it('pauses the sites of a client archived without a move and resumes them on restore', function (): void {
    $client = Client::factory()->create();
    $site = Site::factory()->for($client)->create();
    $token = clientToken(User::factory()->admin()->create(), ['clients:write']);

    clientApi('POST', '/api/clients/'.$client->id.'/archive', $token)->assertOk()->assertJsonPath('archived', true);
    expect($site->refresh()->paused_at)->not->toBeNull();
    clientApi('POST', '/api/clients/'.$client->id.'/restore', $token)->assertOk()->assertJsonPath('archived', false);
    expect($site->refresh()->paused_at)->toBeNull();
});

it('lists only the clients the user and token reach', function (): void {
    [$one, $two] = Client::factory()->count(2)->create()->all();
    $member = User::factory()->member()->create(['client_scope' => ClientScope::Restricted]);
    $member->clients()->attach([$one->id, $two->id]);
    $owner = User::factory()->owner()->create();
    $restricted = clientToken($member, ['clients:read']);
    $constrained = clientToken($owner, ['clients:read'], $two->id);

    clientApi('GET', '/api/clients', $restricted)->assertOk()->assertJsonPath('data.*.id', [$one->id, $two->id]);
    clientApi('GET', '/api/clients/'.internalClient()->id, $restricted)->assertNotFound();
    clientApi('GET', '/api/clients', $constrained)->assertOk()->assertJsonPath('data.*.id', [$two->id]);
    clientApi('GET', '/api/clients/'.$one->id, $constrained)->assertNotFound();
    clientApi('GET', '/api/clients', clientToken($owner, ['monitors:read']))->assertForbidden();
});

it('refuses client writes to Members, client-constrained tokens and tokens without clients:write', function (): void {
    $client = Client::factory()->create();
    $member = User::factory()->member()->create()->createToken('legacy', ['*'])->plainTextToken;
    $owner = User::factory()->owner()->create();
    $constrained = clientToken($owner, ['clients:write'], $client->id);
    $readOnly = clientToken($owner, ['clients:read']);

    clientApi('POST', '/api/clients', $member, ['name' => 'Nope'])->assertForbidden();
    clientApi('PUT', '/api/clients/'.$client->id, $member, ['name' => 'Nope'])->assertNotFound();
    clientApi('POST', '/api/clients/'.$client->id.'/archive', $member)->assertNotFound();
    clientApi('POST', '/api/clients', $constrained, ['name' => 'Nope'])->assertForbidden();
    clientApi('PUT', '/api/clients/'.internalClient()->id, $constrained, ['name' => 'Nope'])->assertNotFound();
    clientApi('POST', '/api/clients', $readOnly, ['name' => 'Nope'])->assertForbidden();
    clientApi('POST', '/api/clients/'.$client->id.'/archive', $readOnly)->assertForbidden();

    expect(Client::query()->where('name', 'Nope')->exists())->toBeFalse()->and($client->refresh()->archived_at)->toBeNull();
});

it('keeps the internal client unarchivable and its name fixed', function (): void {
    $token = clientToken(User::factory()->owner()->create(), ['clients:write']);

    clientApi('POST', '/api/clients/'.internalClient()->id.'/archive', $token)->assertForbidden();
    clientApi('PUT', '/api/clients/'.internalClient()->id, $token, ['name' => 'Renamed'])->assertUnprocessable()->assertJsonValidationErrors(['name']);
});

it('refuses an archive move to a missing, hidden, archived or the same client on move_to_client_id', function (): void {
    $client = Client::factory()->create();
    Site::factory()->for($client)->create();
    $archived = Client::factory()->create(['archived_at' => now()]);
    $hidden = Client::factory()->create();
    $admin = User::factory()->admin()->create();
    $refused = fn (string $token, int $target) => clientApi('POST', '/api/clients/'.$client->id.'/archive', $token, ['move_to_client_id' => $target])
        ->assertUnprocessable()->assertJsonValidationErrors(['move_to_client_id']);

    $broad = clientToken($admin, ['clients:write']);
    $constrained = clientToken($admin, ['clients:write'], $client->id);
    foreach ([$hidden->id + 1000, $archived->id, $client->id] as $target) {
        $refused($broad, $target);
    }
    $refused($constrained, $hidden->id);
    expect($client->refresh()->archived_at)->toBeNull();
});

it('answers a monitor in a hidden client like one in a missing client', function (string $who): void {
    $visible = Client::factory()->create();
    $hidden = Client::factory()->create();
    $site = Site::factory()->for($visible)->create();
    $member = User::factory()->member()->create(['client_scope' => ClientScope::Restricted]);
    $member->clients()->attach($visible);
    // A Member may not edit the site at all, so its move stops at the policy before the client is looked at.
    [$token, $move] = match ($who) {
        'constrained token' => [clientToken(User::factory()->owner()->create(), ['monitors:write'], $visible->id), 422],
        'restricted Member' => [$member->createToken('legacy', ['*'])->plainTextToken, 403],
    };
    $this->withToken($token);

    $missing = $this->postJson('/api/monitors', ['team_id' => 1, 'type' => 'http', 'url' => 'https://missing.test', 'client_id' => $hidden->id + 1000])->assertUnprocessable();
    $this->postJson('/api/monitors', ['team_id' => 1, 'type' => 'http', 'url' => 'https://hidden.test', 'client_id' => $hidden->id])->assertUnprocessable()
        ->assertExactJson($missing->json());
    $this->putJson('/api/monitors/'.$site->id, ['client_id' => $hidden->id])->assertStatus($move);
    expect(Site::query()->where('client_id', $hidden->id)->exists())->toBeFalse()->and($site->refresh()->client_id)->toBe($visible->id);
})->with(['constrained token', 'restricted Member']);

it('refuses monitors in an archived client and moves of a site with pending alerts on client_id', function (): void {
    $archived = Client::factory()->create(['archived_at' => now()]);
    $target = Client::factory()->create();
    $site = Event::factory()->create()->site;
    $before = $site->client_id;
    $this->withToken(clientToken(User::factory()->owner()->create(), ['monitors:write']));

    $this->postJson('/api/monitors', ['team_id' => 1, 'type' => 'http', 'url' => 'https://archived.test', 'client_id' => $archived->id])
        ->assertUnprocessable()->assertJsonValidationErrors(['client_id' => __('clients.archived_cannot_add_site')]);
    $this->putJson('/api/monitors/'.$site->id, ['client_id' => $archived->id])
        ->assertUnprocessable()->assertJsonValidationErrors(['client_id' => __('sites.client_archived')]);
    $this->putJson('/api/monitors/'.$site->id, ['client_id' => $target->id, 'friendly_name' => 'Not saved'])
        ->assertUnprocessable()->assertJsonValidationErrors(['client_id' => __('sites.client_alerts_pending')]);
    expect($site->refresh()->client_id)->toBe($before)->and($site->friendly_name)->not->toBe('Not saved');
});
