<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Heptapod\Addons\Cron\CronDefinitions;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\RunPhase;
use Heptapod\Contracts\RunReference;
use Heptapod\Contracts\RunResult;
use Heptapod\Enums\ClientScope;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\CheckRunAttempt;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;

it('shows a registered addon summary and real run history without addon-specific UI', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $check = Check::factory()->create(['type' => CheckKey::Sitemap, 'latest_run_result' => CheckResult::Succeeded,
        'summary' => 'sitemap.succeeded']);
    $run = CheckRun::factory()->for($check)->create(['summary' => 'sitemap.succeeded', 'started_at' => now()->subMinutes(2), 'ended_at' => now()->subMinute()]);
    $check->update(['latest_run_id' => $run->id, 'latest_run_ended_at' => $run->ended_at]);
    $this->actingAs($user);

    $page = visit('/sites/'.$check->site_id.'/checks/'.$check->id);
    $page->assertSee('Sitemap')->assertSee('What we check')->assertSee('Last result')->assertSee('History')
        ->assertSee('Scheduled')->assertSee('Sitemap path')->assertDontSee('Using defaults')
        ->assertSee('Maintenance')->assertSee('Run check')->assertNoJavaScriptErrors();
    expect($page->script('document.querySelectorAll("[data-check-detail=sitemap] tbody tr").length'))->toBe(1);
});

it('shows the latest uptime attempt duration and measured response history', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $check = Check::factory()->create(['type' => CheckKey::Uptime, 'latest_run_result' => CheckResult::Succeeded,
        'summary' => 'uptime.succeeded']);
    $at = CarbonImmutable::now('UTC')->subMinute();
    $run = CheckRun::factory()->for($check)->create(['started_at' => $at->subMilliseconds(390), 'ended_at' => $at,
        'summary' => 'uptime.succeeded', 'evidence' => ['status_code' => 200], 'metrics' => ['total_time_in_seconds' => 0.24, 'dns_time_in_seconds' => 0.01]]);
    $check->update(['latest_run_id' => $run->id, 'latest_run_ended_at' => $run->ended_at]);
    $this->actingAs($user);

    visit('/sites/'.$check->site_id.'/checks/'.$check->id)
        ->assertSee('Latest attempt')->assertSee('Duration')->assertSee('240 ms')->assertSee('HTTP 200')->assertSee('History')
        ->assertSee('GET')->assertSee('2*')->assertSee('Failed checks before Down')->assertDontSee('Using defaults')->assertSee('Response-time breakdown')
        ->assertNoJavaScriptErrors();
});

it('shows accepted crawl findings and incomplete crawl evidence', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $check = Check::factory()->create(['type' => CheckKey::BrokenLinks, 'latest_run_result' => CheckResult::Warning,
        'summary' => 'crawl.warning']);
    $at = CarbonImmutable::now('UTC')->subMinute();
    $run = CheckRun::factory()->for($check)->create(['started_at' => $at->subMinute(), 'ended_at' => $at]);
    $check->update(['latest_run_id' => $run->id, 'latest_run_ended_at' => $run->ended_at]);
    $context = new CheckContext($check->site_id, $check->id, $run->id, (string) Str::uuid(), $check->site->url, $at, $at->addMinute(), [], [], null);
    $store = app(CheckRegistry::class)->get(CheckKey::BrokenLinks)->persistence();
    $store->stage($context, 'artifact', [[
        'site_url' => $check->site->url, 'target_fingerprint' => str_repeat('a', 64),
        'config_fingerprint' => hash('sha256', json_encode($check->executionConfig(), JSON_THROW_ON_ERROR)),
        'collected_at' => $at->toIso8601String(),
        'settings' => ['max_pages' => 1204], 'truncated' => true, 'page_cap_reached' => true, 'time_limit_reached' => false,
        'observed_urls' => [], 'observed_pages' => [], 'discovered_urls' => [], 'discovery_complete' => false, 'evaluated_finding_ids' => [],
        'urls' => [[
            'crawled_url' => $check->site->url.'/missing', 'relative_crawled_url' => '/missing', 'status_code' => 404,
            'found_on_url' => $check->site->url, 'relative_found_on_url' => '/', 'link_text' => null,
            'type' => 'broken', 'error_message' => null, 'internal' => true,
        ]], 'mixed_content' => [],
    ]]);
    $store->finalize(new RunReference($run->id, $at), $context->claimToken);
    $latestAt = $at->addMinutes(2);
    $latest = CheckRun::factory()->for($check)->create(['started_at' => $latestAt->subMinute(), 'ended_at' => $latestAt,
        'result' => RunResult::Errored, 'evidence' => ['finding_ids' => [], 'truncated' => true, 'unchecked_count' => 0,
            'discovery_complete' => false, 'crawl_truncated' => true, 'page_cap' => 1204,
            'observed_pages' => 30, 'observed_urls' => 40, 'discovered_urls' => 50,
            'page_cap_reached' => false, 'resource_cap_reached' => false, 'time_limit_reached' => false,
            'comparison_limit_reached' => true, 'notes' => []]]);
    $check->update(['latest_run_id' => $latest->id, 'latest_run_ended_at' => $latestAt,
        'latest_run_result' => CheckResult::Errored]);
    $this->actingAs($user);

    visit('/sites/'.$check->site_id.'/checks/'.$check->id)
        ->assertSee('Crawl findings')->assertSee('Latest run incomplete')->assertSee('Comparison limit reached')
        ->assertSee('Findings from')->assertSee('Accepted crawl incomplete')->assertSee('Page limit reached')
        ->assertSee('30 pages checked · 50 URLs discovered · limit reached')
        ->assertSee('/missing')->assertSee('404')->assertNoJavaScriptErrors();
});

