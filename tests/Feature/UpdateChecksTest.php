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
use Illuminate\Support\Facades\Log;
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
    $fake = fakeReleaseSource([releaseItem('v1.2.3', 'A headline.'), releaseItem('v1.2.2', 'Installed.')]);
    app()->instance(OutboundHttp::class, $fake);
    $this->artisan('heptapod:check-updates')->assertSuccessful();
    // Exactly the latest release, then one bounded page of the list beside it.
    expect(array_map(static fn (array $sent): string => $sent['request']->url, $fake->requests))->toBe([
        'https://api.github.com/repos/heptapodwatch/heptapod/releases/latest',
        'https://api.github.com/repos/heptapodwatch/heptapod/releases?per_page=20',
    ]);
    foreach (['Owner', 'Admin'] as $role) {
        $viewer = User::factory()->create();
        $viewer->assignRole($role);
        $this->actingAs($viewer)->get(route('sites.index'))->assertInertia(fn (Assert $page): Assert => $page
            ->where('appUpdate', ['status' => 'available', 'security' => false, 'latest' => 'v1.2.3'])
            ->where('heptapodVersion', 'v1.2.2'));
    }
    $member = User::factory()->create();
    $member->assignRole('Member');
    $response = $this->actingAs($member)->get(route('sites.index'))->assertInertia(fn (Assert $page): Assert => $page
        ->where('appUpdate', null)->where('heptapodVersion', 'v1.2.2'));
    expect($response->getContent())->not->toContain('v1.2.3');

    $owner = User::factory()->owner()->create();
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
        releaseItem('v123456.0.0', 'A major Composer cannot compare.'),
    ]));
    $this->artisan('heptapod:check-updates')->assertSuccessful();
    $owner = User::factory()->owner()->create();
    $response = $this->actingAs($owner)->getJson(route('settings.updates.status'))->assertOk()
        ->assertJsonPath('update_status', 'available')->assertJsonPath('security', true)->assertJsonPath('releases_truncated', false)
        ->assertJsonPath('releases.*.tag', ['v1.2.3', 'v1.2.2', 'v1.2.1'])
        ->assertJsonPath('releases.0.url', config('heptapod.updates.release_page_url').'/tag/v1.2.3')
        ->assertJsonPath('releases.0.headline', 'Faster checks alert(1) and a calm page.')
        ->assertJsonPath('releases.0.before', [])->assertJsonPath('releases.0.security', [])
        // The prefix classifies the item and is not repeated under the Security heading.
        ->assertJsonPath('releases.1.security', ['Updated Go for an advisory. (#8)'])
        ->assertJsonPath('releases.2.headline', 'Sessions end.')
        ->assertJsonPath('releases.2.security', ['Hardened headers.'])
        ->assertJsonPath('releases.2.before.1', 'logo Second step.');
    expect(mb_strlen($response->json('releases.2.before.0')))->toBe(400)
        ->and($response->json('releases.2.before.0'))->toStartWith('Everyone signs in again. word')->toEndWith('…')
        ->and($response->getContent())->not->toContain('<script')->not->toContain('javascript:')->not->toContain('onerror')
        ->not->toContain('rc.1')->not->toContain('Duplicate draft')->not->toContain('v123456');
    $this->get(route('sites.index'))->assertInertia(fn (Assert $page): Assert => $page
        ->where('appUpdate', ['status' => 'available', 'security' => true, 'latest' => 'v1.2.3']));
});

