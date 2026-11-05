<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\ReviewStatesSeeder;
use Heptapod\Checks\Recording\RecordableRun;
use Heptapod\Checks\Recording\ResultRecorder;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\Http\FetchFailure;
use Heptapod\Contracts\Http\FetchFailureKind;
use Heptapod\Contracts\Http\FetchRequest;
use Heptapod\Contracts\Http\FetchResult;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Enums\ClientScope;
use Heptapod\Enums\IncidentCause;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\CheckRunRollupHourly;
use Heptapod\Models\Client;
use Heptapod\Models\Incident;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Testing\ClaimedRun;
use Heptapod\Testing\FakeOutboundHttp;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Vite;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/NavigationTrace.php';

it('renders main crawl and sitemap results through live check tabs and the event presenter', function (CheckKey $key, string $label, string $summary): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $this->seed(DemoSeeder::class);
    $owner = User::query()->where('email', DatabaseSeeder::DEMO_EMAIL)->sole();
    $this->actingAs($owner);
    $site = Site::factory()->create(['url' => 'https://addon-ui.example.test', 'friendly_name' => 'Addon integration']);
    app()->instance(OutboundHttp::class, (new FakeOutboundHttp)->respondUsing(function (FetchRequest $request): FetchResult {
        if (str_ends_with($request->url, '/sitemap.xml')) {
            return new FetchResult(200, '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://addon-ui.example.test/missing</loc></url></urlset>');
        }
        if (str_ends_with($request->url, '/missing')) {
            return new FetchResult(404, failure: new FetchFailure(FetchFailureKind::HttpStatus, status: 404));
        }

        return new FetchResult(200, '<a href="https://addon-ui.example.test/missing">Missing</a><img src="http://addon-ui.example.test/image.png">', ['Content-Type' => ['text/html']]);
    }));
    foreach ($key === CheckKey::MixedContent ? [CheckKey::BrokenLinks, $key] : [$key] as $typeKey) {
        $type = app(CheckRegistry::class)->get($typeKey);
        $check = Check::factory()->for($site)->create(['type' => $typeKey, 'failure_threshold' => 1, 'config' => $type->definition()->configSchema->validateConfig([])]);
        $context = ClaimedRun::start($check);
        $outcome = $type->execute($context);
        expect($outcome)->not->toBeNull();
        expect(app(ResultRecorder::class)->record(new RecordableRun($check->id, $context->claimToken, $context->startedAt, CarbonImmutable::now(), $outcome))->accepted)->toBeTrue();
    }
    $this->get('/sites/'.$site->id.'/checks/'.$check->id)->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->component('sites/show')->where('check.key', $key->value)->where('runs.0.result', 'failed')
        ->where('detail', fn (?Collection $detail): bool => $key === CheckKey::Sitemap
            ? $detail instanceof Collection && $detail['check_type'] === 'sitemap' && $detail['totalIssuesCount'] > 0
            : $detail instanceof Collection && $detail['check_type'] === $key->value && $detail['discovery_complete'] === true));
    $this->get('/sites/'.$site->id)->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->where('events', fn (Collection $events): bool => $events->contains(fn (array $event): bool => $event['check'] === $key->value && $event['type'] === 'incident.opened' && $event['message'] === 'Failed')));
    visit('/sites/'.$site->id.'/checks/'.$check->id)->assertSee($label)->assertSee($summary)->assertSee('Run check')->assertNoJavaScriptErrors();
    visit('/sites/'.$site->id)->assertSee($label)->assertSee($summary)->assertNoJavaScriptErrors();
})->with([
    'sitemap' => [CheckKey::Sitemap, 'Sitemap', 'Sitemap issues found.'],
    'broken links' => [CheckKey::BrokenLinks, 'Broken links', 'Broken internal links found'],
    'mixed content' => [CheckKey::MixedContent, 'Mixed content', 'Insecure resources found'],
]);

