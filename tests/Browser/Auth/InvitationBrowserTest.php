<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Enums\ClientScope;
use Heptapod\Models\Client;
use Heptapod\Models\Invitation;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Vite;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-26T12:00:00Z'));
    Vite::useHotFile(storage_path('framework/testing.hot'));
    config(['broadcasting.default' => 'null']);
    expect(config('mail.default'))->toBe('array')->and(config('queue.default'))->toBe('sync');
    expect(Mail::mailer()->getSymfonyTransport())->toBeInstanceOf(ArrayTransport::class);
    $instance = app(InstanceSettings::class);
    $instance->name = 'Brand & Söhne';
    $instance->save();
});

it('joins through the allowed recipient form, lands in the invited scope and cannot reuse the link', function (bool $reactivate): void {
    $token = str_repeat('a', 64); // Public, test-database-only fixture token.
    $owner = User::factory()->create(['name' => 'Tobias Brand']);
    $owner->assignRole('Owner');
    $client = Client::factory()->create(['name' => 'Studio Mai']);
    $other = Client::factory()->create(['name' => 'Private client']);
    Site::factory()->create(['client_id' => $client->id, 'url' => 'https://allowed.example.test']);
    Site::factory()->create(['client_id' => $other->id, 'url' => 'https://hidden.example.test']);
    $email = 'recipient@example.test';
    $existing = $reactivate ? User::factory()->create(['email' => $email, 'disabled_at' => now(), 'client_scope' => ClientScope::All]) : null;
    $existing?->assignRole('Admin');
    $existing?->clients()->attach($other);
    $invitation = Invitation::factory()->create(['email' => $email, 'inviter_id' => $owner->id,
        'token_hash' => hash('sha256', $token), 'role' => 'Member', 'client_scope' => 'restricted', 'client_ids' => [$client->id]]);
    $submittedFields = [];
    Event::listen(RequestHandled::class, function (RequestHandled $event) use (&$submittedFields): void {
        if ($event->request->routeIs('invitations.accept')) {
            $submittedFields[] = array_keys($event->request->all());
        }
    });
    $page = visit(route('invitations.show', $token, absolute: false), ['viewport' => ['width' => 1440, 'height' => 900]])
        ->assertSee('Join Brand & Söhne')->assertSee('Tobias Brand')->assertSee('Studio Mai · 1 site')->assertSee($email)
        ->assertMissing('input[name="email"]')->assertMissing('input[name="role"]')->assertMissing('input[name="client_ids"]');
    $page->script('localStorage.setItem("appearance", "dark"); document.documentElement.classList.add("dark")');
    $page->fill('Your name', 'Lena Hoff')->fill('#password', 'password')->fill('#password_confirmation', 'different-password')
        ->click('Join')->assertSee('The password field confirmation does not match.')->assertValue('#name', 'Lena Hoff')
        ->assertValue('#password', '')->assertValue('#password_confirmation', '')
        ->screenshot(filename: 'invitation-fixture-en-desktop-validation');
    expect(Invitation::query()->find($invitation->id))->not->toBeNull();
    expect(User::query()->where('email', $email)->count())->toBe($reactivate ? 1 : 0);
    $page->fill('#password', 'password')->fill('#password_confirmation', 'password')->click('Join')
        ->assertPathIs('/sites')->assertSee('Lena Hoff');
    $page->click('role=link[name="Sites"s]')->assertSee('allowed.example.test')->assertDontSee('hidden.example.test');
    $member = User::query()->where('email', $email)->sole();
    expect($member->name)->toBe('Lena Hoff')->and($member->hasVerifiedEmail())->toBeTrue()
        ->and($member->disabled_at)->toBeNull()->and($member->client_scope)->toBe(ClientScope::Restricted)
        ->and($member->getRoleNames()->all())->toBe(['Member'])->and($member->clients()->get()->modelKeys())->toBe([$client->id])
        ->and(Hash::check('password', $member->password))->toBeTrue();
    if ($existing !== null) {
        expect($member->id)->toBe($existing->id);
    }
    expect(Invitation::query()->find($invitation->id))->toBeNull();
    expect($submittedFields)->toBe([['name', 'password', 'password_confirmation'], ['name', 'password', 'password_confirmation']]);
    $this->assertAuthenticatedAs($member);
    $page->screenshot(filename: $reactivate ? 'invitation-fixture-reactivated-scoped-landing' : 'invitation-fixture-new-scoped-landing')
        ->click('[data-test="sidebar-menu-button"]')->click('Log out')->assertPathIs('/login');
    $used = visit(route('invitations.show', $token, absolute: false))->assertSee('Invitation unavailable')->assertMissing('form')
        ->screenshot(filename: 'invitation-fixture-used')->assertNoJavaScriptErrors();
    $used->click('Log in')->assertPathIs('/login');
    $page->assertNoJavaScriptErrors();
})->with(['new recipient' => [false], 'same-ID reactivation' => [true]]);