it('renders each skipped release note as safe HTML on the Updates page only, capped with a pointer to GitHub', function (): void {
    config(['heptapod.version' => 'v1.2.0', 'heptapod.updates.enabled_by_server' => true]);
    $body = "Headline.\n\n## Improved\n- **Bold** item with [docs](https://heptapod.watch/docs/) and [bad](javascript:alert(1)).\n\n"
        ."<script>alert(1)</script><iframe src=\"https://x.test\"></iframe>\n\n![tracking *pixel* `<b>`](https://x.test/p.png)\n\n`code`";
    app()->instance(OutboundHttp::class, fakeReleaseSource([
        releaseItem('v1.2.2', $body),
        releaseItem('v1.2.1', 'Long.'."\n\n".str_repeat('word ', 5000)),
        releaseItem('v1.2.0', 'Installed.'),
    ]));
    $this->artisan('heptapod:check-updates')->assertSuccessful();
    $owner = User::factory()->owner()->create();
    $response = $this->actingAs($owner)->getJson(route('settings.updates.status'))->assertOk()
        ->assertJsonPath('releases.0.notes_truncated', false)->assertJsonPath('releases.1.notes_truncated', true);
    $html = $response->json('releases.0.notes_html');
    expect($html)->toContain('<h2>Improved</h2>')->toContain('<strong>Bold</strong>')->toContain('<code>code</code>')
        ->toContain('<a target="_blank" rel="noopener noreferrer nofollow" href="https://heptapod.watch/docs/">docs</a>')
        // The unsafe link keeps its text but loses its target; raw HTML and the image never reach the page.
        ->toContain('<a target="_blank" rel="noopener noreferrer nofollow">bad</a>')
        // The image is its description as escaped plain text, inline markup flattened.
        ->toContain('tracking pixel &lt;b&gt;')->not->toContain('<em>pixel')
        ->not->toContain('javascript:')->not->toContain('<script')->not->toContain('<iframe')->not->toContain('<img')->not->toContain('x.test');
    // 16 KiB of markdown are stored and rendered; the rest is on GitHub.
    expect(strlen(strip_tags($response->json('releases.1.notes_html'))))->toBeLessThanOrEqual(16384)->toBeGreaterThan(16000);
    $this->get(route('settings.updates.edit'))->assertInertia(fn (Assert $page): Assert => $page
        ->where('updatesPage.updates.releases.0.notes_html', $html));

    // The shell prop and System health read the status without any note.
    expect($this->get(route('sites.index'))->getContent())->not->toContain('Headline.')->not->toContain('notes_html')
        ->and($this->getJson(route('settings.system.data'))->assertOk()->getContent())->not->toContain('notes_html')->not->toContain('tracking pixel');
});

it('records a release whose note holds a NUL, which PostgreSQL jsonb would reject', function (): void {
    config(['heptapod.version' => 'v1.2.0', 'heptapod.updates.enabled_by_server' => true]);
    app()->instance(OutboundHttp::class, fakeReleaseSource([releaseItem('v1.2.1', "Fixed.\n\n## Fixed\n- Null\u{0}byte\u{7} gone.\r\n"), releaseItem('v1.2.0', 'Installed.')]));
    $this->artisan('heptapod:check-updates')->assertSuccessful();
    expect(DB::table('update_checks')->value('status'))->toBe('reachable')
        ->and(DB::table('update_checks')->value('releases'))->not->toContain('\u0000')->not->toContain('\u0007');
    $this->actingAs(User::factory()->owner()->create())->getJson(route('settings.updates.status'))->assertOk()
        ->assertJsonPath('latest_version', 'v1.2.1')->assertJsonPath('releases.0.headline', 'Fixed.');
});

it('says the count is a lower bound when the fetched page never reaches the installed release', function (): void {
    config(['heptapod.version' => 'v1.0.0', 'heptapod.updates.enabled_by_server' => true]);
    // A full page of 20 whose last entry is a pre-release: 19 stable releases, and older ones may be missing.
    $items = array_map(static fn (int $minor): array => releaseItem('v2.'.$minor.'.0', 'Release.'), range(19, 1));
    app()->instance(OutboundHttp::class, fakeReleaseSource([...$items, releaseItem('v2.0.0-rc.1', 'Pre-release.')]));
    $this->artisan('heptapod:check-updates')->assertSuccessful();
    $this->actingAs(User::factory()->owner()->create())->getJson(route('settings.updates.status'))->assertOk()
        ->assertJsonCount(19, 'releases')->assertJsonPath('releases_truncated', true);
});

it('still records the latest release when the list fails, and drops the older notes instead of showing them as complete', function (): void {
    config(['heptapod.version' => 'v1.2.0', 'heptapod.updates.enabled_by_server' => true]);
    app()->instance(OutboundHttp::class, fakeReleaseSource([releaseItem('v1.2.1', 'Old notes.'), releaseItem('v1.2.0', 'Installed.')]));
    $this->artisan('heptapod:check-updates')->assertSuccessful();
    // The source's own link is never used, even when it names another tag.
    $latest = [...releaseItem('v1.2.2', 'New.'), 'html_url' => config('heptapod.updates.release_page_url').'/tag/v9.9.9'];
    app()->instance(OutboundHttp::class, (new FakeOutboundHttp)->respondUsing(static fn (FetchRequest $request): FetchResult => str_ends_with($request->url, '/latest')
        ? new FetchResult(status: 200, body: json_encode($latest, JSON_THROW_ON_ERROR))
        : new FetchResult(status: 500, failure: new FetchFailure(FetchFailureKind::HttpStatus, 500))));
    Log::spy();
    $this->artisan('heptapod:check-updates')->assertSuccessful();
    Log::shouldHaveReceived('warning')->with('Release list could not be read; release details are unavailable until the next check.')->once();
    $this->actingAs(User::factory()->owner()->create())->getJson(route('settings.updates.status'))->assertOk()
        ->assertJsonPath('update_status', 'available')->assertJsonPath('latest_version', 'v1.2.2')
        ->assertJsonPath('release_url', config('heptapod.updates.release_page_url').'/tag/v1.2.2')
        ->assertJsonPath('releases', null)->assertJsonPath('security', false);
});

