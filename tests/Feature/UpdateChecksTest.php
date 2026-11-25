<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Contracts\Http\FetchFailure;
use Heptapod\Contracts\Http\FetchFailureKind;
use Heptapod\Contracts\Http\FetchRequest;
use Heptapod\Contracts\Http\FetchResult;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Models\User;
use Heptapod\Settings\InstanceSettings;
use Heptapod\Testing\FakeOutboundHttp;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

it('does not contact the release source when disabled by server configuration', function (): void {
    config(['heptapod.updates.enabled_by_server' => false]);
    $fake = new FakeOutboundHttp;
    app()->instance(OutboundHttp::class, $fake);
    $this->artisan('heptapod:check-updates')->assertSuccessful();
    expect($fake->requests)->toBe([]);
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $this->actingAs($owner)->getJson(route('settings.updates.status'))->assertOk()
        ->assertJsonPath('update_status', 'disabled_by_server');
    $this->patchJson(route('settings.updates.update'), ['enabled' => true])->assertUnprocessable();
});

it('records a 404 with UTC timestamps while preserving the last successful release check', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05T00:30:00Z'));
    config(['heptapod.version' => 'unknown', 'heptapod.updates.enabled_by_server' => true,
        'heptapod.updates.release_url' => 'https://api.github.com/repos/heptapodwatch/heptapod/releases/latest']);
    $fake = (new FakeOutboundHttp)->respondUsing(static fn (FetchRequest $request): FetchResult => new FetchResult(
        status: 404, failure: new FetchFailure(FetchFailureKind::HttpStatus, 404),
    ));
    app()->instance(OutboundHttp::class, $fake);
    $this->artisan('heptapod:check-updates')->assertSuccessful();
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $this->actingAs($owner)->getJson(route('settings.updates.status'))->assertOk()
        ->assertJsonPath('update_status', 'unreachable')->assertJsonPath('http_status', 404)
        ->assertJsonPath('last_success_at', null)->assertJsonPath('installed_version', 'unknown')
        ->assertJsonPath('checked_at', '2026-10-05T00:30:00+00:00');
    expect($fake->requests)->toHaveCount(1)->and($fake->requests[0]['request']->publicTargetsOnly)->toBeTrue();
});

it('compares a validated release with a configured semantic installed version', function (string $installed, string $expected): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05T00:30:00Z'));
    config(['heptapod.version' => $installed, 'heptapod.updates.enabled_by_server' => true]);
    $fake = (new FakeOutboundHttp)->respondUsing(static fn (FetchRequest $request): FetchResult => new FetchResult(
        status: 200, body: json_encode(['tag_name' => 'v1.2.3', 'html_url' => config('heptapod.updates.release_page_url').'/tag/v1.2.3',
            'published_at' => '2026-10-04T18:00:00Z', 'body' => 'Ignored content'], JSON_THROW_ON_ERROR),
    ));
    app()->instance(OutboundHttp::class, $fake);
    $this->artisan('heptapod:check-updates')->assertSuccessful();
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $response = $this->actingAs($owner)->getJson(route('settings.updates.status'))->assertOk()
        ->assertJsonPath('update_status', $expected)->assertJsonPath('latest_version', 'v1.2.3');
    expect($response->getContent())->not->toContain('Ignored content');
})->with([
    ['v1.2.2', 'available'], ['v1.2.3', 'current'], ['v1.2.4', 'current'],
    ['sha-abc123', 'development'], ['unknown', 'development'],
]);

it('preserves Admin read access while restricting validated release-check changes to Owners', function (): void {
    config(['heptapod.updates.enabled_by_server' => true]);
    $admin = User::factory()->create();
    $admin->assignRole('Admin');
    $this->actingAs($admin)->getJson(route('settings.updates.status'))->assertOk();
    $this->patchJson(route('settings.updates.update'), ['enabled' => false])->assertForbidden();
    expect(app(InstanceSettings::class)->updates_enabled)->toBeTrue();
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $this->actingAs($owner)->patchJson(route('settings.updates.update'), [])->assertUnprocessable()->assertJsonValidationErrors('enabled');
    $this->patchJson(route('settings.updates.update'), ['enabled' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('enabled');
    $this->patchJson(route('settings.updates.update'), ['enabled' => false])->assertOk()->assertJsonPath('update_status', 'disabled_by_user');
    expect(app(InstanceSettings::class)->updates_enabled)->toBeFalse();
});

it('nudges only Owners and Admins in the shell, and only while checks are on and a newer release is out', function (): void {
    config(['heptapod.version' => 'v1.2.2', 'heptapod.updates.enabled_by_server' => true]);
    $fake = (new FakeOutboundHttp)->respondUsing(static fn (FetchRequest $request): FetchResult => new FetchResult(
        status: 200, body: json_encode(['tag_name' => 'v1.2.3', 'html_url' => config('heptapod.updates.release_page_url').'/tag/v1.2.3',
            'published_at' => '2026-10-04T18:00:00Z'], JSON_THROW_ON_ERROR),
    ));
    app()->instance(OutboundHttp::class, $fake);
    $this->artisan('heptapod:check-updates')->assertSuccessful();
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $this->actingAs($owner)->get(route('sites.index'))->assertInertia(fn (Assert $page): Assert => $page
        ->where('appVersion', ['installed_version' => 'v1.2.2', 'available_version' => 'v1.2.3']));
    $member = User::factory()->create();
    $member->assignRole('Member');
    $response = $this->actingAs($member)->get(route('sites.index'))->assertInertia(fn (Assert $page): Assert => $page
        ->where('appVersion', ['installed_version' => 'v1.2.2', 'available_version' => null]));
    expect($response->getContent())->not->toContain('v1.2.3');

    $this->actingAs($owner)->patchJson(route('settings.updates.update'), ['enabled' => false])->assertOk();
    $this->get(route('sites.index'))->assertInertia(fn (Assert $page): Assert => $page->where('appVersion.available_version', null));
});

it('never offers a release tag that is not a published vX.Y.Z tag', function (): void {
    config(['heptapod.version' => 'v1.2.2', 'heptapod.updates.enabled_by_server' => true]);
    // Composer's parser accepts this (build metadata), but it would land in a copyable shell command.
    $tag = 'v1.2.3+pwned;touch';
    app()->instance(OutboundHttp::class, (new FakeOutboundHttp)->respondUsing(static fn (FetchRequest $request): FetchResult => new FetchResult(
        status: 200, body: json_encode(['tag_name' => $tag, 'html_url' => config('heptapod.updates.release_page_url').'/tag/v1.2.3',
            'published_at' => '2026-10-04T18:00:00Z'], JSON_THROW_ON_ERROR),
    )));
    $this->artisan('heptapod:check-updates')->assertSuccessful();
    expect(DB::table('update_checks')->value('status'))->toBe('invalid_response');

    // A tag stored before ingestion enforced the format is ignored on read.
    DB::table('update_checks')->where('id', 1)->update(['status' => 'reachable', 'latest_version' => $tag,
        'release_url' => config('heptapod.updates.release_page_url').'/tag/v1.2.3', 'published_at' => now()]);
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $this->actingAs($owner)->getJson(route('settings.updates.status'))->assertOk()
        ->assertJsonPath('update_status', 'invalid_response')->assertJsonPath('latest_version', null)->assertJsonPath('release_url', null);
    $this->get(route('sites.index'))->assertInertia(fn (Assert $page): Assert => $page->where('appVersion.available_version', null));
    expect($this->get(route('settings.updates.edit'))->getContent())->not->toContain('pwned');
});
