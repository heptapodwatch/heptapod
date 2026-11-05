<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Message;
use GuzzleHttp\Psr7\Response;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\Http\FetchException;
use Heptapod\Contracts\Http\FetchFailureKind;
use Heptapod\Contracts\Http\FetchRequest;
use Heptapod\Contracts\Http\FetchResult;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Contracts\Http\PrivateTargetGuard;
use Heptapod\Contracts\Http\RedirectPolicy;
use Heptapod\Contracts\Http\TargetResolver;
use Heptapod\Testing\DelayedTargetResolver;
use Heptapod\Testing\FakeOutboundHttp;
use Heptapod\Testing\RecordingOutboundHttp;
use Illuminate\Support\Sleep;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Tests\Support\ClosedGuardedHttp;

function pacedHttpContext(float $seconds = 15): CheckContext
{
    $at = CarbonImmutable::now();

    return new CheckContext(1, 1, 1, 'paced-transport-test', 'http://fixtures.test', $at, $at->addMilliseconds((int) ($seconds * 1000)), [], [], null);
}

it('resolves fresh fake paced sessions with the supplied transport context and extra delay', function (): void {
    $this->freezeTime();
    Sleep::fake(syncWithCarbon: true);
    $context = pacedHttpContext();
    $fake = (new FakeOutboundHttp)->respondUsing(static function (FetchRequest $request, CheckContext $actual) use ($context): FetchResult {
        expect($actual)->toBe($context)->and($request->url)->toBe('http://fixtures.test/seam-only');

        return new FetchResult(200, 'configured fake response');
    });
    $first = $fake->pacedClient($context, 50);
    $second = $fake->pacedClient($context, 50);
    $request = new FetchRequest('http://fixtures.test/seam-only');

    expect($first)->not->toBe($second)
        ->and($first->fetch($request)?->body)->toBe('configured fake response')
        ->and($first->fetch($request)?->body)->toBe('configured fake response')
        ->and($second->fetch($request)?->body)->toBe('configured fake response')
        ->and($fake->requests)->toHaveCount(3);
    Sleep::assertSequence([Sleep::for(160)->milliseconds()]);
    expect($fake->pacedClient(pacedHttpContext(0.9))->fetch($request))->toBeNull()
        ->and($fake->requests)->toHaveCount(3);
});

it('keeps recording on the real guarded transport when resolving fresh paced sessions', function (): void {
    $context = pacedHttpContext();
    $recorded = new RecordingOutboundHttp(app(OutboundHttp::class));
    $first = $recorded->pacedClient($context, 25);
    $second = $recorded->pacedClient($context, 25);

    expect($first)->not->toBe($second)
        ->and($first->fetch(new FetchRequest('http://fixtures.test/status/200'))?->status)->toBe(200)
        ->and($second->fetch(new FetchRequest('http://fixtures.test/status/200'))?->status)->toBe(200)
        ->and(array_column($recorded->configurations, 'select_timeout'))->toBe([0.0, 0.0])
        ->and(array_column($recorded->transfers, 'status'))->toBe([200, 200]);
    foreach ($recorded->transfers as $transfer) {
        expect($transfer['transport_seconds'])->toBeFloat()
            ->and($transfer['timeout_at_dispatch_seconds'])->toBeGreaterThan(0);
    }
    $blocked = $recorded->pacedClient($context)->fetch(new FetchRequest('http://127.0.0.1/private', redirects: RedirectPolicy::Untrusted, siteHost: 'fixtures.test'));
    expect($blocked?->failure?->kind)->toBe(FetchFailureKind::PrivateTarget)
        ->and($recorded->transfers)->toHaveCount(2);
    expect($recorded->pacedClient(pacedHttpContext(0.9))->fetch(new FetchRequest('http://fixtures.test/status/200')))->toBeNull()
        ->and($recorded->transfers)->toHaveCount(2);
});