it('keeps the real recipient form responsive in English and German, dark and light', function (string $locale, int $width, bool $dark): void {
    $token = str_repeat('b', 64);
    $client = Client::factory()->create(['name' => 'Studio Mai']);
    Invitation::factory()->create(['token_hash' => hash('sha256', $token), 'email' => 'lena@example.test',
        'role' => 'Member', 'client_scope' => 'restricted', 'client_ids' => [$client->id]]);
    $page = visit('/login', ['viewport' => ['width' => $width, 'height' => 900]]);
    $page->script('document.cookie = "locale='.$locale.'; path=/"; localStorage.setItem("appearance", "'.($dark ? 'dark' : 'light').'")');
    $page->navigate(route('invitations.show', $token, absolute: false))->assertSee($locale === 'de' ? 'Ihr Name' : 'Your name')->assertSee('Studio Mai')->assertSee('lena@example.test')
        ->assertSee($locale === 'de' ? 'Passwort bestätigen' : 'Confirm password')
        ->screenshot(filename: 'invitation-fixture-'.$locale.'-'.$width.'-'.($dark ? 'dark' : 'light'));
    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    expect($page->script('document.documentElement.classList.contains("dark")'))->toBe($dark);
    $page->assertNoJavaScriptErrors();
})->with([
    'EN desktop dark' => ['en', 1440, true], 'EN desktop light' => ['en', 1440, false],
    'EN tablet dark' => ['en', 1024, true], 'EN tablet light' => ['en', 1024, false],
    'EN mobile dark' => ['en', 390, true], 'EN mobile light' => ['en', 390, false],
    'DE desktop dark' => ['de', 1440, true], 'DE desktop light' => ['de', 1440, false],
    'DE tablet dark' => ['de', 1024, true], 'DE tablet light' => ['de', 1024, false],
    'DE mobile dark' => ['de', 390, true], 'DE mobile light' => ['de', 390, false],
]);

it('shows expired or revoked links without a form or account creation', function (bool $expired): void {
    $token = str_repeat('c', 64);
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $invitation = Invitation::factory()->create(['token_hash' => hash('sha256', $token), 'email' => 'unavailable@example.test',
        'expires_at' => $expired ? now()->subMinute() : now()->addDay()]);
    if (! $expired) {
        $this->actingAs($owner);
        $roster = visit('/settings/members')
            ->assertScript('typeof history.state?.page?.props?.upcomingCount === "number"');
        // Finish the initial deferred reload before revocation; overlapping browser requests can fail in this harness.
        // TODO: diagnose intermittent Pest browser transport failures during revocation.
        $roster->click('button[aria-label$="unavailable@example.test"]')->click('[data-test="revoke-invitation-'.$invitation->id.'"]')->assertSee('Invitation revoked.');
        $roster->click('[data-test="sidebar-menu-button"]')->click('Log out')->assertPathIs('/login')->assertNoJavaScriptErrors();
    }
    $page = visit(route('invitations.show', $token, absolute: false), ['viewport' => ['width' => 390, 'height' => 844]])
        ->assertSee($expired ? 'Invitation expired' : 'Invitation unavailable')->assertMissing('form')->assertDontSee($invitation->email)
        ->screenshot(filename: 'invitation-fixture-'.($expired ? 'expired' : 'revoked').'-mobile')->assertNoJavaScriptErrors();
    expect(User::query()->where('email', $invitation->email)->exists())->toBeFalse();
})->with(['expired' => [true], 'revoked through Members controls' => [false]]);