it('renders every monitoring route from real data as an owner', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $this->seed(DemoSeeder::class);
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $this->actingAs($user);
    $site = Site::query()->where('friendly_name', 'Backup')->firstOrFail();
    $check = $site->checks()->firstOrFail();
    $incident = Incident::query()->where('site_id', $site->id)->firstOrFail();
    $routes = [
        ['/dashboard', 'Today'], ['/sites', 'Sites'], ['/sites/'.$site->id, 'Backup'],
        ['/sites/'.$site->id.'/checks/'.$check->id, 'Backup'], ['/sites/create', 'Add site'],
        ['/incidents', 'Incidents'], ['/incidents/'.$incident->id, 'Backup'],
        ['/clients', 'Clients'], ['/clients/'.$site->client_id, 'Demo Client 01'],
    ];
    foreach ($routes as [$route, $heading]) {
        $page = visit($route);
        if ($route === '/dashboard') {
            $page->assertSee('Needs attention')->assertSee('Since you last looked');
        } else {
            expect($page->text('main h1:first-of-type'))->toContain($heading);
        }
        if ($route === '/sites/'.$site->id) {
            $page->assertSee('1 of 2 failing')->assertDontSee(':failed');
            expect($page->script('document.querySelectorAll("[data-slot=card]:has(a[aria-label^=\\"Open check\\"]) [data-slot=card-footer]").length'))->toBe(0);
        }
        if ($route === '/sites/'.$site->id.'/checks/'.$check->id) {
            $page->assertSee('Run check');
        }
        $page->assertNoJavaScriptErrors();
    }
});

it('labels an errored uptime incident as a check error on the site banner', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $site = Site::factory()->create();
    $check = Check::factory()->for($site)->create(['type' => CheckKey::Uptime, 'latest_run_result' => CheckResult::Errored]);
    Incident::factory()->for($check)->create(['cause' => IncidentCause::Errored]);

    $this->actingAs($owner);
    visit('/sites/'.$site->id)->assertSee('Check could not run')->assertDontSee('Down')->assertNoJavaScriptErrors();
});

it('labels the live performance series in milliseconds and explains missing history', function (bool $hasHistory): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $check = Check::factory()->create(['type' => CheckKey::Performance, 'latest_run_result' => CheckResult::Warning]);
    CheckRun::factory()->for($check)->create();
    if ($hasHistory) {
        CheckRunRollupHourly::factory()->for($check)->create([
            'bucket_start' => now('UTC')->subDay()->startOfDay()->addHours(23),
            'metric_p95' => ['total_time_in_seconds' => 0.3],
        ]);
    }
    $this->actingAs($user);

    $page = visit('/sites/'.$check->site_id.'/checks/'.$check->id);
    $page->assertSee($hasHistory ? 'p95 response time · ms' : 'History not available yet.')
        ->assertDontSee('sample_count ·')
        ->assertNoJavaScriptErrors();
    $page->assertSee('What we check')->assertDontSee('Configuration');
})->with([false, true]);

it('navigates from Today attention to the selected site and all sites', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $this->actingAs($user);
    $site = Site::factory()->create(['friendly_name' => 'First warning']);
    Check::factory()->for($site)->create(['type' => CheckKey::Performance, 'latest_run_result' => CheckResult::Warning]);
    foreach (range(2, 11) as $index) {
        $other = Site::factory()->create(['friendly_name' => "Warning $index"]);
        Check::factory()->for($other)->create(['type' => CheckKey::Performance, 'latest_run_result' => CheckResult::Warning]);
    }

    $sitePage = visit('/dashboard');
    traceTodayNavigation($sitePage, 'a[href="/sites/'.$site->id.'"]', '/sites/'.$site->id);
    $sitePage->assertPathIs('/sites/'.$site->id)->assertSee('First warning')->assertNoJavaScriptErrors();
    $sitesPage = visit('/dashboard');
    traceTodayNavigation($sitesPage, 'a[href="/sites"].underline', '/sites');
    $sitesPage->assertPathIs('/sites')->assertSee('First warning')->assertNoJavaScriptErrors();
    $mobile = visit('/dashboard')->resize(390, 844);
    expect($mobile->script('document.documentElement.scrollWidth <= innerWidth'))->toBeTrue();
    $mobile->assertSee('First warning')->assertNoJavaScriptErrors();
});

it('renders the live stream state in the topbar instead of the sidebar', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $this->actingAs($user);

    $page = visit('/dashboard');
    expect($page->script('Array.from(document.querySelectorAll("header [role=status]")).some((pill) => /Live|Reconnecting|Offline/.test(pill.textContent ?? ""))'))->toBeTrue()
        ->and($page->script('Boolean(document.querySelector("[data-slot=sidebar] [role=status]"))'))->toBeFalse();
    $page->assertNoJavaScriptErrors();
});

it('ages live check timestamps after navigation without reloading the document', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $this->actingAs($user);
    $check = Check::factory()->create();
    CheckRun::factory()->for($check)->create(['started_at' => now()->subMinutes(3), 'ended_at' => now()->subMinutes(2)]);
    $path = '/sites/'.$check->site_id.'/checks/'.$check->id;

    $page = visit($path);
    $before = $page->script('document.querySelector("[data-check-detail] tbody time").textContent');
    $page->script('(() => { const now = Date.now(); Date.now = () => now + 300000; })()');
    $page->click('a[href="/sites/'.$check->site_id.'"]')->assertPathIs('/sites/'.$check->site_id)
        ->click('a[role=tab][href="'.$path.'"]')->assertPathIs($path);
    expect($page->script('document.querySelector("[data-check-detail] tbody time").textContent'))->not->toBe($before);
    $page->assertNoJavaScriptErrors();
});

