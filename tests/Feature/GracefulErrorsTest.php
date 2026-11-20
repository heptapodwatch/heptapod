<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckKey;
use Heptapod\Enums\RecipientKind;
use Heptapod\Models\Check;
use Heptapod\Models\Client;
use Heptapod\Models\Incident;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-20T14:00:00Z'));
});

it('exposes check cooldown props, flashes a localized notice on Inertia race, and reports cooldown skips in Run all', function (): void {
    $owner = User::factory()->create(['timezone' => 'Europe/Berlin', 'locale' => 'en']);
    $owner->assignRole('Owner');
    $site = Site::factory()->create();
    $cooled = Check::factory()->for($site)->create([
        'type' => CheckKey::Sitemap,
        'last_manual_requested_at' => now()->subSeconds(60),
    ]);
    $ready = Check::factory()->for($site)->create(['type' => CheckKey::Uptime]);
    $incident = Incident::factory()->for($site)->for($cooled)->create(['resolved_at' => null]);

    $expectedUntil = now()->addSeconds(240)->toIso8601String();

    $this->actingAs($owner)->get("/sites/{$site->id}/checks/{$cooled->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('cooldown_until', $expectedUntil));

    $this->get("/incidents/{$incident->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('cooldown_until', $expectedUntil));

    // Inertia race: redirects back with flash toast and never renders a raw 429 page.
    $checkUrl = "/sites/{$site->id}/checks/{$cooled->id}";
    $this->from($checkUrl)
        ->withHeader('X-Inertia', 'true')
        ->post("{$checkUrl}/run")
        ->assertRedirect($checkUrl)
        ->assertInertiaFlash('toast.type', 'warning')
        ->assertInertiaFlash('toast.message', 'This check ran recently. You can run it again at 15:04 (in 4 min).');

    // German locale flashes German copy.
    $owner->update(['locale' => 'de']);
    $this->from($checkUrl)
        ->withHeader('X-Inertia', 'true')
        ->post("{$checkUrl}/run")
        ->assertRedirect($checkUrl)
        ->assertInertiaFlash('toast.message', 'Diese Prüfung lief erst vor Kurzem. Sie können sie um 15:04 (in 4 Min.) erneut starten.');

    // JSON callers keep 429 + Retry-After + structured message.
    $owner->update(['locale' => 'en']);
    $this->flushHeaders()
        ->postJson("{$checkUrl}/run")
        ->assertTooManyRequests()
        ->assertHeader('Retry-After', '240')
        ->assertJsonPath('code', 'check_cooldown')
        ->assertJsonPath('retry_after', 240);

    // Run all separates cooldown skips from unavailable checks.
    $this->flushHeaders()
        ->post("/sites/{$site->id}/run-all", ['confirmed' => true])
        ->assertRedirect("/sites/{$site->id}")
        ->assertSessionHas('run_all', static fn (array $result): bool => $result['queued'] === 1
            && $result['cooldown'] === 1
            && $result['unavailable'] === 0
            && collect($result['results'])->firstWhere('check_id', $cooled->id) === [
                'check_id' => $cooled->id, 'status' => 'skipped', 'reason' => 'rate_limited',
            ]);
});

it('handles 409 conflicts and 422 domain refusals via flash on Inertia and structured JSON for useHttpRequest', function (): void {
    $owner = User::factory()->create(['locale' => 'en']);
    $owner->assignRole('Owner');
    $site = Site::factory()->create();
    $ended = MaintenancePeriod::factory()->create([
        'site_id' => $site->id, 'starts_at' => now()->subHours(2), 'ends_at' => now()->subHour(),
    ]);
    $running = MaintenancePeriod::factory()->create([
        'site_id' => $site->id, 'starts_at' => now()->subMinutes(10), 'ends_at' => now()->addHour(),
    ]);

    // Inertia PUT/DELETE return 303 back + flash toast (never 302 replay or raw 409).
    $this->actingAs($owner)
        ->from('/maintenance')
        ->withHeader('X-Inertia', 'true')
        ->put("/maintenance/{$ended->id}", [])
        ->assertStatus(303)
        ->assertRedirect('/maintenance')
        ->assertInertiaFlash('toast.type', 'info')
        ->assertInertiaFlash('toast.message', __('errors.maintenance.already_ended'));

    $this->from('/maintenance')
        ->withHeader('X-Inertia', 'true')
        ->delete("/maintenance/{$running->id}")
        ->assertStatus(303)
        ->assertRedirect('/maintenance')
        ->assertInertiaFlash('toast.type', 'warning')
        ->assertInertiaFlash('toast.message', __('errors.maintenance.already_started'));

    // Backup conflict (409 JSON)
    DB::table('backup_runs')->insert([
        'requested_by' => $owner->id, 'state' => 'running', 'requested_at' => now(), 'started_at' => now(),
    ]);
    $this->flushHeaders()
        ->postJson(route('settings.backups.store'))
        ->assertConflict()
        ->assertJsonPath('code', 'backup_already_running')
        ->assertJsonPath('message', __('errors.backup.already_running'));

    // Last active Owner (422 JSON)
    $this->patchJson(route('settings.members.update', $owner), ['role' => 'Admin', 'client_ids' => []])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'last_owner')
        ->assertJsonPath('message', __('members.last_owner'));

    // Built-in account email channel (422 JSON)
    $accountChannel = NotificationDestination::factory()->create([
        'recipient_kind' => RecipientKind::User, 'user_id' => $owner->id, 'account_mail' => true,
    ]);
    $this->deleteJson(route('notification-channels.destroy', $accountChannel))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'account_channel_kept')
        ->assertJsonPath('message', __('notifications.account_channel_kept'));

    // Update checks disabled by server env (422 JSON)
    config()->set('heptapod.updates.enabled_by_server', false);
    $this->patchJson(route('settings.updates.update'), ['enabled' => true])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'updates_disabled_by_server')
        ->assertJsonPath('message', __('errors.updates.disabled_by_server'));
});

