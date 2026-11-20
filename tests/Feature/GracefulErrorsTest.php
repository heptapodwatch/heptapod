<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckKey;
use Heptapod\Enums\RecipientKind;
use Heptapod\Exceptions\ExpectedRefusal;
use Heptapod\Models\Check;
use Heptapod\Models\Client;
use Heptapod\Models\Incident;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-20T14:00:00Z'));
});

it('exposes check cooldown props, flashes a failure-path notice on Inertia race, and reports cooldown skips in Run all', function (): void {
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

    // Site overview Run all preview also exposes cooldown_until before confirmation.
    $this->get("/sites/{$site->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('run_all_checks', static fn ($plans): bool => collect($plans)->firstWhere('check_id', $cooled->id)['cooldown_until'] === $expectedUntil
                && collect($plans)->firstWhere('check_id', $ready->id)['cooldown_until'] === null));

    $this->get("/incidents/{$incident->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('cooldown_until', $expectedUntil));

    // Inertia race: redirects back WITH `_refusal` error (taking Inertia's onError path) + flash toast.
    $checkUrl = "/sites/{$site->id}/checks/{$cooled->id}";
    $this->from($checkUrl)
        ->withHeader('X-Inertia', 'true')
        ->post("{$checkUrl}/run")
        ->assertRedirect($checkUrl)
        ->assertSessionHasErrors(['_refusal' => 'This check ran recently. You can run it again at 15:04 (in 4 min).'])
        ->assertInertiaFlash('toast.type', 'warning')
        ->assertInertiaFlash('toast.message', 'This check ran recently. You can run it again at 15:04 (in 4 min).');

    // German locale flashes German copy.
    $owner->update(['locale' => 'de']);
    $this->from($checkUrl)
        ->withHeader('X-Inertia', 'true')
        ->post("{$checkUrl}/run")
        ->assertRedirect($checkUrl)
        ->assertSessionHasErrors(['_refusal' => 'Diese Prüfung lief erst vor Kurzem. Sie können sie um 15:04 (in 4 Min.) erneut starten.'])
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

it('handles 409 conflicts and 422 domain refusals via failure-path flash on Inertia and structured JSON for useHttpRequest', function (): void {
    $owner = User::factory()->create(['locale' => 'en']);
    $owner->assignRole('Owner');
    $site = Site::factory()->create();
    $ended = MaintenancePeriod::factory()->create([
        'site_id' => $site->id, 'starts_at' => now()->subHours(2), 'ends_at' => now()->subHour(),
    ]);
    $running = MaintenancePeriod::factory()->create([
        'site_id' => $site->id, 'starts_at' => now()->subMinutes(10), 'ends_at' => now()->addHour(),
    ]);

    // Inertia PUT/DELETE return 303 back with `_refusal` error (so open sheets stay open) + flash toast.
    $this->actingAs($owner)
        ->from('/maintenance')
        ->withHeader('X-Inertia', 'true')
        ->put("/maintenance/{$ended->id}", [])
        ->assertStatus(303)
        ->assertRedirect('/maintenance')
        ->assertSessionHasErrors(['_refusal' => __('errors.maintenance.already_ended')])
        ->assertInertiaFlash('toast.type', 'info')
        ->assertInertiaFlash('toast.message', __('errors.maintenance.already_ended'));

    $this->from('/maintenance')
        ->withHeader('X-Inertia', 'true')
        ->delete("/maintenance/{$running->id}")
        ->assertStatus(303)
        ->assertRedirect('/maintenance')
        ->assertSessionHasErrors(['_refusal' => __('errors.maintenance.already_started')])
        ->assertInertiaFlash('toast.type', 'warning')
        ->assertInertiaFlash('toast.message', __('errors.maintenance.already_started'));

    // Backup conflict (409 JSON) + real throttle cooldown exposed by BackupHistoryReader
    DB::table('backup_runs')->insert([
        'requested_by' => $owner->id, 'state' => 'running', 'requested_at' => now(), 'started_at' => now(),
    ]);
    $this->flushHeaders()
        ->postJson(route('settings.backups.store'))
        ->assertConflict()
        ->assertJsonPath('code', 'backup_already_running')
        ->assertJsonPath('message', __('errors.backup.already_running'));

    $this->getJson(route('settings.backups.data'))
        ->assertOk()
        ->assertJsonPath('cooldown_until', fn (?string $until): bool => $until !== null);
    RateLimiter::clear(md5('backup-now'.$owner->id));

    // Domain 422 JSON refusals carry `errors._refusal` so Inertia's useHttp passes the localized reason through useHttpRequest.
    $this->patchJson(route('settings.members.update', $owner), ['role' => 'Admin', 'client_ids' => []])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'last_owner')
        ->assertJsonPath('message', __('members.last_owner'))
        ->assertJsonPath('errors._refusal.0', __('members.last_owner'));

    $accountChannel = NotificationDestination::factory()->create([
        'recipient_kind' => RecipientKind::User, 'user_id' => $owner->id, 'account_mail' => true,
    ]);
    $this->deleteJson(route('notification-channels.destroy', $accountChannel))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'account_channel_kept')
        ->assertJsonPath('message', __('notifications.account_channel_kept'))
        ->assertJsonPath('errors._refusal.0', __('notifications.account_channel_kept'));

    config()->set('heptapod.updates.enabled_by_server', false);
    $this->patchJson(route('settings.updates.update'), ['enabled' => true])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'updates_disabled_by_server')
        ->assertJsonPath('message', __('errors.updates.disabled_by_server'))
        ->assertJsonPath('errors._refusal.0', __('errors.updates.disabled_by_server'));
});

