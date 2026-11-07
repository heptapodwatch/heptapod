<?php

declare(strict_types=1);

use Heptapod\Enums\ClientScope;
use Heptapod\Models\Check;
use Heptapod\Models\Client;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Testing\ApiSpec;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    app()->singleton(ApiSpec::class);
});

it('requires real unexpired bearer tokens and independent read write abilities', function (string $role): void {
    $user = User::factory()->create();
    $user->assignRole($role);
    $this->getJson('/api/me')->assertUnauthorized()->assertJsonStructure(['error']);
    $this->actingAs($user)->getJson('/api/me')->assertUnauthorized();
    $read = $user->createToken('read', ['monitors:read']);
    $this->withToken($read->plainTextToken)->getJson('/api/monitors')->assertOk();
    $this->postJson('/api/monitors', ['team_id' => 1, 'type' => 'http', 'url' => 'https://no-write.test'])->assertForbidden();
    $write = $user->createToken('write', ['monitors:write']);
    $this->withToken($write->plainTextToken)->getJson('/api/monitors')->assertForbidden();
    $this->postJson('/api/monitors', ['team_id' => 1, 'type' => 'http', 'url' => 'https://write.test'])->assertCreated();
    $identity = $user->createToken('identity', ['identity:read']);
    $this->withToken($identity->plainTextToken)->getJson('/api/me')->assertOk();
    $identity->accessToken->update(['expires_at' => now()->subMinute()]);
    $this->getJson('/api/me')->assertUnauthorized();
    $identity->accessToken->update(['expires_at' => null]);
    $identity->accessToken->delete();
    $this->getJson('/api/me')->assertUnauthorized();
})->with(['Owner', 'Admin']);

it('hides inaccessible sites checks and tag associations even with a broad token', function (): void {
    $allowedClient = Client::factory()->create();
    $allowed = Site::factory()->for($allowedClient)->create();
    $hidden = Site::factory()->create();
    $hiddenCheck = Check::factory()->for($hidden)->create();
    $allowed->attachTag('shared', 'sites');
    $hidden->attachTag('shared', 'sites');
    $hidden->attachTag('hidden', 'sites');
    $member = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $member->assignRole('Member');
    $member->clients()->attach($allowedClient);
    $this->withToken($member->createToken('member', ['*'])->plainTextToken);
    $this->getJson('/api/monitors')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $allowed->id);
    $this->getJson('/api/monitors/'.$hidden->id)->assertNotFound();
    $this->getJson('/api/monitors/url/'.urlencode($hidden->url))->assertNotFound();
    $this->putJson('/api/monitors/'.$hidden->id, ['notes' => 'not allowed'])->assertNotFound();
    $this->postJson('/api/checks/'.$hiddenCheck->id.'/disable')->assertNotFound();
    $this->getJson('/api/monitors/'.$hidden->id.'/checks/uptime/runs')->assertNotFound();
    $this->getJson('/api/tags')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.sites', [$allowed->id]);
    $this->postJson('/api/tags', ['team_id' => 1, 'name' => 'global'])->assertForbidden();
    expect($hiddenCheck->refresh()->enabled)->toBeTrue();
    $unroled = User::factory()->create();
    $this->withToken($unroled->createToken('unroled', ['*'])->plainTextToken)->getJson('/api/monitors')->assertForbidden();
});

it('limits authenticated API traffic at the array cache boundary with a spec error and retry information', function (): void {
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $this->withToken($owner->createToken('rate', ['identity:read'])->plainTextToken);
    for ($i = 0; $i < 60; $i++) {
        $this->getJson('/api/me')->assertOk();
    }
    $this->getJson('/api/me')->assertStatus(429)->assertHeader('Retry-After')->assertJsonStructure(['error']);
});

it('creates scoped tokens through the console action and rejects invented wildcard abilities', function (): void {
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $this->artisan('heptapod:token:create', ['user' => $user->email, '--name' => 'automation', '--abilities' => 'monitors:read,checks:write'])->assertSuccessful();
    expect($user->tokens()->firstOrFail()->abilities)->toBe(['monitors:read', 'checks:write']);
    expect(fn () => $this->artisan('heptapod:token:create', ['user' => $user->email, '--name' => 'bad', '--abilities' => 'monitors:*'])->run())->toThrow(ValidationException::class);
    expect($user->tokens()->count())->toBe(1);
    $member = User::factory()->create();
    $member->assignRole('Member');
    expect(fn () => $this->artisan('heptapod:token:create', ['user' => $member->email, '--name' => 'wildcard', '--abilities' => '*'])->run())->toThrow(ValidationException::class);
    expect($member->tokens()->count())->toBe(0);
});