it('buffers newly discovered site rows until the reader applies them', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    Site::factory()->create(['friendly_name' => 'Original monitor']);
    $this->actingAs($owner);
    $page = visit('/sites')->assertSee('Original monitor');

    Site::factory()->create(['friendly_name' => 'New monitor']);
    $page->script('document.dispatchEvent(new Event("visibilitychange"))');
    $page->wait(1)->assertSee('1 new change ↑')->assertDontSee('New monitor');
    $page->click('button:has-text("1 new change ↑")')->assertSee('New monitor')->assertNoJavaScriptErrors();
});

it('buffers a new dashboard attention row until the reader applies it', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $original = Site::factory()->create(['friendly_name' => 'Original failure']);
    Check::factory()->for($original)->create(['latest_run_result' => CheckResult::Failed]);
    $this->actingAs($owner);
    $page = visit('/dashboard')->assertSee('Original failure');

    $added = Site::factory()->create(['friendly_name' => 'New failure']);
    Check::factory()->for($added)->create(['latest_run_result' => CheckResult::Failed]);
    $page->script('document.dispatchEvent(new Event("visibilitychange"))');
    $page->wait(1)->assertSee('1 new change ↑')->assertDontSee('New failure');
    $page->click('button:has-text("1 new change ↑")')->assertSee('New failure')->assertNoJavaScriptErrors();
});

it('buffers a new check-history row until the reader applies it', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $check = Check::factory()->create();
    CheckRun::factory()->for($check)->create(['started_at' => now()->subSeconds(61), 'ended_at' => now()->subMinute()]);
    $this->actingAs($owner);
    $page = visit('/sites/'.$check->site_id.'/checks/'.$check->id);
    expect($page->script('document.querySelectorAll("[data-check-detail] tbody tr").length'))->toBe(1);

    CheckRun::factory()->for($check)->create();
    $page->script('document.dispatchEvent(new Event("visibilitychange"))');
    $page->wait(1)->assertSee('1 new change ↑');
    expect($page->script('document.querySelectorAll("[data-check-detail] tbody tr").length'))->toBe(1);
    $page->click('button:has-text("1 new change ↑")')->assertNoJavaScriptErrors();
    expect($page->script('document.querySelectorAll("[data-check-detail] tbody tr").length'))->toBe(2);
});

it('pauses hidden check polling and requests the p95 series when visible', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $this->actingAs($user);
    $check = Check::factory()->create(['type' => CheckKey::Performance]);
    $page = visit('/sites/'.$check->site_id.'/checks/'.$check->id);
    $page->script('(() => { window.pollProps = []; const original = XMLHttpRequest.prototype.setRequestHeader; XMLHttpRequest.prototype.setRequestHeader = function(name, value) { if (name === "X-Inertia-Partial-Data") window.pollProps.push(value); return original.call(this, name, value); }; })()');
    $page->wait(26);
    expect($page->script('window.pollProps.some(value => value.includes("response_p95_hourly"))'))->toBeTrue();
    $page->script('Object.defineProperty(document, "hidden", { configurable: true, get: () => true }); document.dispatchEvent(new Event("visibilitychange")); window.pollProps = []');
    $page->wait(16);
    expect($page->script('window.pollProps.length'))->toBe(0);
    $page->assertNoJavaScriptErrors();
});

it('explains a warning site from its stored check summary in the Why column', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $site = Site::factory()->create(['friendly_name' => 'Slow shop']);
    Check::factory()->for($site)->create([
        'type' => CheckKey::Performance,
        'latest_run_result' => CheckResult::Warning,
        'summary' => 'performance.slow',
        'summary_params' => ['average' => 1800],
    ]);
    $this->actingAs($owner);

    visit('/sites')->resize(1440, 900)->assertSee('Why')
        ->assertSee('Average response time exceeds the threshold (1,800 ms).')
        ->assertNoJavaScriptErrors();
});

it('shows the failed uptime cause before an incident opens', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $site = Site::factory()->create(['friendly_name' => 'First failure']);
    Check::factory()->for($site)->create([
        'type' => CheckKey::Uptime,
        'latest_run_result' => CheckResult::Failed,
        'summary' => 'causes.http',
        'summary_params' => ['status' => 503],
    ]);
    $this->actingAs($owner);

    $page = visit('/sites')->resize(1440, 900);
    $page->assertSee('First failure')->assertSee('HTTP 503')->assertDontSee('No reason recorded')
        ->assertNoJavaScriptErrors();
});

