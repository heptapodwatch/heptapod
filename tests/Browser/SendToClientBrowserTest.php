<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\Notifications\ChannelRegistry;
use Heptapod\Contracts\RunResult;
use Heptapod\Enums\RecipientKind;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\Client;
use Heptapod\Models\Delivery;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\NotificationPreference;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Vite;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-28T12:00:00Z'));
    Vite::useHotFile(storage_path('framework/testing.hot'));
    config(['broadcasting.default' => 'null']);
    Queue::fake();
});

it('sends a check\'s broken links to a client from the check page and lists the message on the client page', function (): void {
    $admin = User::factory()->create(['name' => 'Anna Berger']);
    $admin->assignRole('Admin');
    $client = Client::factory()->create(['name' => 'Müller GmbH', 'locale' => 'de', 'timezone' => 'Europe/Berlin']);
    $site = Site::factory()->create(['client_id' => $client->id, 'url' => 'https://shop.example.test']);
    $check = Check::factory()->create(['site_id' => $site->id, 'type' => CheckKey::BrokenLinks, 'latest_run_result' => CheckResult::Failed]);
    $run = CheckRun::factory()->for($check)->create(['result' => RunResult::Failed, 'summary' => 'broken_links.failed',
        'metrics' => ['total' => 10, 'internal' => 10, 'external' => 0, 'broken_internal' => 1, 'broken_external' => 0, 'mixed_content' => 0],
        'started_at' => CarbonImmutable::parse('2026-10-28T11:00:00Z'), 'ended_at' => CarbonImmutable::parse('2026-10-28T11:02:00Z')]);
    $check->update(['latest_run_id' => $run->id, 'latest_run_ended_at' => $run->ended_at]);
    $snapshot = DB::table('crawl_snapshots')->insertGetId(['site_id' => $site->id, 'check_id' => $check->id, 'run_id' => $run->id,
        'claim_token' => '00000000-0000-4000-8000-000000000002', 'run_ended_at' => $run->ended_at, 'collected_at' => $run->ended_at,
        'settings' => '{}', 'truncated' => false, 'observed_urls' => '[]', 'observed_pages' => '[]', 'discovered_urls' => '[]',
        'discovery_complete' => true, 'evaluated_finding_ids' => '[]', 'site_url' => $site->url, 'target_fingerprint' => str_repeat('a', 64),
        'classification_policy_version' => null]);
    DB::table('crawl_findings')->insert(['snapshot_id' => $snapshot, 'crawled_url' => 'https://shop.example.test/missing', 'relative_crawled_url' => '/missing',
        'status_code' => 404, 'found_on_url' => 'https://shop.example.test/', 'type' => 'link', 'internal' => true]);
    $office = NotificationDestination::query()->create(['recipient_kind' => RecipientKind::Client, 'client_id' => $client->id, 'channel' => 'mail',
        'label' => 'Kontakt-E-Mail', 'enabled' => true, 'config' => app(ChannelRegistry::class)->get('mail')->configSchema()->validateConfig(['email' => 'office@mueller.test'])]);
    NotificationPreference::writeInitialGrid($office->id, NotificationPreference::defaults(RecipientKind::Client));
    $this->actingAs($admin);

    visit("/sites/{$site->id}/checks/{$check->id}")->resize(1440, 1000)->click('Send to client…')
        ->assertSee('Müller GmbH gets these findings once; nothing else changes.')
        ->assertSee('Defekte Links auf shop.example.test: 1 gefunden')->assertSee('German · client language')
        ->fill('[data-sheet-form] textarea', 'Bitte prüfen.')->click('[data-sheet-form] button[type="submit"]')
        ->assertSee('Sent to Müller GmbH. It is listed on the client page.')->assertNoJavaScriptErrors();
    $delivery = Delivery::query()->where('origin', 'manual')->sole();
    expect($delivery->recipient_snapshot)->toBe(['name' => 'Kontakt-E-Mail', 'locale' => 'de', 'address' => 'office@mueller.test']);

    visit("/clients/{$client->id}?tab=messages")->assertSee('Defekte Links auf shop.example.test: 1 gefunden')
        ->assertSee('Sent by Anna Berger')->assertSee('office@mueller.test')->assertNoJavaScriptErrors();
});