it('names visible URL collisions and hides sites outside the token client constraint', function (string $locale, string $named, string $generic): void {
    $client = Client::factory()->create();
    $allowed = Site::factory()->for($client)->create();
    $originalUrl = $allowed->url;
    $visible = Site::factory()->for($client)->create(['friendly_name' => 'Visible site']);
    $hidden = Site::factory()->create(['friendly_name' => 'Confidential site']);
    $admin = User::factory()->create(['client_scope' => ClientScope::Restricted, 'locale' => $locale]);
    $admin->assignRole('Admin');
    $admin->clients()->attach($client);
    $token = $admin->createToken('collision', ['*']);
    $token->accessToken->forceFill(['client_id' => $client->id])->save();
    $this->withToken($token->plainTextToken);

    $this->getJson('/api/monitors/'.$visible->id)->assertOk();
    $this->getJson('/api/monitors/'.$hidden->id)->assertNotFound();
    foreach ([[$visible, $named], [$hidden, $generic]] as [$collision, $message]) {
        $this->putJson('/api/monitors/'.$allowed->id, ['url' => $collision->url])->assertUnprocessable()
            ->assertJsonPath('errors.url', [$message])->assertJsonPath('message', $message)->assertDontSee('Confidential site');
    }

    expect($allowed->refresh()->url)->toBe($originalUrl);
})->with([
    ['en', 'Already monitored: Visible site', 'This address is already monitored.'],
    ['de', 'Bereits überwacht: Visible site', 'Diese Adresse wird bereits überwacht.'],
]);

it('denies member monitor and check writes even on pre-existing privileged tokens', function (): void {
    $member = User::factory()->create();
    $member->assignRole('Member');
    $site = Site::factory()->create();
    $check = Check::factory()->for($site)->create();
    $this->withToken($member->createToken('legacy', ['*'])->plainTextToken);
    $this->postJson('/api/monitors', ['team_id' => 1, 'type' => 'http', 'url' => 'https://ssrf.test'])->assertForbidden();
    $this->putJson('/api/monitors/'.$site->id, ['url' => 'http://169.254.169.254'])->assertForbidden();
    $this->deleteJson('/api/monitors/'.$site->id)->assertForbidden();
    foreach (['enable', 'disable', 'request-run'] as $operation) {
        $this->postJson('/api/checks/'.$check->id.'/'.$operation)->assertForbidden();
    }
    expect($site->refresh()->url)->not->toBe('http://169.254.169.254')->and($check->refresh()->enabled)->toBeTrue();
});

it('rejects member write ability grants through the token command', function (string $ability): void {
    $member = User::factory()->create();
    $member->assignRole('Member');
    expect(fn () => $this->artisan('heptapod:token:create', ['user' => $member->email, '--name' => 'unsafe', '--abilities' => $ability])->run())->toThrow(ValidationException::class);
    expect($member->tokens()->count())->toBe(0);
})->with(['monitors:write', 'checks:write']);

it('permits scoped member snooze without configuration write authority', function (): void {
    $client = Client::factory()->create();
    $site = Site::factory()->for($client)->create();
    $check = Check::factory()->for($site)->create();
    $hidden = Check::factory()->create();
    $member = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $member->assignRole('Member');
    $member->clients()->attach($client);
    $this->artisan('heptapod:token:create', ['user' => $member->email, '--name' => 'snooze', '--abilities' => 'checks:snooze'])->assertSuccessful();
    $this->withToken($member->createToken('snooze', ['checks:snooze'])->plainTextToken);
    $this->postJson('/api/checks/'.$check->id.'/snooze', ['minutes' => 5])->assertOk();
    $this->postJson('/api/checks/'.$check->id.'/unsnooze')->assertOk();
    $this->postJson('/api/checks/'.$hidden->id.'/snooze', ['minutes' => 5])->assertNotFound();
    $this->postJson('/api/checks/'.$check->id.'/disable')->assertForbidden();
});

it('throttles unauthenticated token lookups with retry information', function (): void {
    for ($i = 0; $i < 60; $i++) {
        $this->withToken('invalid-token')->getJson('/api/me')->assertUnauthorized();
    }
    $this->getJson('/api/me')->assertStatus(429)->assertHeader('Retry-After');
});