it('shows editable performance settings only once', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $check = Check::factory()->create(['type' => CheckKey::Performance]);
    $this->actingAs($user);

    visit('/sites/'.$check->site_id.'/checks/'.$check->id)
        ->assertSee('What we check')->assertDontSee('Configuration')->assertNoJavaScriptErrors();
});

it('keeps the last successful Lighthouse scores after a failed attempt', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $check = Check::factory()->create(['type' => CheckKey::Lighthouse, 'latest_run_result' => CheckResult::Errored,
        'summary' => 'lighthouse.errored']);
    $at = CarbonImmutable::now('UTC')->subMinutes(2);
    $metrics = ['performance_score' => 92, 'accessibility_score' => 98, 'best_practices_score' => 100,
        'seo_score' => 91, 'progressive_web_app_score' => null, 'first_contentful_paint_in_ms' => null,
        'speed_index_in_ms' => null, 'largest_contentful_paint_in_ms' => null, 'time_to_interactive_in_ms' => null,
        'total_blocking_time_in_ms' => null, 'cumulative_layout_shift' => null];
    CheckRun::factory()->for($check)->create(['started_at' => $at->subMinute(), 'ended_at' => $at,
        'result' => RunResult::Succeeded, 'metrics' => $metrics, 'evidence' => ['device_emulation' => 'mobile']]);
    $failed = CheckRun::factory()->for($check)->create(['started_at' => $at->addMinute()->subSeconds(30),
        'ended_at' => $at->addMinute(), 'result' => RunResult::Errored]);
    $check->update(['latest_run_id' => $failed->id, 'latest_run_ended_at' => $failed->ended_at]);
    $this->actingAs($user);

    visit('/sites/'.$check->site_id.'/checks/'.$check->id)
        ->assertSee('Scores from last successful run')->assertSee('92')->assertSee('98')->assertSee('Mobile')
        ->assertSee('Performance, 90 days')->assertSee('What we check')->click('button:has-text("Edit")')
        ->fill('input[name="notification_settings.performance"]', '70')
        ->click('button[type="submit"]')->assertNoJavaScriptErrors();
    expect($check->refresh()->executionConfig()['notification_settings']['performance'])->toBe(70);
    $page = visit('/sites/'.$check->site_id.'/checks/'.$check->id);
    $page->click('button:has-text("Edit")')->fill('input[name="notification_settings.performance"]', '')
        ->click('button[type="submit"]')->assertNoJavaScriptErrors();
    expect($check->refresh()->executionConfig()['notification_settings']['performance'])->toBeNull();
});

it('shows scoped task ping URLs and rotates an owner URL from the check page', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $member = User::factory()->create();
    $member->assignRole('Member');
    $check = Check::factory()->create(['type' => CheckKey::Cron]);
    $task = app(CronDefinitions::class)->create($check->id, ['name' => 'Nightly backup', 'type' => 'simple',
        'frequency_in_minutes' => 60, 'grace_time_in_minutes' => 5]);
    $url = '/sites/'.$check->site_id.'/checks/'.$check->id;
    $oldUuid = $task->uuid;

    $this->actingAs($member);
    visit($url)->assertSee('Nightly backup')->assertSee('********')
        ->assertDontSee($task->uuid)->assertDontSee('Rotate ping URL')->assertNoJavaScriptErrors();

    $this->actingAs($owner);
    $page = visit($url)->assertSee($task->uuid)->assertSee('Rotate ping URL');
    $page->script('window.confirm = () => true');
    $page->click('button:has-text("Rotate ping URL")')->assertNoJavaScriptErrors();
    expect($task->refresh()->uuid)->not->toBe($oldUuid);
    $page->assertDontSee($oldUuid)->assertSee($task->uuid);
});

