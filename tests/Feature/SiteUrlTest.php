<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Contracts\Http\FetchRequest;
use Heptapod\Contracts\Http\FetchResult;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Models\Client;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Testing\FakeOutboundHttp;
use Heptapod\Testing\OhDearAccountTransport;
use Illuminate\Support\Facades\DB;
use Saloon\Http\Faking\MockClient;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-07T12:00:00Z'));
    $fake = new FakeOutboundHttp;
    $fake->respondUsing(static fn (FetchRequest $request): FetchResult => new FetchResult(200, effectiveUrl: $request->url));
    app()->instance(OutboundHttp::class, $fake);
});

afterEach(function (): void {
    MockClient::destroyGlobal();
});

it('refuses canonical duplicates at every write entry point without changing sites', function (string $entry, string $input, bool $keyed): void {
    $owner = User::factory()->owner()->create();
    $client = Client::query()->where('is_internal', true)->firstOrFail();
    $existing = Site::factory()->for($client)->create(['url' => 'https://xn--bcher-kva.example', 'url_key' => $keyed ? hash('sha256', 'xn--bcher-kva.example') : null, 'friendly_name' => 'Library']);
    $other = Site::factory()->for($client)->create(['url' => 'https://other.example']);
    $before = DB::table('sites')->orderBy('id')->get()->all();
    $this->actingAs($owner);

    if ($entry === 'web create') {
        $this->postJson('/sites', ['client_id' => $client->id, 'url' => $input, 'checks' => []])->assertUnprocessable()
            ->assertJsonPath('errors.url.0', 'Already monitored: Library');
    } elseif ($entry === 'settings') {
        $this->patchJson('/sites/'.$other->id, ['url' => $input])->assertUnprocessable()
            ->assertJsonPath('errors.url.0', 'Already monitored: Library');
    } elseif ($entry === 'API') {
        $this->withToken($owner->createToken('write', ['monitors:write'])->plainTextToken)
            ->postJson('/api/monitors', ['team_id' => 1, 'type' => 'http', 'url' => $input, 'checks' => []])->assertUnprocessable()
            ->assertJsonPath('errors.url.0', 'Already monitored: Library');
    } else {
        $account = json_decode(file_get_contents(base_path('tests/Fixtures/OhDear/import-account.json')), true, flags: JSON_THROW_ON_ERROR);
        $account['monitors'] = [array_replace($account['monitors'][0], ['url' => $input])];
        app(OhDearAccountTransport::class)->fake($account);
        $this->artisan('heptapod:import-ohdear', ['--token' => 'fixture-token'])->expectsOutputToContain('Already monitored: Library')->assertSuccessful();
    }

    expect(DB::table('sites')->orderBy('id')->get()->all())->toEqual($before);
    expect($existing->refresh()->url)->toBe('https://xn--bcher-kva.example');
})->with(['web create', 'settings', 'API', 'Oh Dear'])->with([
    'scheme' => 'http://xn--bcher-kva.example',
    'slash' => 'https://xn--bcher-kva.example/',
    'case and default port' => 'HTTPS://XN--BCHER-KVA.EXAMPLE:443/#discard',
    'IDN' => 'bücher.example/',
])->with(['indexed key' => true, 'legacy NULL key' => false]);

it('persists canonical addresses and accepts distinct paths and www addresses', function (string $input, string $expected): void {
    $owner = User::factory()->owner()->create();
    $client = Client::factory()->create();
    Site::factory()->for($client)->create(['url' => 'https://example.com']);

    $this->actingAs($owner)->postJson('/sites', ['client_id' => $client->id, 'url' => $input, 'checks' => []])->assertRedirect();

    $this->assertDatabaseHas('sites', ['url' => $expected, 'url_key' => hash('sha256', substr($expected, strpos($expected, '://') + 3))]);
})->with([
    ['new.example/', 'https://new.example'],
    [' HTTP://BÜCHER.EXAMPLE:80/a/?x=1#secret ', 'http://xn--bcher-kva.example/a/?x=1'],
    ['example.com/path', 'https://example.com/path'],
    ['www.example.com/', 'https://www.example.com'],
    ['example.com:8080/path', 'https://example.com:8080/path'],
    ['empty-query.example/?', 'https://empty-query.example'],
    ['trailing-dot.example./', 'https://trailing-dot.example'],
]);

it('returns a clear 422 for unsupported schemes and URL credentials', function (string $url, string $message): void {
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner)->postJson('/sites/probe', ['url' => $url])->assertUnprocessable()->assertJsonPath('errors.url.0', __($message));
})->with([
    ['ftp://example.com', 'sites.url_invalid'],
    ['javascript:alert(1)', 'sites.url_invalid'],
    ['https://user:password@example.com', 'api.url_credentials_unsupported'],
]);

it('returns only a generic duplicate message outside the API token client constraint', function (): void {
    $owner = User::factory()->owner()->create();
    $internal = Client::query()->where('is_internal', true)->firstOrFail();
    $hidden = Site::factory()->create(['url' => 'https://private.example', 'friendly_name' => 'Confidential client']);
    $token = $owner->createToken('constrained', ['monitors:write']);
    $token->accessToken->forceFill(['client_id' => $internal->id])->save();

    $response = $this->withToken($token->plainTextToken)->postJson('/api/monitors', ['team_id' => 1, 'type' => 'http', 'url' => 'http://PRIVATE.example/', 'checks' => []]);

    $response->assertUnprocessable()->assertJsonPath('errors.url.0', 'This address is already monitored.')->assertDontSee('Confidential client');
    expect(Site::query()->count())->toBe(1);
    expect($hidden->refresh()->url)->toBe('https://private.example');
});

