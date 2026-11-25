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
 * @param  array<string, mixed>|string  $body  a string is sent as the raw body (malformed JSON included)
 */
function clientApi(string $method, string $uri, string $token, array|string $body = []): TestResponse
{
    app('auth')->forgetGuards();
    $content = is_string($body) ? $body : ($body === [] ? null : json_encode($body, JSON_THROW_ON_ERROR));
    $response = app(HttpKernel::class)->handle(Request::create($uri, $method, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token,
        'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'], content: $content));
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

    clientApi('POST', '/api/clients/'.$id.'/archive', $token, ['sites' => 'move', 'move_to_client_id' => $internal])->assertOk()->assertJsonPath('archived', true);
    expect(Site::query()->whereKey([$inAcme, $unassigned])->pluck('client_id')->unique()->all())->toBe([$internal]);
    clientApi('GET', '/api/clients', $token)->assertOk()->assertJsonPath('data.*.id', [$internal]);
    clientApi('GET', '/api/clients?filter[include_archived]=true', $token)->assertOk()->assertJsonPath('data.*.id', [$internal, $id]);
    clientApi('POST', '/api/clients/'.$id.'/restore', $token, ['sites' => 'resume'])->assertOk()->assertJsonPath('archived', false);
});

it('pauses the sites of a client archived without a move and resumes them on restore', function (): void {
    $client = Client::factory()->create();
    $site = Site::factory()->for($client)->create();
    $token = clientToken(User::factory()->admin()->create(), ['clients:write']);

    clientApi('POST', '/api/clients/'.$client->id.'/archive', $token, ['sites' => 'pause'])->assertOk()->assertJsonPath('archived', true);
    expect($site->refresh()->paused_at)->not->toBeNull();
    clientApi('POST', '/api/clients/'.$client->id.'/restore', $token, ['sites' => 'resume'])->assertOk()->assertJsonPath('archived', false);
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
    clientApi('POST', '/api/clients/'.$client->id.'/archive', $member, ['sites' => 'pause'])->assertNotFound();
    clientApi('POST', '/api/clients', $constrained, ['name' => 'Nope'])->assertForbidden();
    clientApi('PUT', '/api/clients/'.internalClient()->id, $constrained, ['name' => 'Nope'])->assertNotFound();
    clientApi('POST', '/api/clients', $readOnly, ['name' => 'Nope'])->assertForbidden();
    clientApi('POST', '/api/clients/'.$client->id.'/archive', $readOnly, ['sites' => 'pause'])->assertForbidden();

    expect(Client::query()->where('name', 'Nope')->exists())->toBeFalse()->and($client->refresh()->archived_at)->toBeNull();
});

it('keeps the internal client unarchivable and its name fixed', function (): void {
    $token = clientToken(User::factory()->owner()->create(), ['clients:write']);

    clientApi('POST', '/api/clients/'.internalClient()->id.'/archive', $token, ['sites' => 'pause'])->assertForbidden();
    clientApi('PUT', '/api/clients/'.internalClient()->id, $token, ['name' => 'Renamed'])->assertUnprocessable()->assertJsonValidationErrors(['name']);
});