it('keeps native guard redirect TLS and byte policies with the scoped constructor option', function (?float $selectTimeout): void {
    $http = app(OutboundHttp::class);
    $template = new FetchRequest('http://fixtures.test', maxWireBytes: 4096, maxDecodedBytes: 4096, redirects: RedirectPolicy::Untrusted, siteHost: 'fixtures.test');
    $config = $http->clientConfig($template, pacedHttpContext(), $selectTimeout);
    expect($config['handler'])->toBeInstanceOf(HandlerStack::class);
    $stack = (string) $config['handler'];
    expect($stack)->toContain('outbound_policy', 'private_target_guard', 'bounded_transfer');
    $client = new Client($config);
    expect($client->get('http://fixtures.test/status/200')->getStatusCode())->toBe(200);
    expect(fn (): ResponseInterface => $client->get('http://fixtures.test/http-seam/private', ['allow_redirects' => true, 'curl' => [CURLOPT_RESOLVE => ['127.0.0.1:80:8.8.8.8']]]))
        ->toThrow(FetchException::class, 'private_target');
    $tlsTemplate = new FetchRequest('https://self-signed.fixtures.test/status/200', redirects: RedirectPolicy::Untrusted, siteHost: 'self-signed.fixtures.test');
    $tlsClient = new Client($http->clientConfig($tlsTemplate, pacedHttpContext(), $selectTimeout));
    expect(fn (): ResponseInterface => $tlsClient->get($tlsTemplate->url, ['verify' => false, 'curl' => [CURLOPT_SSL_VERIFYPEER => false]]))
        ->toThrow(FetchException::class, 'tls');
    expect(fn (): ResponseInterface => $client->get('http://fixtures.test/http-seam/large/200', ['sink' => '/dev/null', 'decode_content' => false, 'stream' => true]))
        ->toThrow(FetchException::class, 'too_large');
    expect(fn (): ResponseInterface => $client->get('http://fixtures.test/status/200', ['delay' => 1]))
        ->toThrow(InvalidArgumentException::class, 'does not support delayed dispatch');
})->with(['ordinary-default' => [null], 'sitemap-scoped' => [0.0]]);

it('keeps a full request-ceiling timeout as an observed failure instead of a budget-null', function (): void {
    $client = app(OutboundHttp::class)->pacedClient(pacedHttpContext());
    $result = $client->fetch(new FetchRequest('http://fixtures.test/http-seam/drip', timeoutSeconds: 0.2));

    expect($result)->not->toBeNull()->and($result->failure?->kind)->toBe(FetchFailureKind::Timeout);
});

it('does not turn unexpected resolver errors into withheld observations', function (): void {
    ClosedGuardedHttp::run([], new MockHandler, function (): void {
        $client = app(OutboundHttp::class)->pacedClient(pacedHttpContext());

        expect(fn () => $client->fetch(new FetchRequest('http://unconfigured.test/', redirects: RedirectPolicy::Untrusted, siteHost: 'fixtures.test')))
            ->toThrow(LogicException::class, 'Unexpected target lookup');
    });
});

it('keeps cross-origin header stripping in the paced operator redirect stack', function (): void {
    $transport = new MockHandler([new Response(302, ['Location' => 'http://external.fixtures.test/headers']), new Response(200)]);
    ClosedGuardedHttp::run(['fixtures.test' => [['8.8.8.8']], 'external.fixtures.test' => [['1.1.1.1']]], $transport, function () use ($transport): void {
        $result = app(OutboundHttp::class)->pacedClient(pacedHttpContext())->fetch(new FetchRequest('http://fixtures.test/http-seam/cross-host', headers: [
            'Authorization' => 'Bearer planted-fixture-secret', 'Cookie' => 'planted-fixture-secret', 'X-Custom-Secret' => 'planted-fixture-secret', 'User-Agent' => 'planted-fixture-secret',
        ], redirects: RedirectPolicy::Operator));

        expect($result)->not->toBeNull()->and($result->failure)->toBeNull();
        $headers = array_map(static fn (array $values): string => implode(', ', $values), array_change_key_case($transport->getLastRequest()->getHeaders()));
        expect(Message::toString($transport->getLastRequest()))->not->toContain('planted-fixture-secret')
            ->and($headers)->not->toHaveKeys(['authorization', 'cookie', 'x-custom-secret'])
            ->and($headers['user-agent'])->toBe('HeptapodBot/'.config()->string('heptapod.version').' (+https://heptapod.watch/bot)');
    });
});

it('keeps dispatch spacing after native enqueue and admits only at the full interval', function (int $enqueueMicroseconds, int $elapsedMicroseconds): void {
    // Freeze the current instant because bounded-transfer still compares deadlines against raw microtime.
    $this->freezeSecond();
    Sleep::fake(syncWithCarbon: true);
    $dispatches = [];
    $native = new MockHandler([new Response(200), new Response(200)]);
    $transport = function (RequestInterface $request, array $options) use ($native, $enqueueMicroseconds, &$dispatches): PromiseInterface {
        if ($dispatches === []) {
            $this->travelTo(CarbonImmutable::now()->addMicroseconds($enqueueMicroseconds));
        }
        $dispatches[] = CarbonImmutable::now()->getPreciseTimestamp();

        return $native($request, $options);
    };

    ClosedGuardedHttp::run(['fixtures.test' => [['8.8.8.8']]], $transport, function () use ($elapsedMicroseconds, &$dispatches): void {
        $client = app(OutboundHttp::class)->pacedClient(pacedHttpContext());
        $request = new FetchRequest('http://fixtures.test/status/200', method: 'HEAD');
        expect($client->fetch($request)?->status)->toBe(200);
        $this->travelTo(CarbonImmutable::now()->addMicroseconds($elapsedMicroseconds));

        expect($client->fetch($request)?->status)->toBe(200)
            ->and($dispatches)->toHaveCount(2)
            ->and($dispatches[1] - $dispatches[0])->toBe(110000.0);
        if ($elapsedMicroseconds === 110000) {
            Sleep::assertNeverSlept();
        } else {
            Sleep::assertSequence([Sleep::for((110000 - $elapsedMicroseconds) / 1000)->milliseconds()]);
        }
    });
})->with([
    'native-enqueue-delay' => [23400, 0],
    'submillisecond-wait' => [0, 109600],
    'exact-interval' => [0, 110000],
]);

