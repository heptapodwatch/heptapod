<?php

declare(strict_types=1);

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckResult;
use Heptapod\Enums\IncidentCause;
use Heptapod\Models\Check;
use Heptapod\Models\Client;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\RecurringMaintenancePeriod;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Support\Facades\Vite;

it('filters incidents and saves a team note while clearing an existing reminder snooze without creating one', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $this->seed(DemoSeeder::class);
    $owner = User::query()->where('email', DatabaseSeeder::DEMO_EMAIL)->sole();
    $this->actingAs($owner);
    $incident = Incident::query()->whereNull('resolved_at')->whereHas('check', fn ($query) => $query->where('type', 'cron'))->firstOrFail();
    $incident->update(['snoozed_until' => now()->addHour()]);

    $page = visit('/incidents')->assertSee('Incidents')->assertSee('Open 6');
    $page->click('button:text-is("Resolved")')->assertSee('Cinema')
        ->assertAttribute('button:text-is("Resolved")', 'aria-checked', 'true')
        ->assertCount('main table tbody > tr', 3);
    $page->click('button:text-is("Open 6")')->click('role=link[name="Open incident '.$incident->id.' for Backup"s]');
    $page->assertSee('1 of 2 tasks is failing: Backup 2.')->assertSee('What we observe');
    $page->click('button:text-is("Add note")')->fill('textarea[aria-label="Internal note"]', 'Investigating this backup run.')
        ->click('button:text-is("Save note")')->assertSee('Note by Test User');
    expect($incident->refresh()->notes)->toBe('Investigating this backup run.');
    $page->assertSee('Reminders snoozed until')->assertDontSee('Snooze reminders')
        ->click('button:text-is("Resume reminders")')
        ->assertDontSee('Reminders snoozed until')->assertNoJavaScriptErrors();
    expect($incident->refresh()->snoozed_until)->toBeNull();
});

it('headlines incident evidence by historical check and cause without duplicate banners or creation snooze controls', function (string $locale, CheckKey $type, IncidentCause $cause, bool $resolved, string $headline): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $owner = User::factory()->create(['locale' => $locale]);
    $owner->assignRole('Owner');
    $site = Site::factory()->create(['friendly_name' => 'Review shop']);
    $check = Check::factory()->for($site)->create(['type' => $type,
        'latest_run_result' => $resolved ? CheckResult::Succeeded : ($cause === IncidentCause::Errored ? CheckResult::Errored : CheckResult::Failed),
        'latest_run_ended_at' => now()]);
    $incident = Incident::factory()->for($check)->create(['cause' => $cause, 'resolved_at' => $resolved ? now() : null]);
    $this->actingAs($owner);

    $page = visit('/incidents/'.$incident->id)->assertSeeIn('role=heading[level=1]', $headline)
        ->assertMissing('main [data-slot="alert"]')->assertDontSee('Resume reminders')
        ->assertPresent('textarea[aria-label]')->assertNoJavaScriptErrors();
    // Reminders exist only for an open uptime outage. There Snooze is the header's one secondary action, a plain button.
    $snoozeLabel = $locale === 'de' ? 'Erinnerungen pausieren' : 'Snooze reminders';
    if (! $resolved && $type === CheckKey::Uptime && $cause === IncidentCause::Failed) {
        $page->assertVisible('header button:text-is("'.$snoozeLabel.'")');
    } else {
        $page->assertDontSee($snoozeLabel);
    }
})->with([
    'resolved broken links EN' => ['en', CheckKey::BrokenLinks, IncidentCause::Failed, true, 'Review shop: Broken links resolved'],
    'resolved broken links DE' => ['de', CheckKey::BrokenLinks, IncidentCause::Failed, true, 'Review shop: Defekte Links behoben'],
    'resolved uptime Down EN' => ['en', CheckKey::Uptime, IncidentCause::Failed, true, 'Review shop is back up.'],
    'resolved uptime Down DE' => ['de', CheckKey::Uptime, IncidentCause::Failed, true, 'Review shop ist wieder erreichbar.'],
    'resolved uptime Errored EN' => ['en', CheckKey::Uptime, IncidentCause::Errored, true, 'Review shop: Uptime resolved'],
    'resolved uptime Errored DE' => ['de', CheckKey::Uptime, IncidentCause::Errored, true, 'Review shop: Erreichbarkeit behoben'],
    'open broken links Failed EN' => ['en', CheckKey::BrokenLinks, IncidentCause::Failed, false, 'Review shop has a failing check.'],
    'open broken links Failed DE' => ['de', CheckKey::BrokenLinks, IncidentCause::Failed, false, 'Bei Review shop schlägt eine Prüfung fehl.'],
    'open uptime Down EN' => ['en', CheckKey::Uptime, IncidentCause::Failed, false, 'Review shop is down.'],
    'open uptime Down DE' => ['de', CheckKey::Uptime, IncidentCause::Failed, false, 'Review shop ist ausgefallen.'],
    'open uptime Errored EN' => ['en', CheckKey::Uptime, IncidentCause::Errored, false, 'Review shop could not be checked.'],
    'open uptime Errored DE' => ['de', CheckKey::Uptime, IncidentCause::Errored, false, 'Review shop konnte nicht geprüft werden.'],
    'open broken links Errored EN' => ['en', CheckKey::BrokenLinks, IncidentCause::Errored, false, 'Review shop could not be checked.'],
    'open broken links Errored DE' => ['de', CheckKey::BrokenLinks, IncidentCause::Errored, false, 'Review shop konnte nicht geprüft werden.'],
]);

