<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Heptapod\Contracts\Http\FetchException;
use Heptapod\Contracts\Http\PrivateTargetGuard;
use Heptapod\Contracts\Http\TargetResolver;
use Heptapod\Testing\FakeTargetResolver;
use Psr\Http\Message\ResponseInterface;

// Every case fakes resolution and replaces the transport with a MockHandler, so the native guard is tested without
// the fixture-only host allowlist, which would otherwise refuse these hosts before the guard under test runs.
beforeEach(function (): void {
    app()->forgetExtenders(PrivateTargetGuard::class);
});

it('blocks third-party private and reserved addresses before dispatch', function (string $ip): void {
    app()->instance(TargetResolver::class, (new FakeTargetResolver)->respondWith('external.test', [$ip]));
    $config = app(PrivateTargetGuard::class)->clientConfig('site.test');
    $config['handler']->setHandler(new MockHandler([new Response(200)]));
    expect(fn (): ResponseInterface => new Client($config)->get('http://external.test/path'))->toThrow(FetchException::class, 'Outbound HTTP failed: private_target.');
})->with(json_decode(file_get_contents(__DIR__.'/../../docker/lighthouse/proxy/policy-cases.json'), true, flags: JSON_THROW_ON_ERROR)['blocked']);

it('permits the shared public-range cases and private first-resolution addresses of the site host', function (string $ip, bool $ownHost): void {
    app()->instance(TargetResolver::class, (new FakeTargetResolver)->respondWith('external.test', [$ip]));
    $config = app(PrivateTargetGuard::class)->clientConfig($ownHost ? 'external.test' : 'site.test');
    $config['handler']->setHandler(new MockHandler([new Response(200)]));
    expect(new Client($config)->get('http://external.test/path')->getStatusCode())->toBe(200);
})->with([
    ...array_map(static fn (string $ip): array => [$ip, false], json_decode(file_get_contents(__DIR__.'/../../docker/lighthouse/proxy/policy-cases.json'), true, flags: JSON_THROW_ON_ERROR)['allowed']),
    ...array_map(static fn (string $ip): array => [$ip, true], json_decode(file_get_contents(__DIR__.'/../../docker/lighthouse/proxy/policy-cases.json'), true, flags: JSON_THROW_ON_ERROR)['blocked']),
]);

it('always denies shared link-local targets, including site hosts', function (string $ip, bool $ownHost): void {
    app()->instance(TargetResolver::class, (new FakeTargetResolver)->respondWith('external.test', [$ip]));
    $config = app(PrivateTargetGuard::class)->clientConfig($ownHost ? 'external.test' : 'site.test');
    $history = [];
    $config['handler']->setHandler(new MockHandler([new Response(200)]));
    $config['handler']->push(Middleware::history($history));
    expect(fn (): ResponseInterface => new Client($config)->get('http://external.test/path'))->toThrow(FetchException::class, 'private_target');
    expect($history)->toBeEmpty();
})->with(array_merge(...array_map(static fn (string $ip): array => [[$ip, true], [$ip, false]], json_decode(file_get_contents(__DIR__.'/../../docker/lighthouse/proxy/policy-cases.json'), true, flags: JSON_THROW_ON_ERROR)['alwaysBlocked'])));

it('trusts normalized same-host targets', function (): void {
    app()->instance(TargetResolver::class, (new FakeTargetResolver)->respondWith('internal.test', ['127.0.0.1'])->respondWith('xn--bcher-kva.test', ['127.0.0.1']));
    $guard = app(PrivateTargetGuard::class);
    $config = $guard->clientConfig('internal.test');
    $config['handler']->setHandler(new MockHandler([new Response(200)]));
    expect(new Client($config)->get('http://INTERNAL.test./path')->getStatusCode())->toBe(200);
    $config = $guard->clientConfig('bücher.test');
    $config['handler']->setHandler(new MockHandler([new Response(200)]));
    expect(new Client($config)->get('https://xn--bcher-kva.test/')->getStatusCode())->toBe(200);
});

it('validates every redirect hop and does not dispatch a private redirect target', function (): void {
    app()->instance(TargetResolver::class, (new FakeTargetResolver)->respondWith('external.test', ['8.8.8.8'])->respondWith('127.0.0.1', ['127.0.0.1']));
    $config = app(PrivateTargetGuard::class)->clientConfig('site.test');
    $history = [];
    $config['handler']->setHandler(new MockHandler([new Response(302, ['Location' => 'http://127.0.0.1/private']), new Response(200)]));
    $config['handler']->push(Middleware::history($history));
    expect(fn (): ResponseInterface => new Client($config)->get('http://external.test/start'))->toThrow(FetchException::class, 'private_target');
    expect($history)->toHaveCount(1)->and($history[0]['request']->getUri()->getHost())->toBe('external.test');
});

it('does not dispatch a public-only redirect to a private address', function (): void {
    app()->instance(TargetResolver::class, (new FakeTargetResolver)
        ->respondWith('external.test', ['8.8.8.8'])
        ->respondWith('internal.test', ['127.0.0.1']));
    $config = app(PrivateTargetGuard::class)->clientConfig('internal.test', true);
    $history = [];
    $config['handler']->setHandler(new MockHandler([new Response(302, ['Location' => 'http://internal.test/private']), new Response(200)]));
    $config['handler']->push(Middleware::history($history));
    expect(fn (): ResponseInterface => new Client($config)->get('http://external.test/start'))->toThrow(FetchException::class, 'private_target');
    expect($history)->toHaveCount(1)->and($history[0]['request']->getUri()->getHost())->toBe('external.test');
});

it('pins all validated addresses without DNS fallback and keeps them when the DNS answer changes', function (): void {
    $fake = (new FakeTargetResolver)->respondWith('external.test', ['8.8.8.8', '2001:4860:4860::8888'], ['127.0.0.1']);
    app()->instance(TargetResolver::class, $fake);
    $config = app(PrivateTargetGuard::class)->clientConfig('site.test');
    $history = [];
    $config['handler']->setHandler(new MockHandler([new Response(200), new Response(200)]));
    $config['handler']->push(Middleware::history($history));
    $client = new Client($config);
    $client->get('http://external.test/start');
    expect($history[0]['options']['curl'][CURLOPT_RESOLVE])->toBe(['external.test:80:8.8.8.8,[2001:4860:4860::8888]'])
        ->and($history[0]['options']['proxy'])->toBe('');
    $client->get('http://external.test/rebound');
    expect($history)->toHaveCount(2)
        ->and($history[1]['options']['curl'][CURLOPT_RESOLVE])->toBe(['external.test:80:8.8.8.8,[2001:4860:4860::8888]'])
        ->and($fake->deadlines)->toHaveCount(1);
});