it('uses the authorized Client Settings tab and preserves active preferences and assignments on minimal form saves', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $client = Client::factory()->create(['name' => 'Remainder client', 'locale' => 'de', 'timezone' => 'Europe/Berlin', 'accent_color' => '#3B5068']);
    $site = Site::factory()->for($client)->create(['friendly_name' => 'Client keyboard target']);
    $member = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $member->assignRole('Member');
    $member->clients()->attach($client);
    $this->actingAs($owner);

    visit('/clients/'.$client->id)->keys('tr[data-site-row]', 'Enter')->assertPathIs('/sites/'.$site->id)->assertNoJavaScriptErrors();
    $page = visit('/clients/'.$client->id)->assertPresent('a[role=tab][href="/clients/'.$client->id.'/settings"]')
        ->assertDontSee('Client settings')->click('a[role=tab][href="/clients/'.$client->id.'/settings"]')
        ->assertDontSee('Branding')->assertDontSee('Team access')->assertSee('Language and time')->assertSee('No unsaved changes');
    $page->fill('#client-name', 'Discard this')->click('[data-client-savebar] button:text-is("Discard")');
    expect($page->script('document.querySelector("#client-name").value'))->toBe('Remainder client');
    $page->fill('#client-name', 'Preserved client')->fill('#contact-email', 'contact@example.test')->click('#client-settings-save')
        ->assertSee('No unsaved changes')->assertSee('Preserved client')->assertNoJavaScriptErrors();
    expect($client->fresh()->locale)->toBe('de')->and($client->fresh()->timezone)->toBe('Europe/Berlin')
        ->and($client->fresh()->accent_color)->toBe('#3B5068')->and($member->clients()->whereKey($client->id)->exists())->toBeTrue();
    $this->actingAs($member);
    visit('/clients/'.$client->id)->assertMissing('a[role=tab][href="/clients/'.$client->id.'/settings"]')->assertNoJavaScriptErrors();
});

it('removes the Archived creation tab but keeps a bookmarked archived view truthful and clearable to active clients', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    Client::factory()->create(['name' => 'Active remainder client']);
    Client::factory()->create(['name' => 'Archived remainder client', 'archived_at' => now()]);
    $this->actingAs($owner);

    visit('/clients')->assertCount('nav[aria-label="Client state"] a:text-is("Archived")', 0)->assertNoJavaScriptErrors();
    $page = visit('/clients?filter[state]=archived')->assertSee('Archived clients')->assertSee('Archived remainder client')
        ->click('a:text-is("Show active clients")')->assertSee('Active remainder client')->assertDontSee('Archived remainder client')
        ->assertNoJavaScriptErrors();
});

it('searches sites and clients without submitting while retaining scoped URL results', function (string $route, string $field): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    if ($route === '/sites') {
        Site::factory()->create(['friendly_name' => 'Quasar Target']);
        Site::factory()->create(['friendly_name' => 'Unrelated Site']);
    } else {
        Client::factory()->create(['name' => 'Quasar Target']);
        Client::factory()->create(['name' => 'Unrelated Client']);
    }
    $this->actingAs($owner);

    $page = visit($route);
    $page->script('(() => { window.partialSearch = []; const original = XMLHttpRequest.prototype.setRequestHeader; XMLHttpRequest.prototype.setRequestHeader = function(name, value) { if (name === "X-Inertia-Partial-Data") window.partialSearch.push(value); return original.call(this, name, value); }; })()');
    $page->fill('input[aria-label="'.$field.'"]', 'Quasar')->wait(0.6)
        ->assertSee('Quasar Target')->assertDontSee('Unrelated');
    expect($page->script('new URLSearchParams(location.search).get("filter[search]")'))->toBe('Quasar');
    expect($page->script('window.partialSearch.length'))->toBeGreaterThan(0);
    $page->fill('input[aria-label="'.$field.'"]', '')->wait(0.6)->assertSee('Unrelated');
    expect($page->script('new URLSearchParams(location.search).has("filter[search]")'))->toBeFalse();
    $page->assertNotPresent('main button[type="submit"]')->assertNoJavaScriptErrors();
})->with([
    'sites' => ['/sites', 'Search sites'],
    'clients' => ['/clients', 'Search clients'],
]);