it('renders signed-in navigation dead ends inside AppLayout, resets Inertia singleton state, and redirects stale partial reloads', function (): void {
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

    // Signed-in 404 → in-shell Inertia error page (status 404, no redirect) + canonical HTTPS origin in raw page data + resets Inertia singleton state for Octane workers.
    $canonicalOrigin = 'https://monitor.example.com:8443';
    URL::useOrigin($canonicalOrigin);
    URL::forceScheme('https');
    try {
        $signedIn404 = $this->actingAs($owner)
            ->get('/missing-ops-page')
            ->assertNotFound()
            ->assertHeaderMissing('Location')
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('errors/show')
                ->where('status', 404)
                ->where('title', __('errors.404.title'))
                ->where('recovery_url', $canonicalOrigin.'/sites')
                ->where('secondary_url', $canonicalOrigin.'/incidents'));
        $body = $signedIn404->getContent();
        expect($body)->not->toBeFalse();
        expect(preg_match('#<script\b[^>]*\bdata-page="app"[^>]*>(.*?)</script>#s', (string) $body, $match))->toBe(1);
        /** @var array{component?: string, props?: array{status?: int, recovery_url?: string, secondary_url?: string}} $embedded */
        $embedded = json_decode(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true, flags: JSON_THROW_ON_ERROR);
        expect($embedded['component'] ?? null)->toBe('errors/show')
            ->and($embedded['props']['status'] ?? null)->toBe(404)
            ->and($embedded['props']['recovery_url'] ?? null)->toBe($canonicalOrigin.'/sites')
            ->and($embedded['props']['secondary_url'] ?? null)->toBe($canonicalOrigin.'/incidents');
    } finally {
        URL::useOrigin(null);
        URL::forceScheme(null);
    }
    expect(Inertia::getShared())->toBe([])
        ->and(Inertia::getVersion())->toBe('');

    // Signed-in 403 → in-shell Inertia error page
    $this->actingAs($member)
        ->get('/settings/instance')
        ->assertForbidden()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('errors/show')
            ->where('status', 403)
            ->where('title', __('errors.403.title')));

    // Signed-in 410 expired findings snapshot → in-shell Inertia error page on direct GET and structured JSON for Export sheet
    $expiredFindingsUrl = route('sites.checks.findings', [
        'site' => $site->id, 'check' => $crawl->id, 'format' => 'csv',
        'run' => 999999, 'observed_at' => now()->toIso8601String(),
    ]);
    $this->actingAs($owner)
        ->get($expiredFindingsUrl)
        ->assertStatus(410)
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('errors/show')
            ->where('status', 410)
            ->where('title', __('errors.findings.expired_title'))
            ->where('recovery_url', route('sites.checks.show', ['site' => $site->id, 'check' => $crawl->id])));

    $this->actingAs($owner)
        ->getJson($expiredFindingsUrl)
        ->assertStatus(410)
        ->assertJsonPath('code', 'findings_snapshot_expired')
        ->assertJsonPath('message', __('errors.findings.expired'));

    // Stale partial reload (Activity pruned group) keeps the feed instead of replacing the page with errors/show.
    $version = $this->actingAs($owner)->get('/activity')->assertOk()->inertiaPage()['version'];
    $this->actingAs($owner)
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $version ?? '',
            'X-Inertia-Partial-Component' => 'activity/index',
            'X-Inertia-Partial-Data' => 'expanded_events',
        ])
        ->get('/activity?group=999999&event_page=1')
        ->assertRedirect(url('/activity'))
        ->assertInertiaFlash('toast.type', 'info')
        ->assertInertiaFlash('toast.message', __('errors.activity.group_pruned'));

    // Stale-tab Inertia mutation hitting 403/404 flashes back on failure path instead of opening a raw modal.
    $this->flushHeaders()
        ->actingAs($member)
        ->from('/sites')
        ->withHeader('X-Inertia', 'true')
        ->post("/sites/{$site->id}/pause")
        ->assertRedirect('/sites')
        ->assertSessionHasErrors(['_refusal' => __('errors.403.action')])
        ->assertInertiaFlash('toast.type', 'error')
        ->assertInertiaFlash('toast.message', __('errors.403.action'));

    $this->flushHeaders()
        ->actingAs($owner)
        ->from('/maintenance')
        ->withHeader('X-Inertia', 'true')
        ->delete('/maintenance/999999')
        ->assertStatus(303)
        ->assertRedirect('/maintenance')
        ->assertSessionHasErrors(['_refusal' => __('errors.404.action')])
        ->assertInertiaFlash('toast.type', 'warning')
        ->assertInertiaFlash('toast.message', __('errors.404.action'));
});

