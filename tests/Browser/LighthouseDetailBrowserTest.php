<?php

declare(strict_types=1);

use Heptapod\Contracts\ChromiumRenderer;
use Heptapod\Contracts\ExternalTimeoutException;
use Heptapod\Models\User;
use Heptapod\Testing\FakeChromiumRenderer;
use Illuminate\Support\Facades\Vite;

require_once __DIR__.'/../../addons/lighthouse/tests/Pest.php';

it('retains measured Lighthouse scores and localizes current evidence after an error', function (string $locale, string $phase): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $user = User::factory()->create(['locale' => $locale]);
    $user->assignRole('Owner');
    $secret = 'lighthouse-browser-secret-123';
    $check = lighthouseCheck(['notification_settings' => ['performance' => 70], 'http_client_headers' => [['name' => 'Authorization', 'value' => $secret]]]);
    $fake = new FakeChromiumRenderer;
    app()->instance(ChromiumRenderer::class, $fake);
    $raw = lighthouseFixture();
    $raw['categories']['performance']['score'] = 0.4;
    lighthouseReply($fake, $raw, headers: ['Authorization' => $secret]);
    runLighthouse($check);
    if ($phase === 'errored') {
        lighthouseReply($fake, new ExternalTimeoutException('Browser did not complete.'), headers: ['Authorization' => $secret]);
        runLighthouse($check);
    }
    config(['heptapod.browser.enabled' => $phase !== 'unavailable']);
    $this->actingAs($user);

    $url = '/sites/'.$check->site_id.'/checks/'.$check->id;
    expect($this->get($url)->getContent())->not->toContain($secret);
    $page = visit($url)->assertNoJavaScriptErrors()->assertAttribute('html[lang]', 'lang', $locale)->assertDontSee($secret);

    if ($phase === 'errored') {
        $page->assertSee($locale === 'de' ? 'Werte vom letzten erfolgreichen Durchlauf' : 'Scores from last successful run')
            ->assertSee($locale === 'de' ? 'Prüffehler' : 'Check error')
            ->assertDontSee($locale === 'de' ? 'Leistung: Wert 40, unter Schwellenwert 70.' : 'Performance: score 40, below threshold 70.');
        expect($page->script('Array.from(document.querySelectorAll("[data-check-detail=lighthouse] dl[aria-label] dd")).map(node => node.textContent)'))->toBe(['40', '96', '100', '80']);
    } else {
        $page->assertSee($locale === 'de' ? 'Leistung: Wert 40, unter Schwellenwert 70.' : 'Performance: score 40, below threshold 70.');
        expect($page->script('Array.from(document.querySelectorAll("[data-check-detail=lighthouse] dl[aria-label] dd")).map(node => node.textContent)'))->toBe(['40', '96', '100', '80']);
        if ($phase === 'unavailable') {
            $page->assertSee($locale === 'de' ? 'Browser-Dienst nicht installiert.' : 'Browser service not installed.');
            $page->assertAttribute('button:has-text("'.($locale === 'de' ? 'Prüfung starten' : 'Run check').'")', 'disabled', '');
        }
    }
    $page->assertNoJavaScriptErrors();
})->with(['en', 'de'])->with(['available', 'errored', 'unavailable']);