it('retains a newly selected status when a pending site search completes', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    Check::factory()->for(Site::factory()->create(['friendly_name' => 'Quasar warning']))->create(['latest_run_result' => CheckResult::Warning]);
    Check::factory()->for(Site::factory()->create(['friendly_name' => 'Quasar healthy']))->create(['latest_run_result' => CheckResult::Succeeded]);
    $this->actingAs($owner);
    $page = visit('/sites');
    // Hold only the search delay so the filter response always arrives before it fires.
    $page->script('(() => {
        const schedule = window.setTimeout.bind(window), cancel = window.clearTimeout.bind(window);
        let id = 0;
        window.pendingSearch = new Map();
        window.setTimeout = (callback, delay, ...args) => {
            if (delay !== 250 || !new Error().stack?.includes("/sites/index")) return schedule(callback, delay, ...args);
            const handle = --id;
            window.pendingSearch.set(handle, () => callback(...args));
            return handle;
        };
        window.clearTimeout = handle => {
            if (!window.pendingSearch.delete(handle)) cancel(handle);
        };
        window.flushSearch = () => {
            const callbacks = [...window.pendingSearch.values()];
            window.pendingSearch.clear();
            callbacks.forEach(callback => callback());
        };
    })()');
    $page->fill('input[aria-label="Search sites"]', 'Quasar');
    expect($page->script('window.pendingSearch.size'))->toBe(1);
    $page->click('button[aria-label="Status"]')->click('[role="option"]:has-text("Warning")')->assertDontSee('Quasar healthy');
    expect($page->script('new URLSearchParams(location.search).get("filter[state]")'))->toBe('warning');
    $page->script('window.flushSearch()');
    $page->wait(0.6)->assertSee('Quasar warning')->assertDontSee('Quasar healthy')->assertNoJavaScriptErrors();
    expect($page->script('new URLSearchParams(location.search).get("filter[search]")'))->toBe('Quasar');
    expect($page->script('new URLSearchParams(location.search).get("filter[state]")'))->toBe('warning');
});

it('shows client status counts and triage incident state and duration', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $client = Client::factory()->create(['name' => 'Mixed client']);
    $site = Site::factory()->for($client)->create(['friendly_name' => 'Mixed site']);
    $check = Check::factory()->for($site)->create(['latest_run_result' => CheckResult::Failed]);
    Incident::factory()->for($check)->create(['first_failure_at' => now()->subMinutes(5), 'opened_at' => now()->subMinutes(5)]);
    Check::factory()->for(Site::factory()->for($client)->create())->create(['type' => CheckKey::Performance, 'latest_run_result' => CheckResult::Warning]);
    Check::factory()->for(Site::factory()->for($client)->create())->create(['latest_run_result' => CheckResult::Succeeded]);
    Check::factory()->for(Site::factory()->for($client)->create())->create(['latest_run_result' => CheckResult::Errored]);
    Check::factory()->for(Site::factory()->for($client)->create())->create(['type' => CheckKey::CertificateHealth, 'latest_run_result' => CheckResult::Failed]);
    $maintenance = Site::factory()->for($client)->create();
    Check::factory()->for($maintenance)->create(['latest_run_result' => CheckResult::Succeeded]);
    MaintenancePeriod::factory()->create(['site_id' => $maintenance->id]);
    Check::factory()->for(Site::factory()->for($client)->create(['paused_at' => now()]))->create();
    Site::factory()->for($client)->create();
    Check::factory()->for(Site::factory()->for($client)->create())->create();
    Incident::factory()->for(Check::factory()->create())->create();
    $resolvedCheck = Check::factory()->create();
    Incident::factory()->for($resolvedCheck)->create(['first_failure_at' => now()->subMinutes(12), 'opened_at' => now()->subMinutes(12), 'resolved_at' => now()->subMinutes(10)]);
    $this->actingAs($owner);

    $clients = visit('/clients')->resize(1440, 900);
    $clients->assertSee('Mixed client');
    expect($clients->script('(() => { const row = [...document.querySelectorAll("tbody tr")].find(row => row.textContent.includes("Mixed client")); return [...row.querySelectorAll("[role=img]")].map(icon => icon.getAttribute("aria-label")); })()'))->toBe(['Down: 1', 'Failed: 1', 'Check error: 1', 'Warning: 1', 'Pending: 1', 'Maintenance: 1', 'Paused: 1', 'Disabled: 1']);
    $labels = ['Down: 1', 'Failed: 1', 'Check error: 1', 'Warning: 1', 'Pending: 1', 'Maintenance: 1', 'Paused: 1', 'Disabled: 1', '1 OK'];
    expect($clients->script('[...document.querySelectorAll("tbody tr")].find(row => row.textContent.includes("Mixed client")).textContent'))->toContain(...$labels);
    expect($clients->script('[...document.querySelectorAll("main [role=img] *")].every(node => getComputedStyle(node).animationName === "none")'))->toBeTrue();
    $clients->resize(390, 844);
    expect($clients->script('[...document.querySelectorAll("main ul li")].find(row => row.textContent.includes("Mixed client")).textContent'))->toContain(...$labels);
    $clients->assertNoJavaScriptErrors();

    $incidents = visit('/incidents')->resize(1440, 900);
    $incidents->assertSee('State')->assertSee('Cause')->assertSee('Duration')
        ->assertSee('2m')->assertSee('All checks')->assertSee('Last 30 days')
        ->assertNoJavaScriptErrors();
});