it('keeps accepted health components visibly stale after a failed attempt', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $check = Check::factory()->create(['type' => CheckKey::ApplicationHealth,
        'config_secrets' => ['secret' => 'private-health-secret']]);
    $at = CarbonImmutable::now('UTC')->subMinutes(5);
    $good = CheckRun::factory()->for($check)->create(['started_at' => $at->subMinute(), 'ended_at' => $at,
        'result' => RunResult::Succeeded, 'metrics' => ['response_time_in_seconds' => 0.08]]);
    DB::table('application_health_snapshots')->insert(['check_id' => $check->id, 'run_id' => $good->id,
        'claim_token' => (string) Str::uuid(), 'run_ended_at' => $good->ended_at, 'components' => '[]', 'created_at' => $good->ended_at]);
    DB::table('application_health_components')->insert(['check_id' => $check->id,
        'source_key' => str_repeat('a', 64), 'active' => true, 'snapshot' => json_encode([
            'name' => 'database', 'label' => 'DB private-health-secret', 'status' => 'ok',
            'shortSummary' => 'Connected', 'latest_result' => 'succeeded',
        ], JSON_THROW_ON_ERROR), 'run_id' => $good->id, 'run_ended_at' => $good->ended_at, 'detected_at' => $good->ended_at]);
    $failed = CheckRun::factory()->for($check)->create(['started_at' => $at->addMinutes(4)->subSeconds(20),
        'ended_at' => $at->addMinutes(4), 'result' => RunResult::Errored,
        'evidence' => ['reason' => 'http_status', 'http_status' => 401]]);
    $check->update(['latest_run_id' => $failed->id, 'latest_run_ended_at' => $failed->ended_at]);
    $this->actingAs($user);

    visit('/sites/'.$check->site_id.'/checks/'.$check->id)
        ->assertSee('Component results are stale after a failed attempt')->assertSee('DB [redacted]')
        ->assertSee('Health attempts')->assertSee('HTTP 401')->assertDontSee('private-health-secret')
        ->assertNoJavaScriptErrors();
});

it('compares accepted DNS records and accepts a changed snapshot with an actor', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $member = User::factory()->create();
    $member->assignRole('Member');
    $check = Check::factory()->create(['type' => CheckKey::Dns]);
    $at = CarbonImmutable::now('UTC')->subMinutes(2);
    $host = parse_url($check->site->url, PHP_URL_HOST);
    $store = app(CheckRegistry::class)->get(CheckKey::Dns)->persistence();
    $record = function (string $ip, CarbonImmutable $ended) use ($check, $host, $store): void {
        $run = CheckRun::factory()->for($check)->create(['started_at' => $ended->subSeconds(10), 'ended_at' => $ended]);
        $context = new CheckContext($check->site_id, $check->id, $run->id, (string) Str::uuid(), $check->site->url,
            $ended->subSeconds(10), $ended->addMinute(), [], [], null);
        $store->stage($context, 'snapshot', [[
            'dns_records' => [['host' => $host, 'class' => 'IN', 'type' => 'A', 'ttl' => 300, 'ip' => $ip]],
            'raw_dns_records' => [], 'normalized_records' => ["$host|A" => [['host' => $host, 'class' => 'IN', 'type' => 'A', 'ip' => $ip]]],
            'issues' => [], 'authoritative_nameservers' => [], 'expected' => [], 'baseline_generation' => 0,
        ]]);
        $store->finalize(new RunReference($run->id, $ended), $context->claimToken);
        $check->update(['latest_run_id' => $run->id, 'latest_run_ended_at' => $ended]);
    };
    $record('192.0.2.1', $at->addMinute());
    $record('192.0.2.2', $at->addMinutes(2));
    $url = '/sites/'.$check->site_id.'/checks/'.$check->id;
    $this->actingAs($member);
    visit($url)->assertSee('DNS comparison')->assertSee('192.0.2.1')->assertSee('192.0.2.2')
        ->assertDontSee('Accept as normal')->assertNoJavaScriptErrors();

    $this->actingAs($owner);
    $page = visit($url)->assertSee('Accept as normal')->assertSee('DNS change history');
    $record('192.0.2.3', $at->addMinutes(3));
    $page->script('window.confirm = () => true');
    $page->click('button:has-text("Accept as normal")')->assertSee('192.0.2.3')
        ->assertSee('There are newer DNS results — please review again.');
    $page->click('button:has-text("Accept as normal")')->assertSee('Accepted; waiting for the new DNS check')
        ->assertSee('Accepted by '.$owner->name)->assertNoJavaScriptErrors();
    expect(DB::table('dns_baselines')->where('check_id', $check->id)->value('accepted_by'))->toBe($owner->id);
});