it('keeps native dispatch spacing after a slow guard lookup and withholds dispatch when usable budget expires', function (int $delay, float $budget, int $starts): void {
    $nativeResolver = app(TargetResolver::class);
    app()->instance(TargetResolver::class, new DelayedTargetResolver($nativeResolver, 'fixtures.test', $delay));
    $token = 'guard-delay-'.bin2hex(random_bytes(8));
    $requests = [
        new FetchRequest('http://fixtures.test/sitemap-production/latency/'.$token.'/page/1', method: 'HEAD', redirects: RedirectPolicy::Untrusted, siteHost: 'fixtures.test'),
        new FetchRequest('http://fixtures.test/sitemap-production/latency/'.$token.'/page/2', method: 'HEAD', redirects: RedirectPolicy::Untrusted, siteHost: 'fixtures.test'),
    ];
    $http = new RecordingOutboundHttp(app(OutboundHttp::class));
    $context = pacedHttpContext($budget);
    $results = $http->pacedClient($context)->fetchMany($requests);
    usleep(700000);
    $reply = $http->fetch(new FetchRequest('http://fixtures.test/sitemap-production/evidence/'.$token, redirects: RedirectPolicy::Untrusted, siteHost: 'fixtures.test'), pacedHttpContext());
    expect($reply->failure)->toBeNull();
    $fixture = json_decode($reply->body, true, flags: JSON_THROW_ON_ERROR);
    if (file_put_contents(storage_path('framework/testing/guard-delay-'.$delay.'.json'), json_encode($fixture, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)) === false) {
        throw new RuntimeException('Cannot persist delayed-guard arrivals.');
    }

    expect($fixture['events'])->toHaveCount($starts)->and($fixture['peak'])->toBeLessThanOrEqual(4);
    if ($starts === 0) {
        expect($results)->toBe([null, null]);
    } else {
        $dispatches = array_column($http->transfers, 'dispatched_epoch');
        sort($dispatches, SORT_NUMERIC);
        expect($dispatches)->toHaveCount(2)
            ->and($dispatches[1] - $dispatches[0])->toBeGreaterThanOrEqual(0.110);
        foreach ($results as $result) {
            expect($result)->not->toBeNull()->and($result->failure)->toBeNull()->and($result->status)->toBe(200);
        }
    }
})->with(['spacing-after-lookup' => [250000, 5.0, 2], 'budget-exhausted-in-guard' => [400000, 1.75, 0]]);

it('retains the first admission ceiling across every redirect hop', function (): void {
    $started = microtime(true);
    $result = app(OutboundHttp::class)->pacedClient(pacedHttpContext())->fetch(new FetchRequest('http://fixtures.test/http-seam/slow-redirect/1', redirects: RedirectPolicy::Operator, timeoutSeconds: 1.0));

    expect($result)->not->toBeNull()->and($result->failure?->kind)->toBe(FetchFailureKind::Timeout)
        ->and($result->transfers)->toHaveCount(1)
        ->and(microtime(true) - $started)->toBeLessThan(1.2);
});

it('starts each redirect ceiling at actual admission rather than while waiting behind earlier requests', function (): void {
    $token = 'late-redirect-'.bin2hex(random_bytes(8));
    $url = 'http://fixtures.test/sitemap-production/redirectdoc/'.$token.'/document/0';
    $requests = array_fill(0, 12, new FetchRequest($url, redirects: RedirectPolicy::Untrusted, siteHost: 'fixtures.test', timeoutSeconds: 0.5, acceptedStatuses: [200]));
    $results = app(OutboundHttp::class)->pacedClient(pacedHttpContext(15), 100)->fetchMany($requests);

    expect($results)->toHaveCount(12);
    foreach ($results as $result) {
        expect($result)->not->toBeNull()->and($result->failure)->toBeNull()->and($result->status)->toBe(200);
    }
});

it('rejects nonfinite or negative select timeouts before transport construction', function (float $value): void {
    expect(fn () => app(OutboundHttp::class)->clientConfig(new FetchRequest('http://fixtures.test'), pacedHttpContext(), $value))
        ->toThrow(InvalidArgumentException::class, 'finite and nonnegative');
    expect(fn () => app(PrivateTargetGuard::class)->clientConfig('fixtures.test', selectTimeoutSeconds: $value))
        ->toThrow(InvalidArgumentException::class, 'finite and nonnegative');
})->with(['negative' => [-1.0], 'infinite' => [INF], 'nan' => [NAN]]);