it('renders signed-in navigation dead ends inside AppLayout and guest errors on zero-DB Blade', function (): void {
    $owner = User::factory()->create(['locale' => 'en']);
    $owner->assignRole('Owner');
    $member = User::factory()->create(['locale' => 'en']);
    $member->assignRole('Member');
    $client = Client::factory()->create();
    $site = Site::factory()->for($client)->create();
    $crawl = Check::factory()->for($site)->create(['type' => CheckKey::BrokenLinks]);

    // Guest 404 → standalone zero-DB Blade page with Sign in
    $this->get('/does-not-exist-anywhere')
        ->assertNotFound()
        ->assertSee('hp-brand')
        ->assertSee('Page not found')
        ->assertSee('Sign in');

    // Signed-in 404 → in-shell Inertia error page (status 404, no redirect)
    $this->actingAs($owner)
        ->get('/does-not-exist-anywhere')
        ->assertNotFound()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('errors/show')
            ->where('status', 404)
            ->where('title', __('errors.404.title')));

    // Signed-in 403 → in-shell Inertia error page
    $this->actingAs($member)
        ->get('/settings/instance')
        ->assertForbidden()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('errors/show')
            ->where('status', 403)
            ->where('title', __('errors.403.title')));

    // Signed-in 410 expired findings snapshot → in-shell Inertia error page pointing back to check
    $this->actingAs($owner)
        ->get(route('sites.checks.findings', [
            'site' => $site->id, 'check' => $crawl->id,
            'run' => 999999, 'observed_at' => now()->toIso8601String(),
        ]))
        ->assertStatus(410)
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('errors/show')
            ->where('status', 410)
            ->where('title', __('errors.findings.expired_title'))
            ->where('recovery_url', route('sites.checks.show', ['site' => $site->id, 'check' => $crawl->id])));

    // Stale-tab Inertia mutation hitting 403/404 flashes back instead of opening a raw modal
    $this->actingAs($member)
        ->from('/sites')
        ->withHeader('X-Inertia', 'true')
        ->post("/sites/{$site->id}/pause")
        ->assertRedirect('/sites')
        ->assertInertiaFlash('toast.type', 'error')
        ->assertInertiaFlash('toast.message', __('errors.403.action'));

    $this->actingAs($owner)
        ->from('/maintenance')
        ->withHeader('X-Inertia', 'true')
        ->delete('/maintenance/999999')
        ->assertStatus(303)
        ->assertRedirect('/maintenance')
        ->assertInertiaFlash('toast.type', 'warning')
        ->assertInertiaFlash('toast.message', __('errors.404.action'));
});

it('renders standalone Blade 401 for disabled accounts and refreshes CSRF 419 on Inertia submits', function (): void {
    $disabled = User::factory()->create(['disabled_at' => now(), 'locale' => 'en']);
    $disabled->assignRole('Member');

    $this->actingAs($disabled)
        ->get('/sites')
        ->assertUnauthorized()
        ->assertSee('hp-brand')
        ->assertSee('Account disabled')
        ->assertSee('Sign in')
        ->assertDontSee('<title>Unauthorized</title>', false);

    $disabledDe = User::factory()->create(['disabled_at' => now(), 'locale' => 'de']);
    $disabledDe->assignRole('Member');
    $this->actingAs($disabledDe)
        ->get('/sites')
        ->assertUnauthorized()
        ->assertSee('Konto deaktiviert')
        ->assertSee('Anmelden');

    // Inertia 419 CSRF expiry redirects back with refreshed notice (keeping form state via preserveState);
    // raw non-Inertia POST keeps standalone Blade 419.
    Route::middleware('web')->post('/_test/graceful-errors/csrf', static fn (): never => throw new TokenMismatchException('CSRF token mismatch.'));
    $owner = User::factory()->create(['locale' => 'en']);
    $owner->assignRole('Owner');
    $site = Site::factory()->create();

    $this->actingAs($owner)
        ->from("/sites/{$site->id}/settings")
        ->withHeader('X-Inertia', 'true')
        ->post('/_test/graceful-errors/csrf')
        ->assertRedirect("/sites/{$site->id}/settings")
        ->assertInertiaFlash('toast.type', 'warning')
        ->assertInertiaFlash('toast.message', __('errors.419.refreshed'));

    $this->flushHeaders()
        ->actingAs($owner)
        ->post('/_test/graceful-errors/csrf')
        ->assertStatus(419)
        ->assertSee('hp-brand')
        ->assertSee('Session expired');
});

it('keeps unexpected 500 and 503 faults on the zero-DB standalone Blade page', function (): void {
    config()->set('app.debug', false);
    Route::middleware('web')->get('/_test/graceful-errors/500', static fn () => abort(500));
    Route::middleware('web')->get('/_test/graceful-errors/503', static fn () => abort(503));

    $owner = User::factory()->create(['locale' => 'en']);
    $owner->assignRole('Owner');

    $this->actingAs($owner)->get('/_test/graceful-errors/500')
        ->assertStatus(500)
        ->assertSee('hp-brand')
        ->assertSee('Something went wrong')
        ->assertDontSee('errors/show');

    $this->actingAs($owner)->get('/_test/graceful-errors/503')
        ->assertStatus(503)
        ->assertSee('hp-brand')
        ->assertSee('Temporarily unavailable')
        ->assertSee('Try again')
        ->assertDontSee('errors/show');
});