it('uses the German open incident state rather than the open action', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $owner = User::factory()->create(['locale' => 'de']);
    $owner->assignRole('Owner');
    Incident::factory()->for(Check::factory()->create())->create();
    $this->actingAs($owner);
    $page = visit('/incidents')->resize(1024, 900);
    expect($page->script('document.querySelector("main a[href^=\\"/incidents/\\"][aria-label]").textContent'))->toContain('Offen')->not->toContain('Öffnen');
    $page->assertNoJavaScriptErrors();
});

it('keeps all installed check indicators on one row at 1280 pixels', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $site = Site::factory()->create(['friendly_name' => 'All checks']);
    foreach (CheckKey::cases() as $key) {
        Check::factory()->for($site)->create(['type' => $key]);
    }
    $this->actingAs($owner);

    $page = visit('/sites')->resize(1280, 900);
    $geometry = $page->script('(() => { const row = [...document.querySelectorAll("tbody [data-site-row]")].find(row => row.getAttribute("aria-label").includes("All checks")); const icons = [...row.querySelectorAll("[role=img]")]; const cell = icons[0].closest("td"); return [icons.length, new Set(icons.map(icon => icon.getBoundingClientRect().top)).size, icons.at(-1).getBoundingClientRect().right <= cell.getBoundingClientRect().right + 1, document.documentElement.scrollWidth <= innerWidth]; })()');
    expect($geometry)->toBe([11, 1, true, true]);
    $page->assertNoJavaScriptErrors();
});

it('renders only visible client data for a restricted member on all monitoring routes', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $this->seed(DemoSeeder::class);
    $client = Client::query()->where('name', 'Demo Client 01')->firstOrFail();
    $member = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $member->assignRole('Member');
    $member->clients()->attach($client);
    $this->actingAs($member);
    $site = Site::query()->where('friendly_name', 'Backup')->firstOrFail();
    $check = $site->checks()->firstOrFail();
    $incident = Incident::query()->where('site_id', $site->id)->firstOrFail();
    foreach ([
        ['/dashboard', 'Today'], ['/sites', 'Sites'], ['/sites/'.$site->id, 'Backup'],
        ['/sites/'.$site->id.'/checks/'.$check->id, 'Backup'], ['/sites/create', 'Add site'],
        ['/incidents', 'Incidents'], ['/incidents/'.$incident->id, 'Backup'],
        ['/clients', 'Clients'], ['/clients/'.$client->id, 'Demo Client 01'],
    ] as [$route, $heading]) {
        $page = visit($route);
        if ($route === '/dashboard') {
            $page->assertSee('Needs attention')->assertSee('Since you last looked');
        } else {
            expect($page->text('main h1:first-of-type'))->toContain($heading);
        }
        $page->assertDontSee('Demo Client 02')->assertNoJavaScriptErrors();
        if ($route === '/sites/'.$site->id.'/checks/'.$check->id) {
            $page->assertDontSee('Run check');
        }
    }
});

it('omits absent readings and summaries instead of rendering bare dashes', function (string $route): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $site = Site::factory()->create();
    Check::factory()->for($site)->create(['latest_run_result' => CheckResult::Failed, 'summary' => null]);
    Check::factory()->for($site)->create(['type' => CheckKey::Cron, 'summary' => null]);
    $this->actingAs($owner);
    $page = visit($route === 'overview' ? '/sites/'.$site->id : '/dashboard');
    expect($page->script('document.querySelector("main").textContent'))->not->toContain('—', 'Last 15 minutes', '24-hour uptime');
    $page->assertNoJavaScriptErrors();
})->with(['overview', 'dashboard']);