it('refuses an archive move to a missing, hidden, archived or the same client on move_to_client_id', function (): void {
    $client = Client::factory()->create();
    Site::factory()->for($client)->create();
    $archived = Client::factory()->create(['archived_at' => now()]);
    $hidden = Client::factory()->create();
    $admin = User::factory()->admin()->create();
    $refused = fn (string $token, int $target) => clientApi('POST', '/api/clients/'.$client->id.'/archive', $token, ['sites' => 'move', 'move_to_client_id' => $target])
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

it('refuses restore to Members, other clients, tokens without clients:write and the internal client', function (): void {
    $client = Client::factory()->create(['archived_at' => now()]);
    $other = Client::factory()->create();
    $owner = User::factory()->owner()->create();
    $member = User::factory()->member()->create()->createToken('legacy', ['*'])->plainTextToken;
    $readOnly = clientToken($owner, ['clients:read']);
    $foreign = clientToken($owner, ['clients:write'], $other->id);
    $broad = clientToken($owner, ['clients:write']);

    clientApi('POST', '/api/clients/'.$client->id.'/restore', $readOnly, ['sites' => 'resume'])->assertForbidden();
    clientApi('POST', '/api/clients/'.$client->id.'/restore', $member, ['sites' => 'resume'])->assertNotFound();
    clientApi('POST', '/api/clients/'.$client->id.'/restore', $foreign, ['sites' => 'resume'])->assertNotFound();
    clientApi('POST', '/api/clients/'.internalClient()->id.'/restore', $broad, ['sites' => 'resume'])->assertForbidden();
    clientApi('POST', '/api/clients/'.$client->id.'/restore', $broad, ['sites' => 'resume', 'resume' => false])->assertUnprocessable()->assertJsonValidationErrors(['resume']);
    expect($client->refresh()->archived_at)->not->toBeNull();
});

it('refuses an unknown archive field instead of pausing every site', function (): void {
    $client = Client::factory()->create();
    $site = Site::factory()->for($client)->create();
    $token = clientToken(User::factory()->owner()->create(), ['clients:write']);

    // client_id is the web form's field; the API takes move_to_client_id.
    clientApi('POST', '/api/clients/'.$client->id.'/archive', $token, ['sites' => 'move', 'client_id' => internalClient()->id])->assertUnprocessable()->assertJsonValidationErrors(['client_id' => __('api.unsupported_field')]);
    clientApi('POST', '/api/clients', $token, ['name' => 'Logo Ltd', 'logo' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['logo']);
    expect($client->refresh()->archived_at)->toBeNull()->and($site->refresh()->paused_at)->toBeNull()
        ->and(Client::query()->where('name', 'Logo Ltd')->exists())->toBeFalse();
});

it('refuses client IDs that are not JSON integers', function (string $id): void {
    $client = Client::factory()->create();
    $site = Site::factory()->for($client)->create();
    $token = clientToken(User::factory()->owner()->create(), ['clients:write', 'monitors:write']);
    // Raw JSON: json_encode would turn 1.0 into 1.
    $raw = fn (string $method, string $uri, string $json) => $this->call($method, $uri, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token,
        'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'], content: $json);

    // Each of these is client 1 ("No client") in a loose comparison; none may move or create anything there.
    $raw('PUT', '/api/monitors/'.$site->id, '{"client_id":'.$id.'}')->assertUnprocessable()->assertJsonValidationErrors(['client_id']);
    $raw('POST', '/api/monitors', '{"team_id":1,"type":"http","url":"https://loose-id.test","checks":[],"client_id":'.$id.'}')
        ->assertUnprocessable()->assertJsonValidationErrors(['client_id']);
    clientApi('POST', '/api/clients/'.$client->id.'/archive', $token, '{"sites":"move","move_to_client_id":'.$id.'}')
        ->assertUnprocessable()->assertJsonValidationErrors(['move_to_client_id']);
    expect($site->refresh()->client_id)->toBe($client->id)->and($client->refresh()->archived_at)->toBeNull()
        ->and(Site::query()->where('url', 'https://loose-id.test')->exists())->toBeFalse();
})->with(['true' => 'true', 'numeric string' => '"1"', 'float' => '1.0']);

it('refuses archive and restore without an explicit, well-formed mode', function (string $operation, string $body): void {
    $client = Client::factory()->create(['archived_at' => $operation === 'restore' ? now() : null]);
    $site = Site::factory()->for($client)->create(['paused_at' => $operation === 'restore' ? now() : null, 'paused_by_archive_at' => $operation === 'restore' ? now() : null]);
    $token = clientToken(User::factory()->owner()->create(), ['clients:write']);

    clientApi('POST', '/api/clients/'.$client->id.'/'.$operation, $token, $body)->assertUnprocessable();
    expect($client->refresh()->archived_at === null)->toBe($operation === 'archive')
        ->and($site->refresh()->paused_at === null)->toBe($operation === 'archive')
        ->and($site->client_id)->toBe($client->id);
})->with([
    'archive without a body' => ['archive', ''],
    'archive null' => ['archive', 'null'],
    'archive list' => ['archive', '[]'],
    'archive empty object' => ['archive', '{}'],
    'archive truncated JSON' => ['archive', '{"sites":"pau'],
    'archive unknown mode' => ['archive', '{"sites":"stop"}'],
    'archive mode true' => ['archive', '{"sites":true}'],
    'archive move without a target' => ['archive', '{"sites":"move"}'],
    'archive pause with a target' => ['archive', '{"sites":"pause","move_to_client_id":1}'],
    'restore without a body' => ['restore', ''],
    'restore null' => ['restore', 'null'],
    'restore list' => ['restore', '[]'],
    'restore truncated JSON' => ['restore', '{"sites":"res'],
    'restore unknown choice' => ['restore', '{"sites":true}'],
]);

it('moves a monitor out of an archived client with client_id alone, as on the web', function (): void {
    $site = Site::factory()->create(['paused_at' => now(), 'paused_by_archive_at' => now()]);
    $site->client->update(['archived_at' => now()]);
    $target = Client::factory()->create();
    $this->withToken(clientToken(User::factory()->admin()->create(), ['monitors:write']));

    $this->putJson('/api/monitors/'.$site->id, ['client_id' => $target->id, 'friendly_name' => 'Not yet'])->assertForbidden();
    $this->putJson('/api/monitors/'.$site->id, ['client_id' => $target->id])->assertOk()->assertJsonPath('client_ref', 'client:'.$target->id);
    expect($site->refresh()->paused_by_archive_at)->toBeNull()->and($site->friendly_name)->not->toBe('Not yet');
});

it('rejects alert timing fields on a monitor move like on any monitor update', function (): void {
    $site = Site::factory()->create();
    $before = $site->client_id;
    $target = Client::factory()->create();
    $this->withToken(clientToken(User::factory()->owner()->create(), ['monitors:write']));

    $this->putJson('/api/monitors/'.$site->id, ['alert_after_minutes' => 30])->assertUnprocessable()->assertJsonValidationErrors(['alert_after_minutes']);
    $this->putJson('/api/monitors/'.$site->id, ['client_id' => $target->id, 'alert_after_minutes' => 30])->assertUnprocessable()->assertJsonValidationErrors(['alert_after_minutes']);
    expect($site->refresh()->client_id)->toBe($before);
});