it('treats a list that cannot explain the latest release as unavailable', function (string $listBody): void {
    config(['heptapod.version' => 'v1.2.0', 'heptapod.updates.enabled_by_server' => true]);
    app()->instance(OutboundHttp::class, (new FakeOutboundHttp)->respondUsing(static fn (FetchRequest $request): FetchResult => new FetchResult(status: 200,
        body: str_ends_with($request->url, '/latest') ? json_encode(releaseItem('v1.2.2', 'New.'), JSON_THROW_ON_ERROR) : $listBody)));
    $this->artisan('heptapod:check-updates')->assertSuccessful();
    $this->actingAs(User::factory()->owner()->create())->getJson(route('settings.updates.status'))->assertOk()
        ->assertJsonPath('update_status', 'available')->assertJsonPath('latest_version', 'v1.2.2')
        ->assertJsonPath('release_url', config('heptapod.updates.release_page_url').'/tag/v1.2.2')
        ->assertJsonPath('releases', null);
})->with([
    'an object, which decodes like an empty list' => ['{}'],
    'a list without the latest release' => [fn (): string => json_encode([releaseItem('v1.2.1', 'Older.')], JSON_THROW_ON_ERROR)],
]);

it('never offers a release tag that is not a published vX.Y.Z tag', function (string $tag, string $marker): void {
    config(['heptapod.version' => 'v1.2.2', 'heptapod.updates.enabled_by_server' => true]);
    app()->instance(OutboundHttp::class, (new FakeOutboundHttp)->respondUsing(static fn (FetchRequest $request): FetchResult => new FetchResult(
        status: 200, body: json_encode(['tag_name' => $tag, 'published_at' => '2026-10-04T18:00:00Z'], JSON_THROW_ON_ERROR),
    )));
    $this->artisan('heptapod:check-updates')->assertSuccessful();
    expect(DB::table('update_checks')->value('status'))->toBe('invalid_response');

    // A tag cached before the gate existed is dropped on read; the shell and the page still render.
    DB::table('update_checks')->where('id', 1)->update(['status' => 'reachable', 'latest_version' => $tag, 'published_at' => now(),
        'releases' => json_encode(['page_full' => false, 'items' => [['tag' => $tag, 'published_at' => '2026-10-04T18:00:00+00:00',
            'headline' => 'Cached.', 'before' => [], 'security' => []]]], JSON_THROW_ON_ERROR)]);
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner)->getJson(route('settings.updates.status'))->assertOk()
        ->assertJsonPath('update_status', 'invalid_response')->assertJsonPath('latest_version', null)
        ->assertJsonPath('release_url', null)->assertJsonPath('releases', null);
    $this->get(route('sites.index'))->assertOk()->assertInertia(fn (Assert $page): Assert => $page->where('appUpdate.latest', null));
    expect($this->get(route('settings.updates.edit'))->assertOk()->getContent())->not->toContain($marker);
})->with([
    'shell metacharacters' => ['v1.2.3+pwned;touch', 'pwned'],
    'a major Composer cannot compare' => ['v123456.0.0', 'v123456'],
]);

/** @return array<string, mixed> */
function releaseItem(string $tag, string $body): array
{
    return ['tag_name' => $tag, 'html_url' => config('heptapod.updates.release_page_url').'/tag/'.$tag, 'draft' => false,
        'prerelease' => str_contains($tag, '-'), 'published_at' => '2026-10-04T18:00:00Z', 'body' => $body];
}

/** The latest endpoint answers with the first item, the list endpoint with every item. @param list<array<string, mixed>> $items */
function fakeReleaseSource(array $items): FakeOutboundHttp
{
    return (new FakeOutboundHttp)->respondUsing(static fn (FetchRequest $request): FetchResult => new FetchResult(status: 200,
        body: json_encode(str_ends_with($request->url, '/latest') ? $items[0] : $items, JSON_THROW_ON_ERROR)));
}