it('keeps unsaved health settings gated after a failed probe', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $schema = app(CheckRegistry::class)->get(CheckKey::ApplicationHealth)->definition()->configSchema;
    $check = Check::factory()->create(['type' => CheckKey::ApplicationHealth, 'config' => $schema->validateConfig([
        'result_url' => 'http://127.0.0.1/health', 'secret' => 'stored-health-secret',
    ])]);
    foreach ([CheckKey::Uptime, CheckKey::CertificateHealth, CheckKey::Dns, CheckKey::BrokenLinks, CheckKey::MixedContent, CheckKey::Sitemap] as $type) {
        Check::factory()->for($check->site)->create(['type' => $type]);
    }
    $this->actingAs($owner);

    $page = visit('/sites/'.$check->site_id.'/checks/'.$check->id)->resize(390, 844);
    $page->script('window.dispatchEvent(new Event("resize"))');
    expect($page->script('(() => { const tab = document.querySelector("[role=tab][data-state=active]"); const rect = tab.getBoundingClientRect(); return rect.left >= 0 && rect.right <= innerWidth; })()'))->toBeTrue();
    $page->click('[data-check-detail=application_health] button:has-text("Edit")')
        ->fill('input[name="result_url"]', 'http://127.0.0.1/new-health')
        ->assertSee('Edit health check')->assertCount('[data-slot=sheet-content] [role=tablist]', 0)
        ->assertSee('Not saved yet. Test successfully before saving.')
        ->assertDontSee('stored-health-secret');
    expect($page->script('document.querySelector("button[type=submit]")?.disabled'))->toBeTrue();
    $page->click('button:has-text("Test health endpoint")')
        ->assertSee('The health endpoint could not be reached.')
        ->assertNoJavaScriptErrors();
    expect($page->script('document.querySelector("button[type=submit]")?.disabled'))->toBeTrue();
    expect($check->refresh()->executionConfig()['result_url'])->toBe('http://127.0.0.1/health');
});

it('keeps running facts in RunBar without a Schedule card and only offers authorized cancellation', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $member = User::factory()->create();
    $member->assignRole('Member');
    $check = Check::factory()->create(['type' => CheckKey::BrokenLinks]);
    $token = (string) Str::uuid();
    $check->update(['claim_token' => $token, 'next_run_at' => now()->addMinutes(10)]);
    CheckRunAttempt::query()->create(['check_id' => $check->id, 'claim_token' => $token,
        'phase' => RunPhase::Running, 'queue' => 'checks-crawl', 'requested_at' => now()->subMinutes(2),
        'due_at' => now(), 'started_at' => now()->subMinute(), 'progress_done' => 3,
        'progress_total' => 10, 'phase_label' => 'crawl']);
    $url = '/sites/'.$check->site_id.'/checks/'.$check->id;

    $this->actingAs($member);
    visit($url)->assertDontSee('Schedule')->assertSee('Running')->assertDontSee('Cancel')->assertNoJavaScriptErrors();
    $this->actingAs($owner);
    visit($url)->assertDontSee('Schedule')->assertSee('Running')->assertSee('Cancel')
        ->assertDontSee('Every run is listed in Runs')->assertNoJavaScriptErrors();
});

it('shows the seeded overdue check as Late by elapsed time', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $this->seed(DemoSeeder::class);
    $owner = User::query()->where('email', DatabaseSeeder::DEMO_EMAIL)->sole();
    $this->actingAs($owner);
    $site = Site::query()->where('url', 'https://demo-05.example')->sole();
    $check = Check::query()->where('site_id', $site->id)->where('type', CheckKey::Lighthouse)->sole();

    visit('/sites/'.$site->id.'/checks/'.$check->id)
        ->assertSee('Late by')->assertDontSee('Due at')->assertNoJavaScriptErrors();
});

