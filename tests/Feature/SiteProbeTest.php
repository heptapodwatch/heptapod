<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Contracts\Http\CertificateInfo;
use Heptapod\Contracts\Http\FetchFailure;
use Heptapod\Contracts\Http\FetchFailureKind;
use Heptapod\Contracts\Http\FetchRequest;
use Heptapod\Contracts\Http\FetchResult;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Contracts\Http\RedirectPolicy;
use Heptapod\Models\User;
use Heptapod\Testing\FakeOutboundHttp;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05T12:00:00Z'));
});

it('probes only through public-only bounded requests and returns observed facts', function (): void {
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $fake = new FakeOutboundHttp;
    $fake->respondUsing(function (FetchRequest $request): FetchResult {
        expect($request->redirects)->toBe(RedirectPolicy::Untrusted)
            ->and($request->siteHost)->toBeNull()
            ->and($request->publicTargetsOnly)->toBeTrue()
            ->and($request->maxRedirects)->toBe(3)
            ->and($request->maxDecodedBytes)->toBe(2097152);
        if ($request->url === 'https://www.example.test/sitemap.xml') {
            return new FetchResult(200, effectiveUrl: $request->url);
        }
        expect($request->withCertificateInfo)->toBeTrue();

        return new FetchResult(200, body: '<!doctype html><html><body>Shop</body></html>', headers: ['Server' => ['nginx'], 'Content-Type' => ['text/html']], effectiveUrl: 'https://www.example.test/shop',
            certificate: new CertificateInfo('Example CA', 'www.example.test', 'DNS:www.example.test', '2026-01-01T00:00:00+00:00', '2028-01-01T00:00:00+00:00'));
    });
    app()->instance(OutboundHttp::class, $fake);

    $this->actingAs($user)->postJson('/sites/probe', ['url' => 'http://example.test/shop'])->assertOk()->assertJson([
        'final_url' => 'https://www.example.test/shop', 'status' => 200, 'server' => 'nginx',
        'certificate_issuer' => 'Example CA', 'certificate_expires_at' => '2028-01-01T00:00:00+00:00',
        'sitemap_url' => 'https://www.example.test/sitemap.xml', 'failure' => null,
    ]);
    expect($fake->requests)->toHaveCount(2);
});

it('refuses unauthorized and private targets without attempting a sitemap fetch', function (): void {
    $this->travelBack();
    $member = User::factory()->create();
    $member->assignRole('Member');
    $this->actingAs($member)->postJson('/sites/probe', ['url' => 'http://127.0.0.1/'])->assertForbidden();
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $this->actingAs($owner)->postJson('/sites/probe', ['url' => 'http://127.0.0.1/'])->assertOk()
        ->assertJson(['final_url' => null, 'status' => null, 'sitemap_url' => null, 'failure' => 'probe_failed']);
    $this->postJson('/sites/probe', ['url' => 'http://fixtures.test/redirect/start'])->assertOk()
        ->assertJson(['final_url' => null, 'status' => null, 'failure' => 'probe_failed']);
    $this->postJson('/sites/probe', ['url' => 'http://user:password@example.test/'])->assertUnprocessable()->assertJsonValidationErrors('url');
});

it('returns a safe translated reason for each failed probe class', function (): void {
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $fake = new FakeOutboundHttp;
    app()->instance(OutboundHttp::class, $fake);
    foreach ([FetchFailureKind::Connect, FetchFailureKind::Timeout, FetchFailureKind::Dns, FetchFailureKind::PrivateTarget, FetchFailureKind::RedirectBlocked] as $kind) {
        $fake->respondUsing(static fn (): FetchResult => new FetchResult(503, failure: new FetchFailure($kind, 503, targetAddress: '10.0.0.5')));
        $response = $this->actingAs($user)->postJson('/sites/probe', ['url' => 'https://example.test/'])->assertOk();
        $response->assertJsonPath('failure', 'probe_failed')->assertJsonPath('normalised_url', 'https://example.test')
            ->assertJsonPath('failure_reason', __('causes.outbound.'.$kind->value))
            ->assertDontSee('10.0.0.5');
        expect($response->json('recommended_checks'))->toBe(['certificate_health', 'dns', 'domain', 'performance', 'uptime']);
    }
    expect($fake->requests)->toHaveCount(5);
});

it('does not start DNS after the probe budget has already expired', function (): void {
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $start = microtime(true);

    $this->actingAs($user)->postJson('/sites/probe', ['url' => 'https://example.test/'])->assertOk()
        ->assertJson(['final_url' => null, 'status' => null, 'failure' => 'probe_failed']);

    expect(microtime(true) - $start)->toBeLessThan(0.5);
});

it('kills DNS within the remaining positive budget at the probe endpoint', function (): void {
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $previous = getenv('PHP_BINARY');
    $previousPidPath = getenv('HEPTAPOD_DNS_TEST_PID');
    $previousServerPidPath = $_SERVER['HEPTAPOD_DNS_TEST_PID'] ?? null;
    $pidPath = tempnam(sys_get_temp_dir(), 'probe-dns-budget-');
    putenv('PHP_BINARY='.base_path('tests/Fixtures/slow-php-cli'));
    putenv('HEPTAPOD_DNS_TEST_PID='.$pidPath);
    $_SERVER['HEPTAPOD_DNS_TEST_PID'] = $pidPath;
    $this->travelBack();
    $this->travelTo(CarbonImmutable::now('UTC')->subSeconds(11)->subMilliseconds(800));
    try {
        $started = microtime(true);
        $this->actingAs($user)->postJson('/sites/probe', ['url' => 'https://example.test/'])->assertOk()
            ->assertJson(['final_url' => null, 'status' => null, 'failure' => 'probe_failed']);
        expect(microtime(true) - $started)->toBeLessThan(2);
        $pid = (int) file_get_contents($pidPath);
        expect($pid)->toBeGreaterThan(0)->and(posix_kill($pid, 0))->toBeFalse();
    } finally {
        putenv($previous === false ? 'PHP_BINARY' : 'PHP_BINARY='.$previous);
        putenv($previousPidPath === false ? 'HEPTAPOD_DNS_TEST_PID' : 'HEPTAPOD_DNS_TEST_PID='.$previousPidPath);
        if ($previousServerPidPath === null) {
            unset($_SERVER['HEPTAPOD_DNS_TEST_PID']);
        } else {
            $_SERVER['HEPTAPOD_DNS_TEST_PID'] = $previousServerPidPath;
        }
        unlink($pidPath);
    }
});

it('limits outbound probe requests per user', function (): void {
    $user = User::factory()->create();
    $user->assignRole('Admin');
    $fake = new FakeOutboundHttp;
    $fake->respondUsing(static fn (): FetchResult => new FetchResult(503, effectiveUrl: 'https://example.test/'));
    app()->instance(OutboundHttp::class, $fake);
    $this->actingAs($user);
    for ($attempt = 0; $attempt < 6; $attempt++) {
        $this->postJson('/sites/probe', ['url' => 'https://example.test/'])->assertOk();
    }
    $this->postJson('/sites/probe', ['url' => 'https://example.test/'])->assertStatus(429);
});
