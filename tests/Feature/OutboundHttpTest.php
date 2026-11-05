<?php

use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Middleware;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Message;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\TransferStats;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\DnsResolver;
use Heptapod\Contracts\Http\FetchException;
use Heptapod\Contracts\Http\FetchFailure;
use Heptapod\Contracts\Http\FetchFailureKind;
use Heptapod\Contracts\Http\FetchRequest;
use Heptapod\Contracts\Http\FetchResult;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Contracts\Http\PrivateTargetGuard;
use Heptapod\Contracts\Http\PublicRequestProfile;
use Heptapod\Contracts\Http\RedirectPolicy;
use Heptapod\Contracts\Http\TargetResolutionException;
use Heptapod\Contracts\Http\TargetResolver;
use Heptapod\Http\Outbound\NativeTargetResolver;
use Heptapod\Testing\FakeOutboundHttp;
use Heptapod\Testing\FakeTargetResolver;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Process\PhpExecutableFinder;
use Tests\Support\ClosedGuardedHttp;

it('keeps the original closed HTTP exception even when its unused-stack assertion would fail', function (): void {
    $original = new RuntimeException('Original operation failed before creating an HTTP stack.');
    $caught = null;

    try {
        ClosedGuardedHttp::run([], new MockHandler, static function () use ($original): never {
            throw $original;
        });
    } catch (Throwable $error) {
        $caught = $error;
    }

    expect($caught)->toBe($original);
});

it('resets closed HTTP bindings and prior instances after success or assertion failure', function (bool $failAssertion): void {
    $originalResolver = new FakeTargetResolver;
    app()->instance(TargetResolver::class, $originalResolver);
    $bindings = app()->getBindings();
    $caught = null;
    try {
        ClosedGuardedHttp::run(['closed.test' => [['8.8.8.8']]], new MockHandler([new Response(200, [], 'closed response')]), function () use ($failAssertion): void {
            $request = new FetchRequest('http://closed.test/', redirects: RedirectPolicy::Untrusted, siteHost: 'site.test');
            if ($failAssertion) {
                app(OutboundHttp::class)->clientConfig($request, outboundContext());
            } else {
                expect(app(OutboundHttp::class)->fetch($request, outboundContext())->body)->toBe('closed response');
            }
        });
    } catch (Throwable $error) {
        $caught = $error;
    }

    if ($failAssertion) {
        expect($caught?->getMessage())->toBe('Failed asserting that two arrays are identical.');
    } else {
        expect($caught)->toBeNull();
    }
    foreach ([NativeTargetResolver::class, DnsResolver::class, TargetResolver::class, PrivateTargetGuard::class] as $abstract) {
        expect(app()->getBindings()[$abstract] ?? null)->toBe($bindings[$abstract] ?? null);
    }
    expect(app(TargetResolver::class))->toBe($originalResolver)
        ->and(app(NativeTargetResolver::class))->toBeInstanceOf(NativeTargetResolver::class)
        ->and(app(DnsResolver::class))->toBeInstanceOf(DnsResolver::class);
})->with(['success' => false, 'assertion failure' => true]);

it('isolates statuses, byte bounds and target pins across one concurrent HTTP client', function (): void {
    $ip = app(TargetResolver::class)->resolve('fixtures.test')[0];
    $context = outboundContext(15);
    $request = new FetchRequest('http://fixtures.test/http-seam/slow-not-found', maxWireBytes: 1024, maxDecodedBytes: 1024, redirects: RedirectPolicy::Untrusted, maxRedirects: 1, siteHost: 'fixtures.test');
    $config = app(OutboundHttp::class)->clientConfig($request, $context);
    $history = [];
    $config['handler']->push(Middleware::history($history));
    $client = new Client($config);
    $urls = [
        'slow-404' => 'http://fixtures.test/http-seam/slow-not-found',
        'large' => 'http://fixtures.test/http-seam/large/200',
        'healthy' => 'http://fixtures.test/status/200',
        'site-headers' => 'http://fixtures.test/http-seam/headers',
        'redirect-limit' => 'http://fixtures.test/redirect/start',
        'redirect-private' => 'http://fixtures.test/http-seam/private',
    ];
    $results = [];
    $hostHeaders = [];
    $connected = [];
    $requests = (static function () use ($urls): Generator {
        foreach ($urls as $name => $url) {
            yield $name => new Request('GET', $url);
        }
    })();
    new Pool($client, $requests, [
        'concurrency' => 8,
        'options' => ['on_stats' => static function (TransferStats $stats) use (&$connected): void {
            if ($stats->hasResponse()) {
                $connected[(string) $stats->getEffectiveUri()] = $stats->getHandlerStats()['primary_ip'] ?? null;
            }
        }],
        'fulfilled' => static function (ResponseInterface $response, string $name) use (&$results, &$hostHeaders): void {
            $results[$name] = $response->getStatusCode();
            if ($name === 'site-headers') {
                $hostHeaders[$name] = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['host'];
            }
            $response->getBody()->close();
        },
        'rejected' => static function (mixed $error, string $name) use (&$results): void {
            $results[$name] = $error instanceof FetchException ? $error->failure->kind : $error;
        },
    ])->promise()->wait();

    expect($results)->toEqual([
        'slow-404' => 404,
        'large' => FetchFailureKind::TooLarge,
        'healthy' => 200,
        'site-headers' => 200,
        'redirect-limit' => FetchFailureKind::TooManyRedirects,
        'redirect-private' => FetchFailureKind::PrivateTarget,
    ]);
    expect($hostHeaders)->toEqual(['site-headers' => 'fixtures.test']);
    expect($connected[$urls['slow-404']])->toBe($ip)
        ->and($connected[$urls['healthy']])->toBe($ip);
    foreach ($history as $entry) {
        $host = $entry['request']->getUri()->getHost();
        expect($host)->toBe('fixtures.test')
            ->and($entry['options']['curl'][CURLOPT_RESOLVE])->toBe([$host.':80:'.$ip]);
    }
    expect($history)->toHaveCount(7)
        ->and($client->get('http://fixtures.test/status/200')->getStatusCode())->toBe(200);
});

it('isolates public pins and metadata refusals across pooled requests', function (): void {
    $transport = new MockHandler([new Response(200), new Response(200), new Response(200), new Response(200)]);
    ClosedGuardedHttp::run([
        'fixtures.test' => [['8.8.8.8']],
        'external.fixtures.test' => [['1.1.1.1']],
        'second.fixtures.test' => [['9.9.9.9']],
        'blocked.test' => [['169.254.169.254']],
    ], $transport, function () use ($transport): void {
        $config = app(OutboundHttp::class)->clientConfig(new FetchRequest('http://fixtures.test', redirects: RedirectPolicy::Untrusted, siteHost: 'fixtures.test'), outboundContext());
        $history = [];
        $config['handler']->push(Middleware::history($history));
        $client = new Client($config);
        $results = [];
        new Pool($client, array_map(static fn (string $host): Request => new Request('GET', 'http://'.$host.'/status/200'), ['fixtures.test', 'external.fixtures.test', 'second.fixtures.test', 'blocked.test']), [
            'concurrency' => 4,
            'fulfilled' => static function (ResponseInterface $response, int $index) use (&$results): void {
                $results[$index] = $response->getStatusCode();
            },
            'rejected' => static function (mixed $reason, int $index) use (&$results): void {
                $results[$index] = $reason;
            },
        ])->promise()->wait();
        expect($results[0])->toBe(200)->and($results[1])->toBe(200)->and($results[2])->toBe(200)
            ->and($results[3])->toBeInstanceOf(FetchException::class)
            ->and($results[3]->failure)->toEqual(new FetchFailure(FetchFailureKind::PrivateTarget, targetAddress: '169.254.169.254', targetClass: 'cloud_metadata'))
            ->and($results[3]->getMessage())->toBe('Outbound HTTP failed: private_target.');
        $pins = [];
        foreach ($history as $entry) {
            $host = $entry['request']->getUri()->getHost();
            $pins[$host] = $entry['options']['curl'][CURLOPT_RESOLVE];
            expect($entry['request']->getHeaderLine('Host'))->toBe($host);
        }
        expect($pins)->toEqual([
            'fixtures.test' => ['fixtures.test:80:8.8.8.8'],
            'external.fixtures.test' => ['external.fixtures.test:80:1.1.1.1'],
            'second.fixtures.test' => ['second.fixtures.test:80:9.9.9.9'],
        ])->and($transport->count())->toBe(1);
    });
});