it('handles disabled account 401, Inertia 419 CSRF refresh on failure path, and early 413 PostTooLargeException', function (): void {
    $disabled = User::factory()->create(['disabled_at' => now(), 'locale' => 'en']);
    $disabled->assignRole('Member');

    // Web GET → standalone zero-DB Blade 401 with account_disabled header
    $this->actingAs($disabled)
        ->get('/sites')
        ->assertUnauthorized()
        ->assertHeader('X-Heptapod-Auth-Error', 'account_disabled')
        ->assertSee('hp-brand')
        ->assertSee('Account disabled')
        ->assertSee('Sign in')
        ->assertDontSee('<title>Unauthorized</title>', false);

    // JSON request → 401 with distinct account_disabled code & message
    $disabledJson = User::factory()->create(['disabled_at' => now(), 'locale' => 'en']);
    $disabledJson->assignRole('Member');
    $this->actingAs($disabledJson)
        ->getJson('/sites')
        ->assertUnauthorized()
        ->assertJsonPath('code', 'account_disabled')
        ->assertJsonPath('message', __('errors.401.description'));

    $disabledDe = User::factory()->create(['disabled_at' => now(), 'locale' => 'de']);
    $disabledDe->assignRole('Member');
    $this->actingAs($disabledDe)
        ->get('/sites')
        ->assertUnauthorized()
        ->assertSee('Konto deaktiviert')
        ->assertSee('Anmelden');

    // Inertia 419 CSRF expiry redirects back WITH `_refusal` error (so forms never clear drafts) + refreshed toast.
    Route::middleware('web')->post('/_test/graceful-errors/csrf', static fn (): never => throw new TokenMismatchException('CSRF token mismatch.'));
    Route::middleware('web')->post('/_test/graceful-errors/too-large', static fn (): never => throw new PostTooLargeException('Post too large.'));
    $owner = User::factory()->create(['locale' => 'en']);
    $owner->assignRole('Owner');
    $site = Site::factory()->create();

    $this->actingAs($owner)
        ->from("/sites/{$site->id}/settings")
        ->withHeader('X-Inertia', 'true')
        ->post('/_test/graceful-errors/csrf')
        ->assertRedirect("/sites/{$site->id}/settings")
        ->assertSessionHasErrors(['_refusal' => __('errors.419.refreshed')])
        ->assertInertiaFlash('toast.type', 'warning')
        ->assertInertiaFlash('toast.message', __('errors.419.refreshed'));

    // Raw non-Inertia POST keeps standalone Blade 419.
    $this->flushHeaders()
        ->actingAs($owner)
        ->post('/_test/graceful-errors/csrf')
        ->assertStatus(419)
        ->assertSee('hp-brand')
        ->assertSee('Session expired');

    // Early PostTooLargeException on Inertia returns real 413 (no session-less redirect) so app.tsx shows toast and keeps form open.
    $this->actingAs($owner)
        ->withHeader('X-Inertia', 'true')
        ->post('/_test/graceful-errors/too-large')
        ->assertStatus(413)
        ->assertSee('hp-brand');

    $this->flushHeaders()
        ->actingAs($owner)
        ->postJson('/_test/graceful-errors/too-large')
        ->assertStatus(413)
        ->assertJsonPath('code', 'payload_too_large')
        ->assertJsonPath('message', __('errors.413.upload'));
});

