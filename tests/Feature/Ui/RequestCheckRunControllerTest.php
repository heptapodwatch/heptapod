<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\Http\FetchResult;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Contracts\RunPhase;
use Heptapod\Enums\ClientScope;
use Heptapod\Jobs\RunCheck;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRunAttempt;
use Heptapod\Models\Client;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Testing\FakeOutboundHttp;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-03T12:00:00Z'));
});

it('queues an authorized check once and reports repeated requests inline', function (): void {
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $check = Check::factory()->create();
    $path = "/sites/{$check->site_id}/checks/{$check->id}/run";

    $this->actingAs($user)->post($path)->assertRedirect("/sites/{$check->site_id}/checks/{$check->id}");
    expect($check->refresh()->claim_token)->not->toBeNull();
    $claim = $check->claim_token;
    $this->from("/sites/{$check->site_id}/checks/{$check->id}")->post($path)
        ->assertRedirect("/sites/{$check->site_id}/checks/{$check->id}")
        ->assertSessionHasErrors(['check' => __('api.run_unavailable')]);
    $admin = User::factory()->create();
    $admin->assignRole('Admin');
    $this->actingAs($admin)->post($path)
        ->assertRedirect("/sites/{$check->site_id}/checks/{$check->id}")
        ->assertSessionHasErrors(['check' => __('api.run_unavailable')]);
    expect($check->refresh()->claim_token)->toBe($claim)
        ->and(CheckRunAttempt::query()->where('check_id', $check->id)->count())->toBe(1);
});

it('rate limits manual requests for 30 seconds after a completed run', function (): void {
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $check = Check::factory()->create();
    $path = "/sites/{$check->site_id}/checks/{$check->id}/run";
    $target = "/sites/{$check->site_id}/checks/{$check->id}";
    $http = (new FakeOutboundHttp)->respondUsing(static fn (): FetchResult => new FetchResult(200, 'ok'));
    app()->instance(OutboundHttp::class, $http);

    $this->actingAs($user)->post($path)->assertRedirect($target);
    $check->refresh();
    $definition = app(CheckRegistry::class)->get($check->type)->definition();
    app()->call(new RunCheck($check->id, $check->claim_token, $definition->timeout, $definition->queue)->handle(...));

    expect($http->requests)->toHaveCount(1)
        ->and($check->refresh()->claim_token)->toBeNull()
        ->and(CheckRunAttempt::query()->where('check_id', $check->id)->sole()->phase)->toBe(RunPhase::Done);
    $this->post($path)->assertTooManyRequests()->assertHeader('Retry-After', '30');
    expect(CheckRunAttempt::query()->where('check_id', $check->id)->count())->toBe(1)
        ->and($check->refresh()->claim_token)->toBeNull();

    $this->travel(31)->seconds();
    $this->post($path)->assertRedirect($target)->assertSessionHasNoErrors();
    expect(CheckRunAttempt::query()->where('check_id', $check->id)->count())->toBe(2)
        ->and($check->refresh()->claim_token)->not->toBeNull();
});

it('rejects restricted and unauthorized writes before scheduling and reports unavailable checks inline', function (): void {
    $client = Client::factory()->create();
    $check = Check::factory()->for(Site::factory()->for($client))->create();
    $hidden = Check::factory()->create();
    $path = "/sites/{$check->site_id}/checks/{$check->id}/run";
    $user = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $user->assignRole('Member');
    $user->clients()->attach($client);

    $this->actingAs($user)->post($path)->assertForbidden();
    $this->post("/sites/{$hidden->site_id}/checks/{$hidden->id}/run")->assertNotFound();
    expect($check->refresh()->claim_token)->toBeNull();

    $user->syncRoles('Admin');
    $check->update(['enabled' => false]);
    $this->postJson($path)->assertUnprocessable()->assertJsonValidationErrors('check');
    expect($check->refresh()->claim_token)->toBeNull();
});