it('refuses a private cross-host redirect before dispatch in ordinary and paced sessions', function (bool $paced): void {
    $transport = new MockHandler([new Response(302, ['Location' => 'http://external.fixtures.test/headers']), new Response(200)]);
    ClosedGuardedHttp::run(['fixtures.test' => [['127.0.0.1']], 'external.fixtures.test' => [['127.0.0.2']]], $transport, function () use ($paced, $transport): void {
        $request = new FetchRequest('http://fixtures.test/cross-host', headers: ['Authorization' => 'Bearer private-token'], redirects: RedirectPolicy::Operator);
        $http = app(OutboundHttp::class);
        $context = outboundContext();
        $result = $paced ? $http->pacedClient($context)->fetch($request) : $http->fetch($request, $context);
        expect($result)->not->toBeNull()
            ->and($result->failure)->toEqual(new FetchFailure(FetchFailureKind::PrivateTarget, targetAddress: '127.0.0.2', targetClass: 'private_network', redirected: true))
            ->and($result->failure?->detail())->toBe('Outbound HTTP failed: private_target.')
            ->and($result->transfers)->toHaveCount(1)
            ->and($result->body)->toBe('')
            ->and($transport->count())->toBe(1);
    });
})->with(['ordinary' => [false], 'paced' => [true]]);

it('runs pooled outbound requests at the configured concurrency', function (): void {
    $token = (string) Str::uuid();
    $http = app(OutboundHttp::class);
    $context = outboundContext(20);
    $client = new Client($http->clientConfig(new FetchRequest('http://fixtures.test/http-seam/overlap/'.$token.'/1', redirects: RedirectPolicy::Untrusted, siteHost: 'fixtures.test', timeoutSeconds: 15), $context));
    $results = [];
    $requests = (static function () use ($token): Generator {
        foreach (range(1, 5) as $index) {
            yield $index => new Request('GET', 'http://fixtures.test/http-seam/overlap/'.$token.'/'.$index);
        }
    })();
    new Pool($client, $requests, [
        'concurrency' => 5,
        'fulfilled' => static function (ResponseInterface $response, int $index) use (&$results): void {
            $results[$index] = $response->getStatusCode();
            $response->getBody()->close();
        },
        'rejected' => static function (mixed $error, int $index) use (&$results): void {
            $results[$index] = $error;
        },
    ])->promise()->wait();
    $peak = $http->fetch(new FetchRequest('http://fixtures.test/http-seam/overlap-peak/'.$token), outboundContext());
    ksort($results);

    expect((int) trim($peak->body))->toBe(5)
        ->and($results)->toBe([1 => 200, 2 => 200, 3 => 200, 4 => 200, 5 => 200]);
});

it('settles each overflow callback on its own transfer without poisoning the next request', function (string $path, int $wireLimit, int $decodedLimit): void {
    $http = app(OutboundHttp::class);
    $client = new Client($http->clientConfig(new FetchRequest('http://fixtures.test/http-seam/'.$path, maxWireBytes: $wireLimit, maxDecodedBytes: $decodedLimit), outboundContext()));
    $results = [];
    new Pool($client, [
        'overflow' => new Request('GET', 'http://fixtures.test/http-seam/'.$path),
        'not-found' => new Request('GET', 'http://fixtures.test/status/404'),
    ], [
        'concurrency' => 2,
        'fulfilled' => static function (ResponseInterface $response, string $name) use (&$results): void {
            $results[$name] = $response->getStatusCode();
            $response->getBody()->close();
        },
        'rejected' => static function (mixed $reason, string $name) use (&$results): void {
            $results[$name] = $reason;
        },
    ])->promise()->wait();

    expect($results['overflow'])->toBeInstanceOf(FetchException::class)
        ->and($results['overflow']->failure->kind)->toBe(FetchFailureKind::TooLarge)
        ->and($results['overflow']->failure->status)->toBe(200)
        ->and($results['not-found'])->toBe(404)
        ->and($client->get('http://fixtures.test/status/200')->getStatusCode())->toBe(200);
})->with([
    'Content-Length in on_headers' => ['large/200', 1024, 128 * 1024],
    'decoded sink short write' => ['compressed/gzip/200', 64 * 1024, 1024],
    'chunked wire progress' => ['wire-stream', 64 * 1024, 128 * 1024],
]);

it('attributes a throwing on_stats observer to its transfer without interrupting siblings', function (): void {
    $client = new Client(app(OutboundHttp::class)->clientConfig(new FetchRequest('http://fixtures.test/status/503'), outboundContext()));
    $results = [];
    new Pool($client, [
        'observer' => new Request('GET', 'http://fixtures.test/status/503'),
        'healthy' => new Request('GET', 'http://fixtures.test/status/200'),
    ], [
        'concurrency' => 2,
        'options' => ['on_stats' => static function (TransferStats $stats): void {
            if ($stats->getEffectiveUri()->getPath() === '/status/503') {
                throw new RuntimeException('observer failed');
            }
        }],
        'fulfilled' => static function (ResponseInterface $response, string $name) use (&$results): void {
            $results[$name] = $response->getStatusCode();
            $response->getBody()->close();
        },
        'rejected' => static function (mixed $reason, string $name) use (&$results): void {
            $results[$name] = $reason;
        },
    ])->promise()->wait();

    expect($results['observer'])->toBeInstanceOf(RuntimeException::class)
        ->and($results['observer']->getMessage())->toBe('observer failed')
        ->and($results['healthy'])->toBe(200)
        ->and($client->get('http://fixtures.test/status/200')->getStatusCode())->toBe(200);
});

function outboundContext(float $seconds = 10): CheckContext
{
    $now = CarbonImmutable::now();

    return new CheckContext(1, 1, 1, 'token', 'http://fixtures.test', $now, $now->addMilliseconds((int) ($seconds * 1000)), [], [], null);
}

it('aborts huge decoded success and error bodies while streaming', function (string $path, int $status): void {
    $result = app(OutboundHttp::class)->fetch(new FetchRequest('http://fixtures.test/http-seam/'.$path.'/'.$status), outboundContext());

    expect($result->failure?->kind)->toBe(FetchFailureKind::TooLarge)
        ->and($result->status)->toBe($status)
        ->and($result->body)->toBe('');
})->with(['large', 'compressed/gzip', 'compressed/deflate', 'compressed/br'])->with([200, 503]);

it('accepts a decoded body larger than the wire limit when both limits are respected', function (): void {
    $result = app(OutboundHttp::class)->fetch(new FetchRequest('http://fixtures.test/http-seam/compressed/gzip/200', maxWireBytes: 4096, maxDecodedBytes: 3 * 1024 * 1024), outboundContext());

    expect($result->failure)->toBeNull()
        ->and(strlen($result->body))->toBe(2 * 1024 * 1024);
});

it('bounds wire bytes independently of the decoded byte limit', function (string $path): void {
    $result = app(OutboundHttp::class)->fetch(new FetchRequest('http://fixtures.test/http-seam/'.$path.'/200', maxWireBytes: 8, maxDecodedBytes: 4 * 1024 * 1024), outboundContext());

    expect($result->failure?->kind)->toBe(FetchFailureKind::TooLarge);
})->with(['large', 'compressed/gzip', 'compressed/deflate', 'compressed/br']);

it('fails loudly when the configured PHP CLI for bounded DNS is unavailable', function (): void {
    $previous = getenv('PHP_BINARY');
    putenv('PHP_BINARY=/missing-php-cli');
    try {
        expect(fn (): FetchResult => app(OutboundHttp::class)->fetch(new FetchRequest('http://fixtures.test/status/200', redirects: RedirectPolicy::Untrusted, publicTargetsOnly: true), outboundContext()))
            ->toThrow(RuntimeException::class, 'Target resolver requires an executable PHP CLI binary.');
    } finally {
        putenv($previous === false ? 'PHP_BINARY' : 'PHP_BINARY='.$previous);
    }
});

it('does not start a native DNS lookup after its budget expires', function (): void {
    $start = microtime(true);
    expect(fn (): array => (new NativeTargetResolver)->resolve('fixtures.test', $start - 1))
        ->toThrow(FetchException::class);
    expect(microtime(true) - $start)->toBeLessThan(0.5);
});