it('keeps unexpected 500 and 503 faults on zero-DB standalone Blade and reports 5xx HttpExceptions while keeping ExpectedRefusal silent', function (): void {
    config()->set('app.debug', false);
    $queriesAfterFault = 0;
    $faulted = false;
    DB::listen(static function () use (&$queriesAfterFault, &$faulted): void {
        if ($faulted) {
            $queriesAfterFault++;
        }
    });
    Route::middleware('web')->get('/_test/graceful-errors/500', static function () use (&$faulted): never {
        $faulted = true;
        abort(500, 'Simulated fault');
    });
    Route::middleware('web')->get('/_test/graceful-errors/503', static function () use (&$faulted): never {
        $faulted = true;
        abort(503, 'Simulated outage');
    });

    $owner = User::factory()->create(['locale' => 'en']);
    $owner->assignRole('Owner');

    $logged = [];
    Log::listen(static function ($entry) use (&$logged): void {
        $logged[] = $entry->message;
    });

    // Reporting contract: 5xx HttpExceptions report, 4xx HttpExceptions and ExpectedRefusal do not.
    $handler = app(ExceptionHandler::class);
    expect($handler->shouldReport(new HttpException(500, 'fault')))->toBeTrue()
        ->and($handler->shouldReport(new HttpException(503, 'outage')))->toBeTrue()
        ->and($handler->shouldReport(new HttpException(404, 'missing')))->toBeFalse()
        ->and($handler->shouldReport(new ExpectedRefusal(429, 'errors.429.description')))->toBeFalse();

    $this->actingAs($owner)->get('/_test/graceful-errors/500')
        ->assertStatus(500)
        ->assertSee('hp-brand')
        ->assertSee('Something went wrong')
        ->assertDontSee('errors/show');

    $faulted = false;
    $this->actingAs($owner)->get('/_test/graceful-errors/503')
        ->assertStatus(503)
        ->assertSee('hp-brand')
        ->assertSee('Temporarily unavailable')
        ->assertSee('Try again')
        ->assertDontSee('errors/show');

    expect($queriesAfterFault)->toBe(0)
        ->and($logged)->toContain('Simulated fault', 'Simulated outage');
});