it('shows permission-truthful API-only Tasks copy and setup links on the check page and wizard', function (string $role, bool $canWrite, string $locale): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $user = User::factory()->create(['client_scope' => ClientScope::Restricted, 'locale' => $locale]);
    $user->assignRole($role);
    $site = Site::factory()->create();
    $user->clients()->attach($site->client_id);
    $check = Check::factory()->for($site)->create(['type' => CheckKey::Cron]);
    $this->actingAs($user);
    $message = $locale === 'de'
        ? ($canWrite ? 'Geplante Aufgaben werden über die API hinzugefügt. Erstelle einen Token unter Einstellungen → API-Tokens.' : 'Geplante Aufgaben werden über die API hinzugefügt. Ein Owner oder Admin kann geplante Aufgaben hinzufügen oder ändern.')
        : ($canWrite ? 'Scheduled tasks are added through the API. Create a token in Settings → API tokens.' : 'Scheduled tasks are added through the API. An Owner or Admin can add or change scheduled tasks.');
    $next = $locale === 'de' ? 'Weiter' : 'Next';

    $page = visit('/sites/'.$site->id.'/checks/'.$check->id)->assertSee($message)->assertNoJavaScriptErrors();
    if ($canWrite) {
        $page->assertPresent('main a[href="/settings/api-tokens"]')->click('main a[href="/settings/api-tokens"]')->assertPathIs('/settings/api-tokens');
    } else {
        $page->assertMissing('main a[href="/settings/api-tokens"]');
    }
    $page = visit('/sites/create?client_id='.$site->client_id)->fill('#site-url', 'https://fixtures.test/status/200')
        ->click('button:text-is("'.$next.'")')->click('button:text-is("'.$next.'")')->assertSee($message)->assertNoJavaScriptErrors();
    if ($canWrite) {
        $page->assertPresent('main a[href="/settings/api-tokens"]');
    } else {
        $page->assertMissing('main a[href="/settings/api-tokens"]');
    }
})->with(['operator EN' => ['Owner', true, 'en'], 'readonly Member EN' => ['Member', false, 'en'], 'operator DE' => ['Owner', true, 'de'], 'readonly Member DE' => ['Member', false, 'de']]);

it('edits check settings through the authorized product sheet', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $check = Check::factory()->create(['type' => CheckKey::Uptime]);
    $this->actingAs($user);

    visit('/sites/'.$check->site_id.'/checks/'.$check->id)
        ->click('[data-check-detail=uptime] button:has-text("Edit")')
        ->assertSee('GET')
        ->fill('input[name="timeout"]', '12')
        ->click('button[type="submit"]')
        ->assertSee('What we check')
        ->assertNoJavaScriptErrors();
    expect($check->refresh()->executionConfig()['timeout'])->toBe(12);
});

it('shows only observed certificate details in the check layout', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $check = Check::factory()->create(['type' => CheckKey::CertificateHealth, 'latest_run_result' => CheckResult::Warning,
        'summary' => 'certificate_health.expiring']);
    $at = CarbonImmutable::now('UTC')->subMinute();
    $run = CheckRun::factory()->for($check)->create(['started_at' => $at->subSecond(), 'ended_at' => $at]);
    $check->update(['latest_run_id' => $run->id, 'latest_run_ended_at' => $run->ended_at]);
    $snapshot = [
        'certificate_details' => ['fingerprint' => str_repeat('a', 64), 'issuer' => 'Example CA', 'domain' => 'example.test',
            'additional_domains' => ['www.example.test'], 'valid_from' => $at->subMonth()->toIso8601String(),
            'valid_until' => $at->addDays(6)->toIso8601String(), 'signature_algorithm' => 'ECDSA P-256', 'is_valid' => true],
        'certificate_checks' => [['type' => 'trust', 'passed' => true, 'label' => 'certificate_health.trust']],
        'certificate_chain_issuers' => ['Example CA'],
    ];
    $store = app(CheckRegistry::class)->get(CheckKey::CertificateHealth)->persistence();
    $context = new CheckContext($check->site_id, $check->id, $run->id, (string) Str::uuid(), $check->site->url, $at, $at->addMinute(), [], [], null);
    $store->stage($context, 'snapshot', [$snapshot]);
    $store->finalize(new RunReference($run->id, $at), $context->claimToken);
    $this->actingAs($user);

    visit('/sites/'.$check->site_id.'/checks/'.$check->id)
        ->assertSee('Example CA')->assertSee('www.example.test')->assertSee('ECDSA P-256')->assertSee('days left')->assertSee('Trusted')->assertSee('Certificate observations')->assertDontSee('Unchanged')->assertSee('History')
        ->assertNoJavaScriptErrors();
});
