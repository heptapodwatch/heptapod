<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Actions\CreateApiToken;
use Heptapod\Enums\ClientScope;
use Heptapod\Models\Client;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-20T12:00:00Z'));
});

it('issues the minimal read-only preset documented for agents', function (): void {
    $skill = file_get_contents(base_path('.agents/skills/heptapod-api/SKILL.md'));
    expect($skill)->toBeString();
    expect(preg_match('/preset `([^`]+)` \(([^)]+)\)/', $skill, $matches))->toBe(1);
    $preset = $matches[1];
    $abilities = array_map(fn (string $ability): string => trim($ability, ' `'), explode(',', $matches[2]));
    expect($abilities)->toHaveCount(6);
    foreach ($abilities as $ability) {
        expect($ability)->toEndWith(':read');
    }
    $owner = User::factory()->create();
    $owner->assignRole('Owner');

    $this->actingAs($owner)->getJson(route('settings.api-tokens.data'))
        ->assertOk()->assertJsonPath('presets.'.$preset, $abilities);
    $this->postJson(route('settings.api-tokens.store'), ['name' => 'Agent reader', 'preset' => $preset])
        ->assertCreated()->assertJsonStructure(['token']);

    expect($owner->tokens()->sole()->abilities)->toBe($abilities);
});

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

it('maps token presets from one supported ability list and keeps role limits', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');
    $data = $this->actingAs($admin)->getJson(route('settings.api-tokens.data'))->assertOk()->json();
    expect($data['presets']['read'])->toContain('monitors:read', 'maintenance:read')->not->toContain('monitors:write', 'maintenance:write');
    expect($data['presets']['write'])->toContain('maintenance:write');
    expect($data['presets']['manage'])->toContain('monitors:write', 'maintenance:write');
    $this->postJson(route('settings.api-tokens.store'), ['name' => 'reader', 'preset' => 'read'])->assertCreated();
    expect($admin->tokens()->firstOrFail()->abilities)->toBe($data['presets']['read']);
    $member = User::factory()->create();
    $member->assignRole('Member');
    $memberData = $this->actingAs($member)->getJson(route('settings.api-tokens.data'))->assertOk()->json();
    expect($memberData['presets']['read'])->toContain('maintenance:read');
    expect($memberData['presets']['manage'])->not->toContain('monitors:write', 'maintenance:write');
    $this->postJson(route('settings.api-tokens.store'), ['name' => 'scoped manager', 'preset' => 'manage'])->assertCreated();
    expect($member->tokens()->firstOrFail()->abilities)->toBe($memberData['presets']['manage']);
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
    expect($member->tokens()->count())->toBe(0);
});