it('handles expiry after preview and an already active identity without false success', function (bool $active): void {
    $token = str_repeat('d', 64);
    $email = 'stale@example.test';
    $invitation = Invitation::factory()->create(['token_hash' => hash('sha256', $token), 'email' => $email]);
    $page = visit(route('invitations.show', $token, absolute: false))->assertSee('Your name');
    if ($active) {
        $user = User::factory()->create(['email' => $email, 'name' => 'Existing identity']);
    } else {
        $invitation->update(['expires_at' => now()->subMinute()]);
    }
    $page->fill('#name', 'Do not save')->fill('#password', 'password')->fill('#password_confirmation', 'password')->click('Join')
        ->assertSee($active ? 'Account already active' : 'Invitation expired')->assertMissing('form')->assertNoJavaScriptErrors();
    expect(Invitation::query()->find($invitation->id))->not->toBeNull();
    if ($active) {
        expect($user->refresh()->name)->toBe('Existing identity');
    } else {
        expect(User::query()->where('email', $email)->exists())->toBeFalse();
    }
    $this->assertGuest();
})->with(['expires while filling the form' => [false], 'account activated elsewhere' => [true]]);

it('keeps Join available after a real 429 without claiming an uncertain result', function (string $locale): void {
    $token = str_repeat('f', 64);
    $invitation = Invitation::factory()->create(['token_hash' => hash('sha256', $token), 'email' => 'throttled-recipient@example.test']);
    $statuses = [];
    Event::listen(RequestHandled::class, function (RequestHandled $event) use (&$statuses): void {
        if ($event->request->routeIs('invitations.accept')) {
            $statuses[] = $event->response->getStatusCode();
        }
    });
    $page = visit('/login');
    $page->script('document.cookie = "locale='.$locale.'; path=/"');
    $page->navigate(route('invitations.show', $token, absolute: false))->assertSee($locale === 'de' ? 'Ihr Name' : 'Your name');
    // The preview and five real GETs exhaust the existing shared six-per-minute invitation budget.
    expect($page->script('async () => { const statuses = []; for (let attempt = 0; attempt < 5; attempt++) { const response = await fetch(location.pathname, { headers: { Accept: "application/json" } }); statuses.push(response.status); } return statuses; }'))->toBe([200, 200, 200, 200, 200]);

    $page->fill('#name', 'Retained draft')->fill('#password', 'password')->fill('#password_confirmation', 'password')
        ->click($locale === 'de' ? 'Beitreten' : 'Join')
        ->assertSee($locale === 'de' ? 'Zu viele Versuche. Bitte versuchen Sie es in Kürze erneut.' : 'Too many attempts. Try again shortly.')
        ->assertValue('#name', 'Retained draft')->assertValue('#password', '')->assertValue('#password_confirmation', '')
        ->assertEnabled('button[type="submit"]')->assertDontSee($locale === 'de' ? 'Das Ergebnis konnte nicht bestätigt werden.' : 'The join result could not be confirmed.')
        ->assertDontSee($locale === 'de' ? 'Einladung akzeptiert.' : 'Invitation accepted.')
        ->screenshot(filename: 'invitation-fixture-'.$locale.'-throttled')->assertNoJavaScriptErrors();
    expect($statuses)->toBe([429]);
    $this->assertModelExists($invitation);
    expect(User::query()->where('email', $invitation->email)->exists())->toBeFalse();
    $this->assertGuest();
})->with(['English' => ['en'], 'German' => ['de']]);

it('shows German confirmation validation on mobile without accepting or losing the name draft', function (): void {
    $token = str_repeat('e', 64);
    $invitation = Invitation::factory()->create(['token_hash' => hash('sha256', $token), 'email' => 'de-validation@example.test']);
    $page = visit('/login', ['viewport' => ['width' => 390, 'height' => 900]]);
    $page->script('document.cookie = "locale=de; path=/"; localStorage.setItem("appearance", "light")');
    $page->navigate(route('invitations.show', $token, absolute: false))->fill('Ihr Name', 'Mira Stern')
        ->fill('#password', 'password')->fill('#password_confirmation', 'different-password')->click('Beitreten')
        ->assertSee('Passwort stimmt nicht mit der Bestätigung überein.')->assertValue('#name', 'Mira Stern')
        ->assertValue('#password', '')->assertValue('#password_confirmation', '')
        ->screenshot(filename: 'invitation-fixture-de-mobile-validation')->assertNoJavaScriptErrors();
    expect(Invitation::query()->find($invitation->id))->not->toBeNull();
    expect(User::query()->where('email', $invitation->email)->exists())->toBeFalse();
});
