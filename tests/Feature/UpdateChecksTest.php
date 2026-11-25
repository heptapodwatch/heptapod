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

it('tells only Owners and Admins about a newer release in the shell, and only while checks are on', function (): void {
    config(['heptapod.version' => 'v1.2.2', 'heptapod.updates.enabled_by_server' => true]);
    app()->instance(OutboundHttp::class, fakeReleaseSource([releaseItem('v1.2.3', 'A headline.'), releaseItem('v1.2.2', 'Installed.')]));
    $this->artisan('heptapod:check-updates')->assertSuccessful();
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $this->actingAs($owner)->get(route('sites.index'))->assertInertia(fn (Assert $page): Assert => $page
        ->where('appUpdate', ['status' => 'available', 'security' => false, 'latest' => 'v1.2.3'])
        ->where('heptapodVersion', 'v1.2.2'));
    $member = User::factory()->create();
    $member->assignRole('Member');
    $response = $this->actingAs($member)->get(route('sites.index'))->assertInertia(fn (Assert $page): Assert => $page
        ->where('appUpdate', null)->where('heptapodVersion', 'v1.2.2'));
    expect($response->getContent())->not->toContain('v1.2.3');

    $this->actingAs($owner)->patchJson(route('settings.updates.update'), ['enabled' => false])->assertOk();
    $this->get(route('sites.index'))->assertInertia(fn (Assert $page): Assert => $page
        ->where('appUpdate', ['status' => 'disabled_by_user', 'security' => false, 'latest' => null]));
});

it('explains the skipped releases as capped plain text from the fixed note sections', function (): void {
    config(['heptapod.version' => 'v1.2.0', 'heptapod.updates.enabled_by_server' => true]);
    $long = str_repeat('word ', 200);
    app()->instance(OutboundHttp::class, fakeReleaseSource([
        releaseItem('v1.2.3', "Faster checks <script>alert(1)</script> and a [calm](javascript:alert(1)) page.\n\n## Improved\n- **Quicker.** Done. (#9)\n\n## Upgrade\n- **Security: not this one** `cd /opt`"),
        releaseItem('v1.2.2', "Security release.\n\n## Fixed\n- **Security: updated Go** for an advisory. (#8)\n- <img src=x onerror=alert(1)>Plain fix."),
        releaseItem('v1.2.1', "Sessions end.\n\n## Before you upgrade\n- **Everyone signs in again.** ".$long."\n- ![logo](https://x.test/a.png) Second **step**.\n\n## Security\n- Hardened \u{202E}headers."),
        releaseItem('v1.2.0', "Installed.\n\n## Fixed\n- Security: old news."),
        releaseItem('v1.2.4-rc.1', 'Pre-release tag.'),
        [...releaseItem('v1.2.3', 'Duplicate draft.'), 'draft' => true],
        [...releaseItem('v1.1.9', 'Wrong page.'), 'html_url' => 'https://example.test/tag/v1.1.9'],
    ]));
    $this->artisan('heptapod:check-updates')->assertSuccessful();
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $response = $this->actingAs($owner)->getJson(route('settings.updates.status'))->assertOk()
        ->assertJsonPath('update_status', 'available')->assertJsonPath('security', true)->assertJsonPath('releases_truncated', false)
        ->assertJsonPath('releases.*.tag', ['v1.2.3', 'v1.2.2', 'v1.2.1'])
        ->assertJsonPath('releases.0.headline', 'Faster checks alert(1) and a calm page.')
        ->assertJsonPath('releases.0.before', [])->assertJsonPath('releases.0.security', [])
        ->assertJsonPath('releases.1.security', ['Security: updated Go for an advisory. (#8)'])
        ->assertJsonPath('releases.2.headline', 'Sessions end.')
        ->assertJsonPath('releases.2.security', ['Hardened headers.'])
        ->assertJsonPath('releases.2.before.1', 'logo Second step.');
    expect(mb_strlen($response->json('releases.2.before.0')))->toBe(400)
        ->and($response->json('releases.2.before.0'))->toStartWith('Everyone signs in again. word')->toEndWith('…')
        ->and($response->getContent())->not->toContain('<script')->not->toContain('javascript:')->not->toContain('onerror')
        ->not->toContain('rc.1')->not->toContain('Duplicate draft')->not->toContain('Wrong page');
    $this->get(route('sites.index'))->assertInertia(fn (Assert $page): Assert => $page
        ->where('appUpdate', ['status' => 'available', 'security' => true, 'latest' => 'v1.2.3']));
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
    $this->get(route('sites.index'))->assertInertia(fn (Assert $page): Assert => $page->where('appUpdate.latest', null));
    expect($this->get(route('settings.updates.edit'))->getContent())->not->toContain('pwned');
});

/** @return array<string, mixed> */
function releaseItem(string $tag, string $body): array
{
    return ['tag_name' => $tag, 'html_url' => config('heptapod.updates.release_page_url').'/tag/'.$tag, 'draft' => false,
        'prerelease' => str_contains($tag, '-'), 'published_at' => '2026-10-04T18:00:00Z', 'body' => $body];
}

/** The latest endpoint answers with the newest stable item, the list endpoint with every item. @param list<array<string, mixed>> $items */
function fakeReleaseSource(array $items): FakeOutboundHttp
{
    return (new FakeOutboundHttp)->respondUsing(static fn (FetchRequest $request): FetchResult => new FetchResult(status: 200,
        body: json_encode(str_ends_with($request->url, '/latest') ? $items[0] : $items, JSON_THROW_ON_ERROR)));
}