it('shows only enabled check navigation and cards while retaining overview history and disabled check routes', function (string $role): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $user = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $user->assignRole($role);
    $site = Site::factory()->create(['friendly_name' => 'Structure monitor']);
    $user->clients()->attach($site->client_id);
    $enabled = Check::factory()->for($site)->create(['type' => CheckKey::Uptime]);
    $disabled = Check::factory()->for($site)->create(['type' => CheckKey::Dns, 'enabled' => false]);
    CheckRun::factory()->for($disabled)->create();
    $this->actingAs($user);
    $enabledPath = '/sites/'.$site->id.'/checks/'.$enabled->id;
    $disabledPath = '/sites/'.$site->id.'/checks/'.$disabled->id;

    $page = visit('/sites/'.$site->id)->assertPresent('a[role=tab][href="'.$enabledPath.'"]')
        ->assertCount('main a[href="'.$disabledPath.'"]', 0)->assertSee('90-day history')->assertDontSee('Response time')
        ->assertSee('Recent events')->assertNoJavaScriptErrors();
    $page = visit($disabledPath)->assertMissing('a[role=tab][href="'.$disabledPath.'"]')->assertSee('History');
    if ($role === 'Owner') {
        $page->click('button:text-is("Edit")')->assertSee('Check settings');
    } else {
        $page->assertDontSee('Edit')->assertDontSee('Run check');
    }
    $page->assertNoJavaScriptErrors();
})->with(['Owner', 'Member']);

it('uses stored check switches and enabled tabs instead of settings Configure links without touching notifications', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $site = Site::factory()->create(['friendly_name' => 'Settings structure']);
    Check::factory()->for($site)->create(['type' => CheckKey::Uptime]);
    $dns = Check::factory()->for($site)->create(['type' => CheckKey::Dns, 'enabled' => false]);
    $this->actingAs($owner);
    $path = '/sites/'.$site->id.'/checks/'.$dns->id;

    $page = visit('/sites/'.$site->id.'/settings')->assertCount('a:text-is("Configure")', 0)->assertDontSee('Danger zone')
        ->assertDontSee('Notification schedule')->assertSee('Delete')->assertMissing('nav a[href="'.$path.'"]');
    $page->click('#settings-check-'.$dns->id)->click('#site-settings-save')->assertPresent('nav a[href="'.$path.'"]');
    expect($dns->fresh()->enabled)->toBeTrue();
    $page->click('nav a[href="'.$path.'"]')->assertPathIs($path)->click('button:text-is("Edit")')
        ->assertSee('Check settings')->assertNoJavaScriptErrors();
    $page->click('[data-slot=sheet-content] button:text-is("Cancel")');
    $page = visit('/sites/'.$site->id.'/settings')->click('#settings-check-'.$dns->id)->click('#site-settings-save')
        ->assertMissing('nav a[href="'.$path.'"]')->assertDontSee('Notification schedule')->assertNoJavaScriptErrors();
    expect($dns->fresh()->enabled)->toBeFalse();
});

it('uses one bottom site savebar with discard and exactly one site PATCH', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $site = Site::factory()->create(['friendly_name' => 'Savebar monitor']);
    Check::factory()->for($site)->create(['type' => CheckKey::Uptime]);
    $this->actingAs($owner);

    $page = visit('/sites/'.$site->id.'/settings')->assertPresent('[data-site-savebar]')->assertSee('No unsaved changes')
        ->assertDontSee('Save changes')->assertDontSee('Notification schedule');
    $page->fill('#site-name', 'Discard this')->assertSee('Unsaved changes')->click('[data-site-savebar] button:text-is("Discard")');
    expect($page->script('document.querySelector("#site-name").value'))->toBe('Savebar monitor');
    $page->script('(() => { window.siteWrites = []; const original = XMLHttpRequest.prototype.open; XMLHttpRequest.prototype.open = function(method, url, ...args) { if (!["GET", "HEAD"].includes(method.toUpperCase())) window.siteWrites.push([method.toUpperCase(), new URL(url, location.href).pathname]); return original.call(this, method, url, ...args); }; })()');
    $page->fill('#site-name', 'Saved once')->click('#site-settings-save')->assertSee('No unsaved changes')->assertSee('Saved once');
    expect($site->fresh()->friendly_name)->toBe('Saved once');
    expect($page->script('window.siteWrites'))->toBe([['PATCH', '/sites/'.$site->id]]);
    $page->assertNoJavaScriptErrors();
});

it('omits uptime history and response charts from a cron-only overview', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $this->seed(ReviewStatesSeeder::class);
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $this->actingAs($owner);
    $site = Site::query()->where('friendly_name', 'Review recovered-tasks')->sole();
    $page = visit('/sites/'.$site->id);
    expect($page->script('document.querySelector("main").textContent'))->not->toContain('90-day history', '24-hour uptime', 'Response time');
    $page->assertSee('Scheduled tasks')->assertSee('Recent events')->assertSee('2 tasks · all on time')->assertSee('Next run in')->assertNoJavaScriptErrors();
});

