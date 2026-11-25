<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Actions\CreateApiToken;
use Heptapod\Contracts\CheckKey;
use Heptapod\Enums\ClientScope;
use Heptapod\Models\Check;
use Heptapod\Models\Client;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-20T12:00:00Z'));
});

it('issues the read-everything starting point documented for agents', function (): void {
    $skill = file_get_contents(base_path('.agents/skills/heptapod-api/SKILL.md'));
    expect($skill)->toBeString();
    expect(preg_match('/\*\*Read everything\*\* \(([^)]+)\)/', $skill, $matches))->toBe(1);
    $abilities = array_map(fn (string $ability): string => trim($ability, ' `'), explode(',', $matches[1]));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');

    $this->actingAs($owner)->getJson(route('settings.api-tokens.data'))
        ->assertOk()->assertJsonPath('starting_points.read-everything', $abilities);
    $this->postJson(route('settings.api-tokens.store'), ['name' => 'Agent reader', 'abilities' => $abilities])
        ->assertCreated()->assertJsonStructure(['token']);

    expect($owner->tokens()->sole()->abilities)->toBe($abilities)->toBe(['identity:read', 'monitors:read', 'checks:read',
        'incidents:read', 'uptime:read', 'downtime:read', 'tags:read', 'maintenance:read', 'activity:read', 'upcoming:read', 'clients:read']);
});

it('offers every role its grantable abilities and the deploy starting point only to Owners and Admins', function (string $role, bool $privileged): void {
    $user = User::factory()->create();
    $user->assignRole($role);
    $data = $this->actingAs($user)->getJson(route('settings.api-tokens.data'))->assertOk()->json();

    expect($data['starting_points']['read-everything'])->toHaveCount(11)
        ->and($data['starting_points']['deploy-automation'])->toBe($privileged ? ['monitors:read', 'checks:read', 'maintenance:read', 'maintenance:write', 'checks:run'] : null)
        ->and(in_array('checks:run', $data['abilities'], true))->toBe($privileged)
        ->and($data['abilities'])->not->toContain('*');
})->with([['Owner', true], ['Admin', true], ['Member', false]]);

it('rejects session authentication with a forged bearer header', function (): void {
    $owner = User::factory()->create();
    $owner->assignRole('Owner');

    $this->actingAs($owner)->withToken('not-a-real-token')->getJson('/api/me')->assertUnauthorized();
});

it('intersects a token client constraint with current access on every API request', function (): void {
    $one = Client::factory()->create();
    $two = Client::factory()->create();
    $firstSite = Site::factory()->for($one)->create();
    $secondSite = Site::factory()->for($two)->create();
    $member = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $member->assignRole('Member');
    $member->clients()->attach([$one->id, $two->id]);
    $token = app(CreateApiToken::class)($member, 'scoped', ['monitors:read'], $one->id);

    $this->withToken($token)->getJson('/api/monitors')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $firstSite->id);
    $this->getJson('/api/monitors/'.$secondSite->id)->assertNotFound();
    $member->clients()->detach($one->id);
    $this->getJson('/api/monitors')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/monitors/'.$firstSite->id)->assertNotFound();
});

it('keeps the legacy API presets as frozen aliases with role limits', function (): void {
    $historical = ['identity:read', 'monitors:read', 'monitors:write', 'checks:read', 'checks:write', 'checks:snooze', 'incidents:read',
        'incidents:note', 'uptime:read', 'downtime:read', 'tags:read', 'tags:write', 'maintenance:read', 'maintenance:write'];
    $expected = [
        'read' => ['identity:read', 'monitors:read', 'checks:read', 'incidents:read', 'uptime:read', 'downtime:read', 'tags:read', 'clients:read', 'maintenance:read'],
        'agent-read' => ['identity:read', 'monitors:read', 'checks:read', 'incidents:read', 'activity:read', 'upcoming:read'],
        'write' => $historical,
        'manage' => $historical,
    ];
    $admin = User::factory()->create();
    $admin->assignRole('Admin');
    $this->actingAs($admin);
    foreach ($expected as $preset => $abilities) {
        $this->postJson(route('settings.api-tokens.store'), ['name' => $preset, 'preset' => $preset])->assertCreated();
        expect($admin->tokens()->where('name', $preset)->sole()->abilities)->toBe($abilities);
    }
    $member = User::factory()->create();
    $member->assignRole('Member');
    $this->actingAs($member)->postJson(route('settings.api-tokens.store'), ['name' => 'scoped manager', 'preset' => 'manage'])->assertCreated();
    expect($member->tokens()->sole()->abilities)->toBe(array_values(array_diff($historical, ['monitors:write', 'checks:write', 'maintenance:write'])));
});