it('keeps byte-identical legacy collision URLs valid for other field saves but refuses a changed colliding value', function (): void {
    $owner = User::factory()->owner()->create();
    Site::factory()->create(['url' => 'https://legacy.example']);
    $legacy = Site::factory()->create(['url' => 'http://LEGACY.example/', 'url_key' => null]);

    $this->actingAs($owner)->patchJson('/sites/'.$legacy->id, ['url' => $legacy->url, 'friendly_name' => 'Updated name'])->assertRedirect();
    expect($legacy->refresh()->url)->toBe('http://LEGACY.example/')->and($legacy->getAttribute('url_key'))->toBeNull()->and($legacy->friendly_name)->toBe('Updated name');
    $this->patchJson('/sites/'.$legacy->id, ['url' => 'https://legacy.example/'])->assertUnprocessable()->assertJsonValidationErrors('url');
    expect($legacy->refresh()->url)->toBe('http://LEGACY.example/');
    $this->patchJson('/sites/'.$legacy->id, ['url' => 'new-legacy.example/'])->assertRedirect();
    expect($legacy->refresh()->url)->toBe('https://new-legacy.example')->and($legacy->getAttribute('url_key'))->toBe(hash('sha256', 'new-legacy.example'));
});

it('reports visible duplicates with links and warns only for visible same-host or www variants', function (string $url, bool $blocked, bool $warned): void {
    $owner = User::factory()->owner()->create();
    $site = Site::factory()->create(['url' => 'https://example.com', 'friendly_name' => 'Store']);

    $response = $this->actingAs($owner)->postJson('/sites/probe', ['url' => $url])->assertOk();

    $response->assertJsonPath('duplicate_message', $blocked ? 'Already monitored: Store' : null)
        ->assertJsonPath('duplicate_site_id', $blocked ? $site->id : null)
        ->assertJsonPath('warning_site_name', $warned ? 'Store' : null);
})->with([
    ['http://EXAMPLE.com/', true, false],
    ['https://example.com/shop', false, true],
    ['https://www.example.com', false, true],
    ['https://www.example.com/shop', false, false],
]);

it('accepts a different path through the API without exposing hidden host warnings', function (): void {
    $owner = User::factory()->owner()->create();
    $internal = Client::query()->where('is_internal', true)->firstOrFail();
    Site::factory()->create(['url' => 'https://hidden.example', 'friendly_name' => 'Secret']);
    $token = $owner->createToken('write', ['monitors:write']);
    $token->accessToken->forceFill(['client_id' => $internal->id])->save();

    $this->withToken($token->plainTextToken)->postJson('/api/monitors', ['team_id' => 1, 'type' => 'http', 'url' => 'hidden.example/path', 'checks' => []])->assertCreated()->assertDontSee('Secret');

    $this->assertDatabaseHas('sites', ['url' => 'https://hidden.example/path']);
});

it('authorizes the site excluded from a settings probe and ignores its own address', function (): void {
    $owner = User::factory()->owner()->create();
    $site = Site::factory()->create(['url' => 'https://own.example']);
    $this->actingAs($owner)->postJson('/sites/probe', ['url' => 'http://own.example/', 'site_id' => $site->id])->assertOk()->assertJsonPath('duplicate_message', null);
    $member = User::factory()->create();
    $member->assignRole('Member');
    $this->actingAs($member)->postJson('/sites/probe', ['url' => 'http://own.example/', 'site_id' => $site->id])->assertForbidden();
});

it('accepts another path on an already monitored host at every write entry point', function (string $entry): void {
    $owner = User::factory()->owner()->create();
    $client = Client::query()->where('is_internal', true)->firstOrFail();
    Site::factory()->for($client)->create(['url' => 'https://paths.example']);
    $this->actingAs($owner);
    if ($entry === 'web create') {
        $this->postJson('/sites', ['client_id' => $client->id, 'url' => 'PATHS.example/shop', 'checks' => []])->assertRedirect();
    } elseif ($entry === 'settings') {
        $other = Site::factory()->for($client)->create(['url' => 'https://other.example']);
        $this->patchJson('/sites/'.$other->id, ['url' => 'PATHS.example/shop'])->assertRedirect();
    } elseif ($entry === 'API') {
        $this->withToken($owner->createToken('write', ['monitors:write'])->plainTextToken)
            ->postJson('/api/monitors', ['team_id' => 1, 'type' => 'http', 'url' => 'PATHS.example/shop', 'checks' => []])->assertCreated();
    } else {
        $account = json_decode(file_get_contents(base_path('tests/Fixtures/OhDear/import-account.json')), true, flags: JSON_THROW_ON_ERROR);
        $account['monitors'] = [array_replace($account['monitors'][0], ['url' => 'https://PATHS.example/shop'])];
        app(OhDearAccountTransport::class)->fake($account);
        $this->artisan('heptapod:import-ohdear', ['--token' => 'fixture-token'])->expectsOutputToContain('Create')->assertSuccessful();
    }
    $this->assertDatabaseHas('sites', ['url' => 'https://paths.example/shop', 'url_key' => hash('sha256', 'paths.example/shop')]);
})->with(['web create', 'settings', 'API', 'Oh Dear']);