it('switches site uptime history between 7, 30, and 90 days without crashing', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $check = Check::factory()->create(['type' => CheckKey::Uptime]);
    $this->actingAs($owner);

    $page = visit('/sites/'.$check->site_id)->resize(1440, 900);
    foreach (['7 days', '30 days', '90 days'] as $range) {
        $page->click('button[aria-pressed]:has-text("'.$range.'")')->assertSee('Uptime for this period')->assertNoJavaScriptErrors();
    }
});

it('loads deletion result counts only when the site dialog opens', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $site = Site::factory()->create(['friendly_name' => 'Preview delete']);
    $check = Check::factory()->for($site)->create();
    CheckRun::factory()->for($check)->create();
    $this->actingAs($owner);

    $page = visit('/sites/'.$site->id.'/settings')->resize(1440, 900);
    $page->click('button:has-text("Delete site")')
        ->assertSee('Deleting removes 1 check, 0 incidents and 1 stored result.')
        ->assertNoJavaScriptErrors();
    $this->assertDatabaseHas('sites', ['id' => $site->id]);
});

it('confirms fast-only site runs and shows individual queued and skipped results', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $site = Site::factory()->create(['friendly_name' => 'Run preview', 'url' => 'https://fixtures.test/status/200']);
    Check::factory()->for($site)->create(['type' => CheckKey::Uptime]);
    Check::factory()->for($site)->create(['type' => CheckKey::Lighthouse]);
    $this->actingAs($owner);

    $page = visit('/sites/'.$site->id)->resize(1440, 900);
    $page->click('button:has-text("Run all checks…")')->assertSee('Run all checks on Run preview?')
        ->assertSee('Browser queue')->assertSee('At most');
    $page->click('label:has-text("Only fast checks")')->assertSee('Skipped: fast checks only');
    $page->click('button:has-text("Run 1 checks")')->assertSee('Request result: 1 queued · 0 unavailable')
        ->assertSee('Skipped: fast checks only')->assertNoJavaScriptErrors();
});

it('shares pause and run-all controls across site overview, check and settings', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $site = Site::factory()->create(['friendly_name' => 'Header preview']);
    $check = Check::factory()->for($site)->create(['type' => CheckKey::Uptime]);
    $this->actingAs($owner);

    $page = visit('/sites/'.$site->id)->resize(1440, 900);
    $page->click('button[aria-label="More actions"]')->click('[role="menuitem"]:has-text("Pause site")')
        ->assertSee('Pause Header preview?');
    $page->click('button:has-text("Cancel")');
    $page->click('[role="tab"][href="/sites/'.$site->id.'/checks/'.$check->id.'"]')
        ->assertPathIs('/sites/'.$site->id.'/checks/'.$check->id)->assertSee('Run all checks…');
    $page->click('a[href="/sites/'.$site->id.'/settings"]')
        ->assertPathIs('/sites/'.$site->id.'/settings')->assertSee('Run all checks…');
    $page->click('button[aria-label="More actions"]')->click('[role="menuitem"]:has-text("Pause site")')
        ->assertSee('Pause Header preview?')->click('input[value="hour"]');
    $page->click('[role="dialog"] button:has-text("Pause site")')->assertSee('Resume site')->assertNoJavaScriptErrors();
    expect($site->refresh()->paused_at)->not->toBeNull()
        ->and($site->resume_at)->not->toBeNull();
});

it('keeps response charts on the check page with available 24-hour measurements and explains empty ranges', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $check = Check::factory()->create(['type' => CheckKey::Uptime]);
    CheckRun::factory()->for($check)->create(['started_at' => now()->subHours(2)->subSecond(), 'ended_at' => now()->subHours(2), 'metrics' => ['total_time_in_seconds' => 0.37]]);
    $this->actingAs($owner);
    $page = visit('/sites/'.$check->site_id.'/checks/'.$check->id)->resize(1440, 900);
    $page->assertSee('Minute average')->assertDontSee('No measurements in this range yet');
    expect($page->script('document.querySelector("[data-slot=toggle-group-item][data-state=on]").textContent'))->toBe('24 hours');
    expect($page->script('document.querySelectorAll(".recharts-area-dot").length'))->toBeGreaterThan(0);
    $page->click('button[data-slot="toggle-group-item"]:has-text("7 days")')->assertSee('No measurements in this range yet')->assertNoJavaScriptErrors();
});