it('lets a deploy token request a run and open a window but not change configuration', function (): void {
    $site = Site::factory()->create();
    $check = Check::factory()->for($site)->create(['type' => CheckKey::Uptime]);
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $token = $this->actingAs($owner)->postJson(route('settings.api-tokens.store'), ['name' => 'CI deploy',
        'abilities' => ['monitors:read', 'checks:read', 'checks:run', 'maintenance:read', 'maintenance:write']])->assertCreated()->json('token');
    app('auth')->forgetGuards();
    $this->withToken($token);

    $this->postJson('/api/checks/'.$check->id.'/request-run')->assertOk();
    $this->postJson('/api/monitors/'.$site->id.'/start-maintenance', ['stop_maintenance_after_seconds' => 300, 'name' => 'Deploy'])->assertOk();
    $this->postJson('/api/checks/'.$check->id.'/disable')->assertForbidden();
    $this->postJson('/api/checks/'.$check->id.'/snooze', ['minutes' => 5])->assertForbidden();
    $this->putJson('/api/monitors/'.$site->id, ['url' => 'https://changed.test'])->assertForbidden();

    expect($check->refresh()->enabled)->toBeTrue()->and($check->claim_token)->not->toBeNull()
        ->and($site->refresh()->url)->not->toBe('https://changed.test');
});

it('rejects new wildcard, role-exceeding, expired and empty grants through settings and the token command', function (): void {
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $member = User::factory()->create();
    $member->assignRole('Member');
    $store = route('settings.api-tokens.store');

    $this->actingAs($owner)->postJson($store, ['name' => 'wildcard', 'abilities' => ['*']])->assertUnprocessable()->assertJsonValidationErrors('abilities.0');
    expect(fn () => $this->artisan('heptapod:token:create', ['user' => $owner->email, '--name' => 'wildcard', '--abilities' => '*'])->run())->toThrow(ValidationException::class);
    $this->postJson($store, ['name' => 'empty', 'abilities' => []])->assertUnprocessable()->assertJsonValidationErrors('abilities');
    $this->postJson($store, ['name' => 'expired', 'abilities' => ['monitors:read'], 'expires_at' => now()->subMinute()->toIso8601String()])
        ->assertUnprocessable()->assertJsonValidationErrors('expires_at');
    $this->actingAs($member)->postJson($store, ['name' => 'deploy', 'abilities' => ['checks:run', 'maintenance:write']])
        ->assertUnprocessable()->assertJsonValidationErrors(['abilities.0', 'abilities.1']);

    expect($owner->tokens()->count())->toBe(0)->and($member->tokens()->count())->toBe(0);
});

it('shows a new secret once and lists or revokes only own token metadata', function (): void {
    $client = Client::factory()->create();
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $other = User::factory()->create();
    $other->assignRole('Owner');
    $otherToken = $other->createToken('other', ['monitors:read']);
    $issued = $this->actingAs($owner)->postJson(route('settings.api-tokens.store'), [
        'name' => 'agency', 'abilities' => ['monitors:read'], 'client_id' => $client->id,
        'expires_at' => now()->addDay()->toIso8601String(),
    ])->assertCreated()->assertHeader('Cache-Control', 'no-store, private')->assertJsonStructure(['token']);
    $tokens = $this->getJson(route('settings.api-tokens.data'))->assertOk()->assertJsonCount(1, 'tokens')
        ->assertJsonPath('tokens.0.client_id', $client->id);
    expect($tokens->getContent())->not->toContain($issued->json('token'))->not->toContain($otherToken->plainTextToken);
    $this->deleteJson(route('settings.api-tokens.destroy', $otherToken->accessToken->id))->assertNotFound();
    $this->deleteJson(route('settings.api-tokens.destroy', $owner->tokens()->sole()->id))->assertNoContent();
    expect($owner->tokens()->count())->toBe(0)->and($other->tokens()->count())->toBe(1);
});

it('rejects granting a token a client outside the current member scope', function (): void {
    $client = Client::factory()->create();
    $member = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $member->assignRole('Member');

    expect(fn () => app(CreateApiToken::class)($member, 'hidden', ['monitors:read'], $client->id))
        ->toThrow(ValidationException::class);
    $this->actingAs($member)->postJson(route('settings.api-tokens.store'), ['name' => 'hidden', 'abilities' => ['monitors:read'], 'client_id' => $client->id])
        ->assertUnprocessable()->assertJsonValidationErrors('client_id');
    expect($member->tokens()->count())->toBe(0);
});