it('kills the bounded DNS subprocess when its positive budget expires', function (): void {
    $previous = getenv('PHP_BINARY');
    $previousPidPath = getenv('HEPTAPOD_DNS_TEST_PID');
    $previousServerPidPath = $_SERVER['HEPTAPOD_DNS_TEST_PID'] ?? null;
    $pidPath = tempnam(sys_get_temp_dir(), 'dns-budget-');
    putenv('PHP_BINARY='.base_path('tests/Fixtures/slow-php-cli'));
    putenv('HEPTAPOD_DNS_TEST_PID='.$pidPath);
    $_SERVER['HEPTAPOD_DNS_TEST_PID'] = $pidPath;
    try {
        expect((new PhpExecutableFinder)->find(false))->toBe(base_path('tests/Fixtures/slow-php-cli'));
        $started = microtime(true);
        $error = observeOutboundFailure(fn (): array => (new NativeTargetResolver)->resolve('fixtures.test', $started + 0.2));
        expect($error)->toBeInstanceOf(FetchException::class)
            ->and($error->failure)->toEqual(new FetchFailure(FetchFailureKind::Timeout))
            ->and($error->getMessage())->toBe('Outbound HTTP failed: timeout.')
            ->and(microtime(true) - $started)->toBeLessThan(2);
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

it('uses one remaining deadline across a chunked slow drip', function (): void {
    // The drip sends 20 chunks over 2 s. A 1 s budget leaves headers a wide margin on a loaded runner and still expires mid-body.
    $result = app(OutboundHttp::class)->fetch(new FetchRequest('http://fixtures.test/http-seam/drip', timeoutSeconds: 5), outboundContext(1.0));

    expect($result->failure?->kind)->toBe(FetchFailureKind::Timeout)
        ->and($result->status)->toBe(200)
        ->and($result->body)->toBe('');
});

it('does not dispatch after the check budget expires', function (): void {
    $result = app(OutboundHttp::class)->fetch(new FetchRequest('http://fixtures.test/status/200'), outboundContext(-1));

    expect($result->failure?->kind)->toBe(FetchFailureKind::Timeout)
        ->and($result->transfers)->toBe([]);
});

it('keeps redirects off by default and follows operator redirects without the private guard', function (): void {
    $http = app(OutboundHttp::class);
    $off = $http->fetch(new FetchRequest('http://fixtures.test/redirect/start'), outboundContext());
    $operator = $http->fetch(new FetchRequest('http://fixtures.test/redirect/start', redirects: RedirectPolicy::Operator, maxRedirects: 2), outboundContext());
    $limited = $http->fetch(new FetchRequest('http://fixtures.test/redirect/start', redirects: RedirectPolicy::Operator, maxRedirects: 1), outboundContext());
    $loop = $http->fetch(new FetchRequest('http://fixtures.test/http-seam/loop', redirects: RedirectPolicy::Operator, maxRedirects: 2), outboundContext());

    expect($off->status)->toBe(301)
        ->and($off->failure?->kind)->toBe(FetchFailureKind::HttpStatus)
        ->and($off->body)->toBe('')
        ->and($off->headers)->toBe([])
        ->and($operator->status)->toBe(200)
        ->and($operator->failure)->toBeNull()
        ->and($operator->transfers)->toHaveCount(3)
        ->and($limited->failure?->kind->value)->toBe('too_many_redirects')
        ->and($loop->failure?->kind->value)->toBe('too_many_redirects');
});

it('rejects redirect protocols without exposing the destination', function (): void {
    $result = app(OutboundHttp::class)->fetch(new FetchRequest('http://fixtures.test/http-seam/protocol', redirects: RedirectPolicy::Operator), outboundContext());

    expect($result->failure?->kind)->toBe(FetchFailureKind::RedirectBlocked)
        ->and($result->failure?->detail())->toBe('Outbound HTTP failed: redirect_blocked (HTTP 302).')
        ->not->toContain('private-token', 'fixtures.test')
        ->and($result->transfers)->toHaveCount(1);
});

it('names credential and scheme refusals without exposing the destination', function (string $class, bool $redirected, string $english, string $german): void {
    $secret = hash('sha256', 'FX3 credential sentinel');
    $destination = $class === 'credentials' ? 'https://user:'.$secret.'@fixtures.test/private' : 'ftp://fixtures.test/private';
    $transport = new MockHandler($redirected ? [new Response(302, ['Location' => $destination]), new Response(200)] : [new Response(200)]);
    $logs = [];
    Log::listen(static function (MessageLogged $event) use (&$logs): void {
        $logs[] = [$event->message, $event->context];
    });
    ClosedGuardedHttp::run($redirected ? ['fixtures.test' => [['8.8.8.8']]] : [], $transport, function () use ($destination, $class, $redirected, $english, $german, $secret, $transport, &$logs): void {
        $context = outboundContext();
        $request = new FetchRequest('https://fixtures.test/start', redirects: RedirectPolicy::Operator);
        $http = app(OutboundHttp::class);
        if ($redirected) {
            $result = $http->fetch($request, $context);
            $failure = $result->failure;
            expect($result->body)->toBe('')->and($result->transfers)->toHaveCount(1);
        } else {
            $config = $http->clientConfig($request, $context);
            if ($class === 'credentials') {
                $earlier = observeOutboundFailure(fn (): ResponseInterface => new Client($config)->get($destination));
                expect($earlier)->toBeInstanceOf(FetchException::class)
                    ->and($earlier->failure)->toEqual(new FetchFailure(FetchFailureKind::InvalidRequest, exceptionClass: 'InvalidArgumentException', headerName: 'Host', headerPosition: 3))
                    ->and($earlier->getMessage())->toBe('Outbound HTTP failed: invalid_request.')
                    ->and($transport->count())->toBe(1);
                $this->assertFalse(str_contains(json_encode([$earlier->failure, $earlier->getMessage()], JSON_THROW_ON_ERROR), $secret), 'Earlier refusal must not expose credentials.');
                $config = app(PrivateTargetGuard::class)->clientConfig('fixtures.test');
            }
            $client = new Client($config);
            $result = observeOutboundFailure(fn (): ResponseInterface => $client->get($destination));
            expect($result)->toBeInstanceOf(FetchException::class);
            $failure = $result->failure;
        }
        $summary = $failure?->policySummary();
        $messages = array_map(static fn (string $locale): string => __($summary['key'], $summary['params'], $locale), ['en', 'de']);
        $this->assertFalse(str_contains(json_encode([$result, $failure, $failure?->detail(), $result instanceof Throwable ? $result->getMessage() : null, $summary, $messages, $logs, $context->policyRefusals], JSON_THROW_ON_ERROR), $secret), 'Credential sentinel must not appear in diagnostics or logs.');

        expect($summary)->toBe(['key' => 'checks.policy.'.($redirected ? 'redirect_' : '').$class, 'params' => []]);
        expect($messages)->toBe([$english, $german]);
        expect($failure)->toEqual(new FetchFailure(FetchFailureKind::PrivateTarget, targetClass: $class, redirected: $redirected))
            ->and($failure?->isPolicyRefusal())->toBeTrue()
            ->and($failure?->detail())->toBe('Outbound HTTP failed: private_target.')
            ->and($transport->count())->toBe(1);
    });
})->with([
    'credentials on start URL' => ['credentials', false, 'Could not check: the address contains embedded credentials, which is not allowed.', 'Prüfung nicht möglich: Die Adresse enthält eingebettete Zugangsdaten. Dies ist nicht erlaubt.'],
    'credentials on redirect' => ['credentials', true, 'Could not check: the site redirects to an address with embedded credentials, which is not allowed.', 'Prüfung nicht möglich: Die Website leitet auf eine Adresse mit eingebetteten Zugangsdaten weiter. Dies ist nicht erlaubt.'],
    'unsupported scheme on start URL' => ['unsupported_scheme', false, 'Could not check: the address uses an unsupported protocol. Only HTTP and HTTPS are allowed.', 'Prüfung nicht möglich: Die Adresse verwendet ein nicht unterstütztes Protokoll. Nur HTTP und HTTPS sind erlaubt.'],
]);

it('blocks untrusted private targets and private redirect hops', function (string $url): void {
    $result = app(OutboundHttp::class)->fetch(new FetchRequest($url, redirects: RedirectPolicy::Untrusted, siteHost: 'fixtures.test'), outboundContext());

    expect($result->failure?->kind)->toBe(FetchFailureKind::PrivateTarget)
        ->and($result->body)->toBe('');
})->with(['http://127.0.0.1/private', 'http://fixtures.test/http-seam/private']);

it('pins a redirect host once per context and refuses its changed private answer in a new context', function (): void {
    $transport = new MockHandler([
        new Response(302, ['Location' => 'http://rebound.test/first']),
        new Response(302, ['Location' => 'http://rebound.test/final']),
        new Response(200), new Response(200), new Response(200),
    ]);
    ClosedGuardedHttp::run(['fixtures.test' => [['127.0.0.1']], 'rebound.test' => [['8.8.8.8'], ['127.0.0.1']]], $transport, function (TargetResolver $resolver) use ($transport): void {
        $context = outboundContext();
        $template = new FetchRequest('http://fixtures.test/start', redirects: RedirectPolicy::Untrusted, siteHost: 'fixtures.test');
        $config = app(OutboundHttp::class)->clientConfig($template, $context);
        $history = [];
        $config['handler']->push(Middleware::history($history));
        $client = new Client($config);
        expect($client->get($template->url)->getStatusCode())->toBe(200)
            ->and($client->get('http://rebound.test/repeat')->getStatusCode())->toBe(200)
            ->and($resolver->lookups)->toBe(['fixtures.test', 'rebound.test']);
        expect($history)->toHaveCount(4);
        foreach ($history as $entry) {
            $host = $entry['request']->getUri()->getHost();
            expect($entry['options']['curl'][CURLOPT_RESOLVE])->toBe([$host.':80:'.($host === 'fixtures.test' ? '127.0.0.1' : '8.8.8.8')]);
        }
        $result = app(OutboundHttp::class)->fetch(new FetchRequest('http://rebound.test/new-context', redirects: RedirectPolicy::Untrusted, siteHost: 'fixtures.test'), outboundContext());
        expect($result->failure)->toEqual(new FetchFailure(FetchFailureKind::PrivateTarget, targetAddress: '127.0.0.1', targetClass: 'private_network'))
            ->and($result->failure?->detail())->toBe('Outbound HTTP failed: private_target.')
            ->and($result->transfers)->toBe([])
            ->and($resolver->lookups)->toBe(['fixtures.test', 'rebound.test', 'rebound.test'])
            ->and($transport->count())->toBe(1);
    });
});

it('classifies guard DNS failures without swallowing unexpected resolver errors', function (): void {
    ClosedGuardedHttp::run(['not-present.invalid' => new TargetResolutionException('Target DNS lookup failed.')], new MockHandler, function (): void {
        $result = app(OutboundHttp::class)->fetch(new FetchRequest('http://not-present.invalid/', redirects: RedirectPolicy::Untrusted, siteHost: 'fixtures.test'), outboundContext());
        expect($result->failure?->kind)->toBe(FetchFailureKind::Dns);
        expect(fn (): FetchResult => app(OutboundHttp::class)->fetch(new FetchRequest('http://unconfigured.test/', redirects: RedirectPolicy::Untrusted, siteHost: 'fixtures.test'), outboundContext()))->toThrow(LogicException::class, 'Unexpected target lookup');
    });
});

it('strips all caller supplied headers including secret User-Agent on a cross-host redirect', function (): void {
    $transport = new MockHandler([new Response(302, ['Location' => 'http://external.fixtures.test/headers']), new Response(200)]);
    ClosedGuardedHttp::run(['fixtures.test' => [['8.8.8.8']], 'external.fixtures.test' => [['1.1.1.1']]], $transport, function () use ($transport): void {
        $result = app(OutboundHttp::class)->fetch(new FetchRequest('http://fixtures.test/http-seam/cross-host', headers: [
            'Authorization' => 'Bearer private-token', 'X-Custom-Secret' => 'secret', 'Cookie' => 'session=secret', 'Accept' => 'application/json', 'User-Agent' => 'private-token',
        ], redirects: RedirectPolicy::Operator), outboundContext());
        $headers = array_map(static fn (array $values): string => implode(', ', $values), array_change_key_case($transport->getLastRequest()->getHeaders()));

        expect($result->failure)->toBeNull()
            ->and(Message::toString($transport->getLastRequest()))->not->toContain('private-token', 'secret')
            ->and($headers)->not->toHaveKeys(['authorization', 'x-custom-secret', 'cookie'])
            ->and($headers['accept'])->toBe('text/html,application/xhtml+xml;q=0.9,*/*;q=0.8')
            ->and($headers['user-agent'])->toBe('HeptapodBot/'.config()->string('heptapod.version').' (+https://heptapod.watch/bot)');
    });
});

it('keeps site headers only on the normalised site origin for initial library dispatches', function (string $url, bool $sameOrigin): void {
    $host = parse_url($url, PHP_URL_HOST);
    $transport = new MockHandler([new Response(200)]);
    ClosedGuardedHttp::run([$host => [['8.8.8.8']]], $transport, function () use ($url, $sameOrigin, $transport): void {
        $context = outboundContext();
        $context = new CheckContext(1, 1, 1, 'token', 'http://FIXTURES.TEST.:80', $context->startedAt, $context->deadline, [], [], null);
        $template = new FetchRequest($context->url, redirects: RedirectPolicy::Untrusted, siteHost: 'fixtures.test');
        $client = new Client([...app(OutboundHttp::class)->clientConfig($template, $context), 'headers' => [
            'X-Site-Secret' => 'site-secret', 'User-Agent' => 'secret-agent', 'Accept' => 'secret-accept',
        ]]);
        $response = $client->get($url, ['headers' => ['X-Request-Secret' => 'request-secret']]);
        $headers = array_map(static fn (array $values): string => implode(', ', $values), array_change_key_case($transport->getLastRequest()->getHeaders()));

        if ($sameOrigin) {
            expect($headers['x-site-secret'])->toBe('site-secret')
                ->and($headers['x-request-secret'])->toBe('request-secret')
                ->and($headers['user-agent'])->toBe('secret-agent');
        } else {
            expect($headers)->not->toHaveKeys(['x-site-secret', 'x-request-secret'])
                ->and($headers['user-agent'])->toBe('HeptapodBot/'.config()->string('heptapod.version').' (+https://heptapod.watch/bot)')
                ->and(Message::toString($transport->getLastRequest()))->not->toContain('site-secret', 'request-secret', 'secret-agent', 'secret-accept');
        }
    });
})->with([
    'site origin' => ['http://fixtures.test/http-seam/headers', true],
    'external origin' => ['http://external.fixtures.test/http-seam/headers', false],
]);

it('exposes the verified final TLS leaf only when requested through the outbound seam', function (): void {
    config(['certificate_health.ca_file' => base_path('docker/fixtures/tls/ca.crt')]);
    $http = app(OutboundHttp::class);
    $secure = $http->fetch(new FetchRequest('https://fixtures.test/status/200', withCertificateInfo: true), outboundContext());
    $plain = $http->fetch(new FetchRequest('http://fixtures.test/status/200', withCertificateInfo: true), outboundContext());
    $default = $http->fetch(new FetchRequest('https://fixtures.test/status/200'), outboundContext());

    expect($secure->failure)->toBeNull()
        ->and($secure->certificate?->issuer)->toBe('Heptapod offline fixture CA')
        ->and($secure->certificate?->subject)->toBe('fixtures.test')
        ->and($secure->certificate?->validTo)->toBe('2046-01-01T00:00:00+00:00')
        ->and($secure->certificateUnavailableReason)->toBeNull()
        ->and($plain->certificate)->toBeNull()
        ->and($plain->certificateUnavailableReason)->toBe('not_https')
        ->and($default->certificate)->toBeNull()
        ->and($default->certificateUnavailableReason)->toBeNull();
});

it('keeps concurrent certificate observations attached to their own transfer', function (): void {
    config(['certificate_health.ca_file' => base_path('docker/fixtures/tls/ca.crt')]);
    $request = new FetchRequest('https://fixtures.test/status/200', withCertificateInfo: true);
    $config = app(OutboundHttp::class)->clientConfig($request, outboundContext());
    $client = new Client($config);
    $observed = [];
    new Pool($client, [
        'https' => new Request('GET', 'https://fixtures.test/status/200'),
        'http' => new Request('GET', 'http://fixtures.test/status/200'),
    ], [
        'concurrency' => 2,
        'options' => ['on_stats' => static function (TransferStats $stats) use (&$observed): void {
            $observed[$stats->getEffectiveUri()->getScheme()] = $stats->getHandlerStats()['certinfo'][0]['Issuer'] ?? null;
        }],
    ])->promise()->wait();

    expect($observed)->toHaveCount(2)
        ->and($observed['http'])->toBeNull()
        ->and($observed['https'])->toBe('CN = Heptapod offline fixture CA');
});

it('uses native certificate trust expiry and hostname verification', function (string $host): void {
    config(['certificate_health.ca_file' => base_path('docker/fixtures/tls/ca.crt')]);
    $result = app(OutboundHttp::class)->fetch(new FetchRequest('https://'.$host.'/status/200'), outboundContext());

    expect($result->failure?->kind)->toBe(FetchFailureKind::Tls)
        ->and($result->body)->toBe('');
})->with(['expired.fixtures.test', 'self-signed.fixtures.test', 'wrong-host.fixtures.test']);

it('preserves the native curl timing breakdown unchanged', function (): void {
    config(['certificate_health.ca_file' => base_path('docker/fixtures/tls/ca.crt')]);
    $stats = [];
    $config = app(OutboundHttp::class)->clientConfig(new FetchRequest('https://fixtures.test/status/200'), outboundContext());
    $response = new Client($config)->get('https://fixtures.test/status/200', ['on_stats' => function (TransferStats $transfer) use (&$stats): void {
        $stats = $transfer->getHandlerStats();
    }]);
    $result = app(OutboundHttp::class)->fetch(new FetchRequest('https://fixtures.test/status/200'), outboundContext());

    expect($response->getStatusCode())->toBe(200)
        ->and($result->failure)->toBeNull();
    foreach (['namelookup_time', 'connect_time', 'appconnect_time', 'starttransfer_time', 'total_time'] as $key) {
        expect($stats[$key])->toBeFloat()
            ->and($result->transfers[0][$key])->toBeFloat()->toBeGreaterThanOrEqual(0);
    }
});

it('discards all error body and header keys and values including numeric JSON keys', function (): void {
    $result = app(OutboundHttp::class)->fetch(new FetchRequest('http://fixtures.test/http-seam/error', headers: ['private-token' => 'private-token', '123456' => '123456']), outboundContext());

    expect($result->failure?->kind)->toBe(FetchFailureKind::HttpStatus)
        ->and($result->status)->toBe(503)
        ->and($result->body)->toBe('')
        ->and($result->headers)->toBe([])
        ->and($result->failure?->detail())->toBe('Outbound HTTP failed: http_status (HTTP 503).')
        ->not->toContain('PRIVATE-TOKEN')->not->toContain('123456')->not->toContain('fixtures.test');
});

it('returns static typed diagnostics for a truncated secret-bearing error response', function (): void {
    $result = app(OutboundHttp::class)->fetch(new FetchRequest('http://fixtures.test/http-seam/truncated?token=private-token', headers: ['private-token' => 'private-token']), outboundContext());

    expect($result->failure?->kind)->toBe(FetchFailureKind::Connect)
        ->and($result->status)->toBe(503)
        ->and($result->body)->toBe('')
        ->and($result->failure?->exceptionClass)->toBeString()->not->toBeEmpty()
        ->and($result->failure?->detail())->not->toContain('PRIVATE-TOKEN')->not->toContain('fixtures.test');
});

it('classifies native DNS and connection failures without raw URLs', function (string $url, FetchFailureKind $kind): void {
    if ($kind === FetchFailureKind::Dns) {
        ClosedGuardedHttp::run(['not-present.invalid' => new TargetResolutionException('Target DNS lookup failed.')], new MockHandler, function () use ($url, $kind): void {
            $result = app(OutboundHttp::class)->fetch(new FetchRequest($url), outboundContext());
            expect($result->failure?->kind)->toBe($kind)->and($result->failure?->detail())->not->toContain($url);
        });

        return;
    }
    $result = app(OutboundHttp::class)->fetch(new FetchRequest($url), outboundContext());

    expect($result->failure?->kind)->toBe($kind)
        ->and($result->failure?->detail())->not->toContain($url);
})->with([
    ['http://not-present.invalid/', FetchFailureKind::Dns],
    ['http://127.0.0.1:1/', FetchFailureKind::Connect],
]);

it('does not let library request options disable TLS or decoded bounds', function (): void {
    $http = app(OutboundHttp::class);
    $options = ['verify' => false, 'curl' => [CURLOPT_SSL_VERIFYPEER => false], 'decode_content' => false, 'sink' => '/dev/null', 'stream' => true, 'http_errors' => true, 'timeout' => 0];
    foreach (['https://self-signed.fixtures.test/status/200' => FetchFailureKind::Tls, 'http://fixtures.test/http-seam/compressed/gzip/503' => FetchFailureKind::TooLarge] as $url => $kind) {
        $client = new Client($http->clientConfig(new FetchRequest($url), outboundContext()));
        try {
            $client->get($url, $options);
            test()->fail('Unsafe library request unexpectedly succeeded.');
        } catch (FetchException $exception) {
            expect($exception->failure->kind)->toBe($kind)
                ->and($exception->getPrevious())->toBeNull()
                ->and($exception->getMessage())->not->toContain($url);
        }
    }
});

it('keeps library redirect and deadline policies despite request overrides', function (): void {
    $http = app(OutboundHttp::class);
    $url = 'http://fixtures.test/http-seam/private';
    $client = new Client($http->clientConfig(new FetchRequest($url, redirects: RedirectPolicy::Untrusted, siteHost: 'fixtures.test'), outboundContext()));
    expect(fn (): ResponseInterface => $client->get($url, ['allow_redirects' => true, 'curl' => [CURLOPT_RESOLVE => ['127.0.0.1:80:8.8.8.8']]]))->toThrow(FetchException::class, 'private_target');

    $url = 'http://fixtures.test/http-seam/drip';
    $client = new Client($http->clientConfig(new FetchRequest($url), outboundContext(0.2)));
    expect(fn (): ResponseInterface => $client->get($url, ['timeout' => 0, 'connect_timeout' => 0]))->toThrow(FetchException::class, 'timeout');

    $url = 'http://fixtures.test/redirect/start';
    $client = new Client($http->clientConfig(new FetchRequest($url), outboundContext()));
    expect($client->get($url, ['allow_redirects' => true])->getStatusCode())->toBe(301);
});

it('tracks library redirect history without weakening hop limits or guard re-pinning', function (): void {
    $http = app(OutboundHttp::class);
    $template = new FetchRequest('http://fixtures.test', redirects: RedirectPolicy::Untrusted, maxRedirects: 2, siteHost: 'fixtures.test');
    $client = new Client($http->clientConfig($template, outboundContext()));
    $options = ['allow_redirects' => ['track_redirects' => true, 'max' => 100]];
    $response = $client->get('http://fixtures.test/redirect/start', $options);

    expect($response->getHeader('X-Guzzle-Redirect-History'))->toBe(['http://fixtures.test/redirect/hop', 'http://fixtures.test/redirect/final'])
        ->and($response->getHeader('X-Guzzle-Redirect-Status-History'))->toBe(['301', '302']);
    expect(fn (): ResponseInterface => $client->get('http://fixtures.test/http-seam/loop', $options))->toThrow(FetchException::class, 'too_many_redirects');

    ClosedGuardedHttp::run(['fixtures.test' => [['127.0.0.1']], 'rebound.test' => [['127.0.0.1']]], new MockHandler([new Response(302, ['Location' => 'http://rebound.test/private']), new Response(200)]), function () use ($template, $options): void {
        $client = new Client(app(OutboundHttp::class)->clientConfig($template, outboundContext()));
        expect(fn (): ResponseInterface => $client->get('http://fixtures.test/http-seam/rebind', $options))->toThrow(FetchException::class, 'Outbound HTTP failed: private_target.');
    });
});

it('starts each library request ceiling at dispatch while retaining the overall check deadline', function (): void {
    $url = 'http://fixtures.test/http-seam/drip';
    $client = new Client(app(OutboundHttp::class)->clientConfig(new FetchRequest($url, timeoutSeconds: 10), outboundContext(30)));
    $started = microtime(true);
    for ($request = 0; $request < 6; $request++) {
        expect($client->get($url)->getStatusCode())->toBe(200);
    }
    expect(microtime(true) - $started)->toBeGreaterThan(10);
    $client = new Client(app(OutboundHttp::class)->clientConfig(new FetchRequest($url, timeoutSeconds: 10), outboundContext(0.2)));
    expect(fn (): ResponseInterface => $client->get($url))->toThrow(FetchException::class, 'timeout');
});

it('shares one request ceiling across a slow redirect chain', function (): void {
    $result = app(OutboundHttp::class)->fetch(new FetchRequest('http://fixtures.test/http-seam/slow-redirect/1', redirects: RedirectPolicy::Operator, timeoutSeconds: 1), outboundContext(5));

    expect($result->failure?->kind)->toBe(FetchFailureKind::Timeout)
        ->and($result->transfers)->toHaveCount(1);
});

it('propagates unexpected runtime exceptions rather than treating them as DNS failures', function (): void {
    $client = new Client(app(OutboundHttp::class)->clientConfig(new FetchRequest('http://fixtures.test/status/200'), outboundContext()));

    expect(fn (): ResponseInterface => $client->get('http://fixtures.test/status/200', ['on_stats' => static function (): never {
        throw new RuntimeException('unexpected-runtime');
    }]))->toThrow(RuntimeException::class, 'unexpected-runtime');
});

it('serves library requests through the same recording fake', function (): void {
    $request = new FetchRequest('http://example.test/status', headers: [...array_map(static fn (string $value): array => [$value], PublicRequestProfile::headers()), 'Host' => ['example.test'], 'X-Secret' => ['token']], body: '');
    $fake = (new FakeOutboundHttp)->respondWith($request, new FetchResult(503, failure: new FetchFailure(FetchFailureKind::HttpStatus, 503)));
    $client = new Client($fake->clientConfig(new FetchRequest('http://example.test/status'), outboundContext()));
    $response = $client->get('http://example.test/status', ['headers' => ['X-Secret' => 'token']]);

    expect($response->getStatusCode())->toBe(503)
        ->and((string) $response->getBody())->toBe('')
        ->and($fake->requests)->toHaveCount(1)
        ->and($fake->requests[0]['request']->headers)->toBe($request->headers);
});

it('records exact fake seam requests and fails loudly on unconfigured requests', function (): void {
    $request = new FetchRequest('https://example.test', headers: ['X-Secret' => 'token']);
    $response = new FetchResult(failure: new FetchFailure(FetchFailureKind::Timeout));
    $fake = (new FakeOutboundHttp)->respondWith($request, $response);
    app()->instance(OutboundHttp::class, $fake);
    $context = outboundContext();

    expect(app(OutboundHttp::class)->fetch($request, $context))->toBe($response)
        ->and($fake->requests)->toBe([['request' => $request, 'context' => $context]]);
    expect(fn (): FetchResult => $fake->fetch(new FetchRequest('https://example.test/other'), $context))->toThrow(LogicException::class, 'No configured');
});

/** @param callable(): FetchResult|ResponseInterface|array<string, mixed> $operation */
function observeOutboundFailure(callable $operation): FetchResult|ResponseInterface|array|Throwable
{
    try {
        return $operation();
    } catch (Throwable $exception) {
        return $exception;
    }
}

it('returns invalid_request for synchronous header failures without retaining secrets', function (array $headers): void {
    $observed = observeOutboundFailure(fn (): FetchResult => app(OutboundHttp::class)->fetch(new FetchRequest('http://fixtures.test/status/200', headers: $headers), outboundContext()));
    expect($observed instanceof FetchResult ? $observed->failure?->kind->value : $observed::class)->toBe('invalid_request');
    expect(json_encode($observed, JSON_THROW_ON_ERROR))->not->toContain('private-token');
})->with([
    'invalid Host' => [['Host' => 'private-token☃']],
    'CRLF value' => [['X-Token' => "private-token\r\nInjected: value"]],
    'tab control' => [['X-Token' => "private-token\tvalue"]],
]);

it('sanitizes configured headers before libraries can construct requests', function (): void {
    $observed = observeOutboundFailure(fn (): array => app(OutboundHttp::class)->clientConfig(new FetchRequest('http://fixtures.test/status/200', headers: ['X-Token' => "private-token\r\nInjected: value"]), outboundContext()));
    expect($observed)->toBeInstanceOf(FetchException::class);
    expect($observed->getMessage())->not->toContain('private-token');
    expect($observed->getPrevious())->toBeNull();
});

it('sanitizes synchronous Host validation through the library handler', function (): void {
    $url = 'http://fixtures.test/status/200';
    $client = new Client(app(OutboundHttp::class)->clientConfig(new FetchRequest($url), outboundContext()));
    $observed = observeOutboundFailure(fn (): ResponseInterface => $client->get($url, ['headers' => ['Host' => 'private-token☃']]));
    expect($observed)->toBeInstanceOf(FetchException::class);
    expect($observed->failure->kind->value)->toBe('invalid_request');
    expect($observed->getMessage())->not->toContain('private-token');
    expect($observed->getPrevious())->toBeNull();
});

it('never exposes failure payloads for library supplied ordinary header secrets', function (string $name): void {
    $url = 'http://fixtures.test/http-seam/error';
    $client = new Client(app(OutboundHttp::class)->clientConfig(new FetchRequest($url), outboundContext()));
    $response = $client->get($url, ['headers' => [$name => 'private-token']]);
    expect((string) $response->getBody())->toBe('');
    expect($response->getHeaders())->toBe([]);
    expect(Message::toString($response))->not->toContain('PRIVATE-TOKEN');
})->with(['User-Agent', 'Accept', 'Accept-Encoding', 'Content-Type']);

it('discards JSON escaped failure keys and values instead of trying to redact encodings', function (): void {
    $url = 'http://fixtures.test/http-seam/escaped-error';
    $result = app(OutboundHttp::class)->fetch(new FetchRequest($url, headers: ['private-token' => 'private-token']), outboundContext());
    expect($result->body)->toBe('');
    expect($result->headers)->toBe([]);
    expect($result->failure?->kind)->toBe(FetchFailureKind::HttpStatus);
    $control = app(OutboundHttp::class)->fetch(new FetchRequest($url, acceptedStatuses: [500]), outboundContext());
    $decoded = json_decode($control->body, flags: JSON_THROW_ON_ERROR);
    expect($decoded->{'private-token'})->toBe('PRIVATE-TOKEN');
});

it('returns a caller accepted 401 body unchanged but no payload for an unaccepted 500', function (): void {
    $logs = [];
    Log::listen(function (MessageLogged $event) use (&$logs): void {
        $logs[] = [$event->message, $event->context];
    });
    $accepted = app(OutboundHttp::class)->fetch(new FetchRequest('http://fixtures.test/http-seam/accepted-401', acceptedStatuses: [401]), outboundContext());
    expect($accepted->failure)->toBeNull();
    expect($accepted->body)->toBe('private-token expected keyword');

    $unaccepted = app(OutboundHttp::class)->fetch(new FetchRequest('http://fixtures.test/http-seam/escaped-error'), outboundContext());
    expect($unaccepted->body)->toBe('');
    expect(json_encode($unaccepted, JSON_THROW_ON_ERROR))->not->toContain('PRIVATE-TOKEN');
    expect($unaccepted->failure?->detail())->not->toContain('PRIVATE-TOKEN');
    expect($logs)->not->toBeEmpty();
    foreach ($logs as [$message, $context]) {
        expect($message)->toBe('Test fixture outbound allowed')
            ->and($context)->toMatchArray(['host' => 'fixtures.test'])
            ->and(array_keys($context))->toBe(['host', 'seam'])
            ->and($context['seam'])->toBeIn(['http', 'resolver']);
    }
    expect(strtolower(json_encode($logs, JSON_THROW_ON_ERROR)))->not->toContain('private-token', 'expected keyword');

    $url = 'http://fixtures.test/http-seam/accepted-401';
    $client = new Client(app(OutboundHttp::class)->clientConfig(new FetchRequest($url, acceptedStatuses: [401]), outboundContext()));
    expect((string) $client->get($url)->getBody())->toBe('private-token expected keyword');
});

it('rejects delayed library scheduling before dispatch instead of starting after the deadline', function (): void {
    $id = (string) Str::uuid();
    $url = 'http://fixtures.test/http-seam/dispatch/'.$id;
    $client = new Client(app(OutboundHttp::class)->clientConfig(new FetchRequest($url), outboundContext(0.2)));
    $started = microtime(true);
    $observed = observeOutboundFailure(fn (): ResponseInterface => $client->get($url, ['delay' => 1000]));
    expect($observed)->toBeInstanceOf(InvalidArgumentException::class);
    expect(microtime(true) - $started)->toBeLessThan(0.8);
    $counter = app(OutboundHttp::class)->fetch(new FetchRequest('http://fixtures.test/http-seam/dispatch-count/'.$id), outboundContext());
    expect(trim($counter->body))->toBe('0');

    $control = app(OutboundHttp::class)->fetch(new FetchRequest($url), outboundContext());
    expect($control->failure)->toBeNull();
    $counter = app(OutboundHttp::class)->fetch(new FetchRequest('http://fixtures.test/http-seam/dispatch-count/'.$id), outboundContext());
    expect(trim($counter->body))->toBe('1');
});

it('never restores supplied headers when a redirect returns to its initial origin', function (): void {
    $echo = new Response(200);
    $transport = new MockHandler([
        new Response(302, ['Location' => 'http://external.fixtures.test/hop']), new Response(302, ['Location' => 'http://fixtures.test/final']), $echo,
        new Response(302, ['Location' => 'http://fixtures.test/final']), $echo,
    ]);
    ClosedGuardedHttp::run(['fixtures.test' => [['8.8.8.8']], 'external.fixtures.test' => [['1.1.1.1']]], $transport, function () use ($transport): void {
        $headers = ['X-Secret' => 'private-token', 'User-Agent' => 'private-token', 'Accept' => 'application/json'];
        $result = app(OutboundHttp::class)->fetch(new FetchRequest('http://fixtures.test/http-seam/return-origin', headers: $headers, redirects: RedirectPolicy::Operator), outboundContext());
        expect($result->failure)->toBeNull();
        expect(Message::toString($transport->getLastRequest()))->not->toContain('private-token');
        expect(array_change_key_case($transport->getLastRequest()->getHeaders()))->not->toHaveKeys(['x-secret'])
            ->and($transport->getLastRequest()->getHeaderLine('Accept'))->toBe('text/html,application/xhtml+xml;q=0.9,*/*;q=0.8');

        $control = app(OutboundHttp::class)->fetch(new FetchRequest('http://fixtures.test/http-seam/same-origin', headers: $headers, redirects: RedirectPolicy::Operator), outboundContext());
        expect($control->failure)->toBeNull()
            ->and($transport->getLastRequest()->getHeaderLine('X-Secret'))->toBe('private-token')
            ->and($transport->getLastRequest()->getHeaderLine('Accept'))->toBe('application/json');
    });
});

it('rejects unsupported callbacks rather than swallowing their runtime exceptions', function (string $callback): void {
    $url = 'http://fixtures.test/status/200';
    $client = new Client(app(OutboundHttp::class)->clientConfig(new FetchRequest($url), outboundContext()));
    $observed = observeOutboundFailure(fn (): ResponseInterface => $client->get($url, [$callback => static function (): never {
        throw new RuntimeException('callback-bug');
    }]));
    expect($observed)->toBeInstanceOf(InvalidArgumentException::class);
    expect($observed->getMessage())->toBe('Unsupported outbound HTTP callback: '.$callback.'.');
})->with(['on_trailers', 'on_headers', 'progress']);

it('returns typed timeout when less than one millisecond remains', function (): void {
    $observed = observeOutboundFailure(fn (): FetchResult => app(OutboundHttp::class)->fetch(new FetchRequest('http://fixtures.test/status/200', timeoutSeconds: 0.0009), outboundContext()));
    expect($observed instanceof FetchResult ? $observed->failure?->kind->value : $observed::class)->toBe('timeout');
    expect($observed->transfers)->toBe([]);
});

it('fails loudly for a malformed operator CA instead of blaming the target TLS', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'http-seam-ca-');
    file_put_contents($file, 'not a PEM certificate');
    config(['certificate_health.ca_file' => $file]);
    try {
        $observed = observeOutboundFailure(fn (): FetchResult => app(OutboundHttp::class)->fetch(new FetchRequest('https://fixtures.test/status/200'), outboundContext()));
        expect($observed)->toBeInstanceOf(InvalidArgumentException::class);
        expect($observed->getMessage())->toBe('Invalid outbound HTTP CA file.');
    } finally {
        unlink($file);
    }
});

it('rejects mixed private and public DNS answers in either order', function (array $ips): void {
    ClosedGuardedHttp::run(['mixed.invalid' => [$ips]], new MockHandler([new Response(200)]), function (): void {
        $result = app(OutboundHttp::class)->fetch(new FetchRequest('http://mixed.invalid/status/200', redirects: RedirectPolicy::Untrusted, siteHost: 'fixtures.test'), outboundContext());
        expect($result->failure)->toEqual(new FetchFailure(FetchFailureKind::PrivateTarget, targetAddress: '127.0.0.1', targetClass: 'private_network'));
        expect($result->failure?->detail())->toBe('Outbound HTTP failed: private_target.');
        expect($result->transfers)->toBe([]);
    });
})->with([[['8.8.8.8', '127.0.0.1']], [['127.0.0.1', '8.8.8.8']]]);

it('normalises inet_aton numeric aliases to loopback without DNS', function (string $host): void {
    expect((new NativeTargetResolver)->resolve($host))->toBe(['127.0.0.1']);
})->with(['2130706433', '0177.0.0.1', '0x7f000001', '127.1']);

it('refuses alternate numeric IP spellings before any transport', function (string $host): void {
    ClosedGuardedHttp::run([$host => [['127.0.0.1']]], new MockHandler([new Response(200)]), function () use ($host): void {
        $result = app(OutboundHttp::class)->fetch(new FetchRequest('http://'.$host.'/status/200', redirects: RedirectPolicy::Untrusted, siteHost: 'fixtures.test'), outboundContext());
        expect($result->failure)->toEqual(new FetchFailure(FetchFailureKind::PrivateTarget, targetAddress: '127.0.0.1', targetClass: 'private_network'));
        expect($result->failure?->detail())->toBe('Outbound HTTP failed: private_target.');
        expect($result->transfers)->toBe([]);
    });
})->with(['2130706433', '0177.0.0.1', '0x7f000001', '127.1']);

it('ignores proxy environment variables and native curl proxy options', function (): void {
    $variables = ['HTTP_PROXY', 'http_proxy', 'HTTPS_PROXY', 'https_proxy', 'ALL_PROXY', 'all_proxy', 'NO_PROXY', 'no_proxy'];
    $previous = [];
    foreach ($variables as $name) {
        $previous[$name] = getenv($name);
        putenv($name.'='.(str_contains(strtolower($name), 'no_proxy') ? '' : 'http://127.0.0.1:1'));
    }
    try {
        $url = 'http://fixtures.test/status/200';
        $client = new Client(app(OutboundHttp::class)->clientConfig(new FetchRequest($url), outboundContext()));
        expect($client->get($url, ['proxy' => 'http://127.0.0.1:1', 'curl' => [CURLOPT_PROXY => 'http://127.0.0.1:1']])->getStatusCode())->toBe(200);
        $control = observeOutboundFailure(fn (): ResponseInterface => new Client(['proxy' => 'http://127.0.0.1:1'])->get($url));
        expect($control)->toBeInstanceOf(ConnectException::class);
    } finally {
        foreach ($previous as $name => $value) {
            putenv($value === false ? $name : $name.'='.$value);
        }
    }
});

it('bounds the decoded output of multiple content compression layers', function (): void {
    $url = 'http://fixtures.test/http-seam/multilayered';
    $result = app(OutboundHttp::class)->fetch(new FetchRequest($url), outboundContext());
    expect($result->failure?->kind)->toBe(FetchFailureKind::TooLarge);
    $control = app(OutboundHttp::class)->fetch(new FetchRequest($url, maxDecodedBytes: 3 * 1024 * 1024), outboundContext());
    expect($control->failure)->toBeNull();
    expect(strlen($control->body))->toBe(2 * 1024 * 1024);
});

it('pins a permitted public target and refuses a private external target after DNS changes', function (): void {
    $transport = new MockHandler([new Response(200, [], "OK fixture.\n"), new Response(200)]);
    ClosedGuardedHttp::run(['pinned-fixture.invalid' => [['8.8.8.8'], ['127.0.0.1']]], $transport, function () use ($transport): void {
        $url = 'http://pinned-fixture.invalid/status/200';
        $config = app(OutboundHttp::class)->clientConfig(new FetchRequest($url, redirects: RedirectPolicy::Untrusted, siteHost: 'site.test'), outboundContext());
        $history = [];
        $config['handler']->push(Middleware::history($history));
        $response = new Client($config)->get($url);
        expect($response->getStatusCode())->toBe(200)->and((string) $response->getBody())->toBe("OK fixture.\n")
            ->and($history[0]['options']['curl'][CURLOPT_RESOLVE])->toBe(['pinned-fixture.invalid:80:8.8.8.8']);
        $result = app(OutboundHttp::class)->fetch(new FetchRequest($url, redirects: RedirectPolicy::Untrusted, siteHost: 'site.test'), outboundContext());
        expect($result->failure)->toEqual(new FetchFailure(FetchFailureKind::PrivateTarget, targetAddress: '127.0.0.1', targetClass: 'private_network'))
            ->and($result->failure?->detail())->toBe('Outbound HTTP failed: private_target.')
            ->and($result->transfers)->toBe([])->and($transport->count())->toBe(1);
    });
});

it('allows HEAD representation length without counting it as a downloaded body', function (bool $library): void {
    $url = 'http://fixtures.test/status/200';
    $http = app(OutboundHttp::class);
    if ($library) {
        $client = new Client($http->clientConfig(new FetchRequest($url, maxWireBytes: 1), outboundContext()));
        $result = observeOutboundFailure(fn (): ResponseInterface => $client->head($url));
        expect($result)->toBeInstanceOf(ResponseInterface::class);
        expect($result->getStatusCode())->toBe(200);
        expect((int) $result->getHeaderLine('Content-Length'))->toBeGreaterThan(1);
        expect((string) $result->getBody())->toBe('');
    } else {
        $result = $http->fetch(new FetchRequest($url, method: 'HEAD', maxWireBytes: 1), outboundContext());
        expect($result->failure)->toBeNull();
        expect($result->status)->toBe(200);
        expect($result->body)->toBe('');
    }
})->with(['fetch' => [false], 'library actual method differs from template' => [true]]);

it('allows an accepted 304 representation length without counting it as a body', function (): void {
    $result = app(OutboundHttp::class)->fetch(new FetchRequest('http://fixtures.test/http-seam/not-modified', maxWireBytes: 1, acceptedStatuses: [304]), outboundContext());
    expect($result->failure)->toBeNull();
    expect($result->status)->toBe(304);
    expect($result->body)->toBe('');
    expect((int) $result->headers['Content-Length'][0])->toBe(2097152);
});

it('reports header names and ordinal positions without retaining values', function (array $headers, string $name, int $position, ?int $valuePosition): void {
    $request = new FetchRequest('http://fixtures.test/status/200', headers: $headers);
    $http = app(OutboundHttp::class);
    $result = $http->fetch($request, outboundContext());
    expect($result->failure?->kind)->toBe(FetchFailureKind::InvalidRequest);
    expect(get_object_vars($result->failure))->toMatchArray([
        'headerName' => $name,
        'headerPosition' => $position,
        'headerValuePosition' => $valuePosition,
    ]);
    expect($result->transfers)->toBe([]);
    expect(json_encode($result, JSON_THROW_ON_ERROR))->not->toContain('private-token');

    $exception = observeOutboundFailure(fn (): array => $http->clientConfig($request, outboundContext()));
    expect($exception)->toBeInstanceOf(FetchException::class);
    expect($exception->failure)->toEqual($result->failure);
    expect($exception->getPrevious())->toBeNull();
    expect($exception->getMessage())->not->toContain('private-token');
    expect(json_encode($exception->failure, JSON_THROW_ON_ERROR))->not->toContain('private-token');
})->with([
    'invalid header name' => [['Accept' => 'text/plain', 'X-Bad Name' => 'private-token'], 'X-Bad Name', 1, null],
    'caller supplied Host even when it matches the URL' => [['Accept' => 'text/plain', 'Host' => 'fixtures.test'], 'Host', 1, null],
    'escaped invalid name' => [["X-Bad\r\né" => 'private-token'], 'X-Bad\\r\\n\\303\\251', 0, null],
    'bounded invalid name' => [[str_repeat('A', 150).':' => 'private-token'], str_repeat('A', 128), 0, null],
    'empty value list' => [['Accept' => 'text/plain', 'X-Token' => []], 'X-Token', 1, null],
    'first duplicate value' => [['Accept' => 'text/plain', 'X-Token' => ["private-token\r\nInjected: value", 'safe']], 'X-Token', 1, 0],
    'second duplicate value' => [['Accept' => 'text/plain', 'X-Token' => ['safe', "private-token\r\nInjected: value"]], 'X-Token', 1, 1],
]);

it('rejects non integer accepted statuses as configuration errors', function (mixed $status): void {
    expect(fn (): FetchRequest => new FetchRequest('http://fixtures.test/http-seam/accepted-401', acceptedStatuses: [$status]))
        ->toThrow(InvalidArgumentException::class, 'Invalid accepted HTTP status.');
})->with(['string' => ['401'], 'float' => [401.0]]);

it('aborts an oversized accepted 401 body instead of bypassing decoded limits', function (): void {
    $url = 'http://fixtures.test/http-seam/large/401';
    $result = app(OutboundHttp::class)->fetch(new FetchRequest($url, maxWireBytes: 3 * 1024 * 1024, maxDecodedBytes: 32, acceptedStatuses: [401]), outboundContext());
    expect($result->failure?->kind)->toBe(FetchFailureKind::TooLarge);
    expect($result->status)->toBe(401);
    expect($result->body)->toBe('');
    expect($result->headers)->toBe([]);
    $control = app(OutboundHttp::class)->fetch(new FetchRequest($url, maxWireBytes: 3 * 1024 * 1024, maxDecodedBytes: 3 * 1024 * 1024, acceptedStatuses: [401]), outboundContext());
    expect($control->failure)->toBeNull();
    expect($control->status)->toBe(401);
    expect(strlen($control->body))->toBe(2 * 1024 * 1024);
});

it('rejects library Host overrides and duplicates with header location diagnostics', function (array $values): void {
    $url = 'http://fixtures.test/status/200';
    $client = new Client(app(OutboundHttp::class)->clientConfig(new FetchRequest($url), outboundContext()));
    $message = new Request('GET', $url, ['X-Safe' => 'safe', 'Host' => $values]);
    $exception = observeOutboundFailure(fn (): ResponseInterface => $client->send($message));
    expect($exception)->toBeInstanceOf(FetchException::class);
    expect(get_object_vars($exception->failure))->toMatchArray([
        'kind' => FetchFailureKind::InvalidRequest,
        'headerName' => 'Host',
        'headerPosition' => 4,
        'headerValuePosition' => null,
    ]);
    expect($exception->getMessage())->not->toContain('private-token');
    expect($exception->getPrevious())->toBeNull();
    expect(json_encode($exception->failure, JSON_THROW_ON_ERROR))->not->toContain('private-token');
    expect($client->get($url)->getStatusCode())->toBe(200);
})->with([
    'different authority' => [['private-token']],
    'non ASCII override' => [['private-token☃']],
    'duplicate generated values' => [['fixtures.test', 'fixtures.test']],
]);

it('blocks cross-origin redirects with a body instead of disclosing it', function (int $status, bool $strict): void {
    $result = app(OutboundHttp::class)->fetch(new FetchRequest('http://fixtures.test/http-seam/body-redirect/'.$status, method: 'POST', body: 'private payload', redirects: RedirectPolicy::Operator, strictRedirects: $strict), outboundContext());
    expect($result->failure?->kind)->toBe(FetchFailureKind::RedirectBlocked)->and($result->body)->toBe('');
})->with([301, 302, 303, 307, 308])->with([true, false]);