it('keeps the Upcoming client filter while changing range and type and can clear it', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $first = Client::factory()->create(['name' => 'First filter client']);
    $second = Client::factory()->create(['name' => 'Second filter client']);
    foreach ([[$first, 'First dated site'], [$second, 'Second dated site']] as [$client, $name]) {
        $site = Site::factory()->for($client)->create(['friendly_name' => $name]);
        MaintenancePeriod::factory()->create(['site_id' => $site->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour()]);
    }
    $this->actingAs($owner);

    $page = visit('/upcoming')->assertSee('First dated site')->assertSee('Second dated site');
    $page->click('[aria-label="Client"]')->click('[role=option]:has-text("Second filter client")')
        ->assertSeeIn('[aria-label="Client"]', 'Second filter client')
        ->assertScript('location.pathname === "/upcoming" && new URL(location.href).searchParams.get("days") === "90" && new URL(location.href).searchParams.get("type") === "all" && new URL(location.href).searchParams.get("client_id") === "'.$second->id.'"')
        ->assertSee('Second dated site')->assertDontSee('First dated site');
    $page->click('[aria-label="Time range"] button:text-is("30 days")')
        ->assertAttribute('[aria-label="Time range"] button:text-is("30 days")', 'aria-checked', 'true')
        ->assertScript('location.pathname === "/upcoming" && new URL(location.href).searchParams.get("days") === "30" && new URL(location.href).searchParams.get("type") === "all" && new URL(location.href).searchParams.get("client_id") === "'.$second->id.'"');
    $page->click('[role=combobox][aria-label="Type"]')->click('role=option[name="Maintenance"s]')
        ->assertSeeIn('[role=combobox][aria-label="Type"]', 'Maintenance')
        ->assertScript('location.pathname === "/upcoming" && new URL(location.href).searchParams.get("days") === "30" && new URL(location.href).searchParams.get("type") === "maintenance" && new URL(location.href).searchParams.get("client_id") === "'.$second->id.'"')
        ->assertSee('Second dated site')->assertDontSee('First dated site');
    expect($page->script('new URL(location.href).searchParams.get("client_id")'))->toBe((string) $second->id);
    $page->click('[aria-label="Client"]')->click('[role=option]:has-text("All clients")')
        ->assertSeeIn('[aria-label="Client"]', 'All clients')
        ->assertScript('location.pathname === "/upcoming" && new URL(location.href).searchParams.get("days") === "30" && new URL(location.href).searchParams.get("type") === "maintenance" && !new URL(location.href).searchParams.get("client_id")')
        ->assertSee('First dated site')->assertSee('Second dated site')->assertNoJavaScriptErrors();
});

it('preserves Maintenance client and site filters on the next page without changing window targets', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $client = Client::factory()->create(['name' => 'Paged maintenance client']);
    $site = Site::factory()->for($client)->create(['friendly_name' => 'Paged maintenance site']);
    MaintenancePeriod::factory()->count(51)->create(['site_id' => $site->id, 'reason' => 'Paged deployment',
        'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour()]);
    MaintenancePeriod::factory()->create(['reason' => 'Other client deployment']);
    $this->actingAs($owner);

    $page = visit('/maintenance?site_id='.$site->id)->click('#maintenance-client-filter')
        ->click('[role=option]:has-text("Paged maintenance client")')->assertSee('Paged deployment')
        ->assertDontSee('Other client deployment')->click('a:text-is("Next page")')->assertCount('button[aria-label^="More actions for"]', 1);
    expect($page->script('new URL(location.href).searchParams.get("client_id")'))->toBe((string) $client->id)
        ->and($page->script('new URL(location.href).searchParams.get("site_id")'))->toBe((string) $site->id);
    $page->assertSee('Paged maintenance site')->assertNoJavaScriptErrors();
});

it('schedules a monthly client window with a second Sunday and cancels one occurrence', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $client = Client::factory()->create(['name' => 'Schedule client']);
    $this->actingAs($owner);

    $page = visit('/maintenance')->assertSee('Maintenance')->click('button:text-is("New window")');
    $page->click('#maintenance-target-type');
    $page->click('[data-slot=select-item]:has-text("Client")');
    $page->click('#maintenance-target');
    $page->click('[data-slot=select-item]:has-text("Schedule client")');
    $page->click('#maintenance-repeat');
    $page->click('[data-slot=select-item]:has-text("Monthly")');
    $page->fill('#maintenance-reason', 'Scheduled migration')
        ->click('button:text-is("Schedule maintenance")')->assertSee('Scheduled migration')->assertNoJavaScriptErrors();
    $series = RecurringMaintenancePeriod::query()->where('target_type', 'client')->firstOrFail();
    expect($series->target_id)->toBe($client->id)->and($series->nth_weekday)->toBe(2)->and($series->day_of_week)->toBe(0);
    $page->click('button[aria-label^="More actions for"] >> nth=0')->click('[role=menuitem]:text-is("Edit")')->click('#maintenance-nth')
        ->click('[data-slot=select-item]:has-text("Day of month")')->fill('#maintenance-month-day', '15')
        ->click('button:text-is("Save")')->assertMissing('#maintenance-month-day')->assertNoJavaScriptErrors();
    expect($series->fresh()->day_of_month)->toBe(15)->and($series->fresh()->nth_weekday)->toBeNull();
    $before = MaintenancePeriod::query()->where('recurring_maintenance_period_id', $series->id)->count();
    $page->click('button[aria-label^="More actions for"] >> nth=0')->click('[role=menuitem]:text-is("Skip occurrence")')->assertSee('Skip this occurrence?')
        ->click('[role=dialog] button:text-is("Skip occurrence")')->assertMissing('[role=dialog]')->assertNoJavaScriptErrors();
    expect(MaintenancePeriod::query()->where('recurring_maintenance_period_id', $series->id)->count())->toBe($before - 1);
});

