<?php

declare(strict_types=1);

use Heptapod\Models\Client;
use Heptapod\Models\User;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Vite;

it('validates creates clears the one-time disclosure and revokes through the real controls', function (): void {
    $diagnosticsDirectory = getenv('HEPTAPOD_API_TOKEN_DIAGNOSTICS_DIR') ?: storage_path('framework/testing/api-token-diagnostics');
    if (! is_dir($diagnosticsDirectory) && ! mkdir($diagnosticsDirectory, 0700, true)) {
        throw new RuntimeException('Cannot create API token diagnostic directory.');
    }
    $diagnosticsFile = $diagnosticsDirectory.'/revoke-steps.jsonl';
    if (file_put_contents($diagnosticsFile, '') !== 0) {
        throw new RuntimeException('Cannot initialize API token diagnostic artifact.');
    }
    $record = static function (array $metadata) use ($diagnosticsFile): void {
        $line = json_encode($metadata, JSON_THROW_ON_ERROR).PHP_EOL;
        if (file_put_contents($diagnosticsFile, $line, FILE_APPEND | LOCK_EX) !== strlen($line)) {
            throw new RuntimeException('Cannot write API token diagnostic artifact.');
        }
    };
    // GitHub provenance is absent in local runs; never copy unvalidated environment strings.
    $sourceSha = getenv('GITHUB_SHA') ?: 'local';
    $runId = getenv('GITHUB_RUN_ID') ?: 'local';
    $runAttempt = getenv('GITHUB_RUN_ATTEMPT') ?: 'local';
    if (preg_match('/\A(?:[0-9a-f]{40}|local)\z/', $sourceSha) !== 1
        || preg_match('/\A(?:[0-9]{1,20}|local)\z/', $runId) !== 1
        || preg_match('/\A(?:[0-9]{1,10}|local)\z/', $runAttempt) !== 1) {
        throw new RuntimeException('Invalid API token diagnostic provenance.');
    }
    $record(['step' => 'provenance', 'source_sha' => $sourceSha, 'run_id' => $runId, 'run_attempt' => $runAttempt]);

    // These listeners belong to this case's application. Teardown disposes them without forgetting the framing listener.
    Event::listen(RouteMatched::class, static function (RouteMatched $event) use ($record): void {
        if ($event->route->getName() === 'settings.api-tokens.destroy' && $event->request->isMethod('DELETE')) {
            $record(['step' => 'revoke.delete.request', 'method' => 'DELETE', 'route' => '/settings/api-tokens/{token}']);
        }
    });
    Event::listen(RequestHandled::class, static function (RequestHandled $event) use ($record): void {
        $metadata = match ($event->request->route()?->getName()) {
            'settings.api-tokens.store' => ['step' => 'create.post.response', 'method' => 'POST', 'route' => '/settings/api-tokens'],
            'settings.api-tokens.destroy' => ['step' => 'revoke.delete.response', 'method' => 'DELETE', 'route' => '/settings/api-tokens/{token}'],
            default => null,
        };
        if ($metadata === null) {
            return;
        }
        $record([...$metadata, 'status' => $event->response->getStatusCode(), 'body_length' => strlen($event->response->getContent()),
            'connection_close' => $event->response->headers->get('Connection') === 'close',
            'transfer_encoding_present' => $event->response->headers->has('Transfer-Encoding'),
            'content_length_present' => $event->response->headers->has('Content-Length')]);
    });

    Vite::useHotFile(storage_path('framework/testing.hot'));
    config(['broadcasting.default' => 'null']);
    $user = User::factory()->create(['timezone' => 'Europe/Berlin']);
    $user->assignRole('Owner');
    $client = Client::factory()->create(['name' => 'Browser token client']);
    $this->actingAs($user);
    $page = visit('/settings/profile')->resize(1440, 900)->click('API tokens')->assertSee('No API tokens yet.')->click('Create token');
    // Mask credential pixels before issuance, including automatic failure screenshots. No trace is enabled.
    $page->script('const style = document.createElement("style"); style.textContent = "#one-time-secret { opacity: 0 !important; }"; document.head.append(style); Object.defineProperty(navigator, "clipboard", {configurable: true, value: {writeText: async () => { throw new DOMException("Denied", "NotAllowedError"); }}});');
    $page->fill('token-name', str_repeat('x', 256))->keys('token-name', 'Enter')->assertSee('The name field must not be greater than 255 characters.');
    $page->fill('token-name', 'Browser scoped read')->click('#token-client')->click('[role="option"]:has-text("'.$client->name.'")')
        ->fill('token-expiry', '2000-01-02T03:04')->keys('token-name', 'Enter')->assertSee('The expires at field must be a date after now.')
        ->fill('token-expiry', '2030-01-02T03:04')->keys('token-name', 'Enter')->assertSee('Copy your token now');
    try {
        expect($page->script('(() => { const value = document.querySelector("#one-time-secret").value; return value.length > 40 && !JSON.stringify(history.state).includes(value) && !JSON.stringify(localStorage).includes(value) && !JSON.stringify(sessionStorage).includes(value); })()'))->toBeTrue();
        $page->click('Copy')->assertSee('Copy failed. Select the token and copy it manually.')->screenshot(filename: 'api-tokens-secret-masked-1440');
        $page->resize(390, 844)->screenshot(filename: 'api-tokens-secret-masked-390');
        $page->script('Object.defineProperty(navigator, "clipboard", {configurable: true, value: undefined});');
        $page->click('Copy')->assertSee('Copy failed. Select the token and copy it manually.');
        $page->script('Object.defineProperty(navigator, "clipboard", {configurable: true, value: {writeText: async value => { window.__copyMatched = value === document.querySelector("#one-time-secret").value; }}});');
        $page->click('Copy')->assertSee('Copied');
        expect($page->script('window.__copyMatched === true'))->toBeTrue();
        $page->click('I have stored it')->assertDontSee('Copy your token now')->assertSee('Browser scoped read')->assertSee('Browser token client')->assertSee('Never used');
        expect($page->script('document.querySelector("#one-time-secret") === null'))->toBeTrue();
        $page->refresh()->assertDontSee('Copy your token now')->assertSee('Browser scoped read');
        $record(['step' => 'revoke.open.attempt']);
        $page->click('[data-test="revoke-token"]');
        $record(['step' => 'revoke.open.complete']);
        $page->assertSee('Revoke token?');
        $record(['step' => 'revoke.cancel.attempt']);
        $page->click('Cancel');
        $record(['step' => 'revoke.cancel.complete']);
        $page->assertSee('Browser scoped read');
        $record(['step' => 'revoke.reopen.attempt']);
        $page->click('[data-test="revoke-token"]');
        $record(['step' => 'revoke.reopen.complete']);
        $record(['step' => 'revoke.confirm.attempt']);
        $page->click('[data-test="confirm-revoke-token"]');
        $record(['step' => 'revoke.confirm.complete']);
        $page->assertSee('Token revoked.')->assertSee('No API tokens yet.')->assertNoJavaScriptErrors();
        expect($user->tokens()->count())->toBe(0);
    } finally {
        // Rollback owns DB cleanup; clear browser memory before any later failure artifact.
        $page->script('document.querySelector("#one-time-secret")?.remove(); delete window.__copyMatched;');
    }
});

it('does not redisclose an issued token after navigation and history return', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    config(['broadcasting.default' => 'null']);
    $user = User::factory()->create();
    $user->assignRole('Member');
    $this->actingAs($user);
    $page = visit('/settings/api-tokens')->click('Create token');
    $page->script('const style = document.createElement("style"); style.textContent = "#one-time-secret { opacity: 0 !important; }"; document.head.append(style);');
    $page->fill('token-name', 'Navigation token')->keys('token-name', 'Enter')->assertSee('Copy your token now');
    try {
        $page->script('document.querySelector("a[href$=\\"/settings/profile\\"]").click();');
        $page->assertSee('Language')->assertDontSee('Copy your token now');
        $page->script('history.back();');
        $page->assertSee('Navigation token')->assertDontSee('Copy your token now')->assertNoJavaScriptErrors();
        expect($page->script('document.querySelector("#one-time-secret") === null'))->toBeTrue();
    } finally {
        $page->script('document.querySelector("#one-time-secret")?.remove();');
    }
});