it('shows exact window hours in the window timezone and labels daily intervals', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $owner = User::factory()->create(['timezone' => 'America/New_York']);
    $owner->assignRole('Owner');
    $site = Site::factory()->create();
    MaintenancePeriod::factory()->create(['site_id' => $site->id,
        'starts_at' => now()->addDays(2)->setTime(15, 45),
        'ends_at' => now()->addDays(2)->setTime(16, 45), 'timezone' => 'UTC']);
    $this->actingAs($owner);

    $page = visit('/maintenance')->assertSee('15:45')->assertSee('16:45')->assertSee('1 site')
        ->click('button:text-is("New window")')->click('#maintenance-repeat')
        ->click('[data-slot=select-item]:has-text("Daily")')->assertSee('Every N days')
        ->assertNoJavaScriptErrors();
});

it('opens a site-specific maintenance list from the site header', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $site = Site::factory()->create(['friendly_name' => 'Review site']);
    MaintenancePeriod::factory()->create(['site_id' => $site->id, 'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHour(), 'reason' => 'Review deployment']);
    $this->actingAs($owner);

    // Without a runnable check the site header has no primary action, so Maintenance is a plain button, not a More menu item.
    $page = visit('/sites/'.$site->id)->assertMissing('button[aria-label="More actions"]')->click('header a:has-text("Maintenance")');
    $page->assertPathIs('/maintenance')->assertSee('Review deployment')->assertNoJavaScriptErrors();
    // With a check, Run all checks is primary and Maintenance moves into More actions.
    $incident = Incident::factory()->for(Check::factory()->for($site)->create())->create();
    visit('/incidents/'.$incident->id)->click('header a[href="/sites/'.$site->id.'"]')
        ->assertPathIs('/sites/'.$site->id)->click('button[aria-label="More actions"]')
        ->click('[role="menuitem"]:has-text("Maintenance")')
        ->assertPathIs('/maintenance')->assertSee('Review deployment')->assertNoJavaScriptErrors();
});

it('previews only an accepted mail delivery in a sandboxed iframe', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $this->actingAs($owner);
    $incident = Incident::factory()->create(['evidence' => [
        'summary' => 'causes.http', 'summary_params' => ['status' => 503],
        'evidence' => [], 'metrics' => null, 'run_ended_at' => now()->toIso8601String(),
    ]]);
    $event = Event::factory()->for($incident)->create(['type' => 'incident.opened', 'site_id' => $incident->site_id,
        'check_id' => $incident->check_id, 'to_result' => CheckResult::Failed, 'cause' => IncidentCause::Failed]);
    $destination = NotificationDestination::factory()->create();
    Delivery::query()->create(['event_id' => $event->id, 'destination_id' => $destination->id,
        'state' => 'sent', 'sent_at' => now(), 'next_attempt_at' => now(), 'suppression_evaluated_at' => now()]);

    $page = visit('/incidents/'.$incident->id)->click('button:text-is("Show notifications")')
        ->click('button:text-is("Preview email")')
        ->assertSee('Alert email preview')->assertPresent('iframe[sandbox]');
    expect($page->script('(() => { const frame = document.querySelector("iframe[sandbox]"); return frame.getAttribute("sandbox") === "" && frame.srcdoc.includes("<html"); })()'))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

it('keeps the incident and maintenance lists usable in German at phone width', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $owner = User::factory()->create(['locale' => 'de']);
    $owner->assignRole('Owner');
    $this->actingAs($owner);
    Incident::factory()->create();

    $page = visit('/incidents')->resize(390, 844)->assertSee('Störungen')->assertNoJavaScriptErrors();
    $maintenance = visit('/maintenance')->resize(390, 844)->assertSee('Wartung')
        ->click('button:text-is("Neues Zeitfenster")')->click('#maintenance-repeat')
        ->click('[data-slot=select-item]:has-text("Täglich")')->assertSee('Alle N Tage')->assertNoJavaScriptErrors();
    expect($maintenance->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
});
