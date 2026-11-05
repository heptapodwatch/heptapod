<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Heptapod\Addons\Domain\BoundedWhoisClient;
use Heptapod\Contracts\CertificateFetcher;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\DnsLookupResult;
use Heptapod\Contracts\DnsResolver;
use Heptapod\Contracts\Http\FetchRequest;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Contracts\Http\RedirectPolicy;
use Heptapod\Contracts\Http\TargetResolver;
use Heptapod\Testing\FakeDnsResolver;
use Heptapod\Testing\FakeTargetResolver;
use Psr\Http\Message\ResponseInterface;

beforeEach(function (): void {
    // The guarded transport uses an absolute wall-clock deadline, independently of Carbon.
    $this->travelTo(CarbonImmutable::parse('2100-01-01T00:00:00Z'));
    config(['heptapod.version' => '1.2.3']);
});

function profileContext(): CheckContext
{
    $at = CarbonImmutable::now();

    return new CheckContext(1, 1, 1, 'profile-token', 'http://fixtures.test', $at, $at->addSeconds(10), [], [], null);
}

it('keeps DNS lookups within the check deadline for normal fetches and public probes across redirects', function (): void {
    $resolver = (new FakeTargetResolver)
        ->respondWith('fixtures.test', ['8.8.8.8'])
        ->respondWith('external.fixtures.test', ['8.8.8.8']);
    app()->instance(TargetResolver::class, $resolver);
    $http = app(OutboundHttp::class);
    foreach ([false, true] as $publicOnly) {
        $request = new FetchRequest('http://fixtures.test/start', redirects: RedirectPolicy::Untrusted,
            siteHost: $publicOnly ? null : 'fixtures.test', publicTargetsOnly: $publicOnly);
        $config = $http->clientConfig($request, profileContext());
        $config['handler']->setHandler(new MockHandler([
            new Response(302, ['Location' => 'http://external.fixtures.test/end']), new Response(200),
        ]));
        expect(new Client($config)->get($request->url)->getStatusCode())->toBe(200);
    }

    expect($resolver->deadlines)->toHaveCount(4);
    foreach ($resolver->deadlines as $deadline) {
        expect($deadline)->toBe(4102444810.0);
    }
});

it('sends the public bot profile and preserves explicit Accept headers through the production client', function (array $headers, string $accept): void {
    app()->instance(TargetResolver::class, (new FakeTargetResolver)->respondWith('fixtures.test', ['8.8.8.8']));
    $request = new FetchRequest('http://fixtures.test/profile', headers: $headers);
    $config = app(OutboundHttp::class)->clientConfig($request, profileContext());
    $history = [];
    $config['handler']->setHandler(new MockHandler([new Response(200)]));
    $config['handler']->push(Middleware::history($history));

    $response = new Client($config)->get($request->url);

    expect($response->getStatusCode())->toBe(200);
    expect($history)->toHaveCount(1);
    $sent = $history[0]['request'];
    expect($sent->getHeaderLine('User-Agent'))->toBe('HeptapodBot/1.2.3 (+https://heptapod.watch/bot)')
        ->and($sent->getHeaderLine('Accept'))->toBe($accept)
        ->and($sent->getHeaderLine('Accept-Language'))->toBe('en,de;q=0.9,*;q=0.5');
})->with([
    'HTML default' => [[], 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8'],
    'RDAP' => [['Accept' => ['application/rdap+json']], 'application/rdap+json'],
    'application health' => [['Accept' => ['application/json']], 'application/json'],
]);

it('refuses non-fixture hosts before DNS or transport even when a fixture redirects there', function (bool $redirect): void {
    $blockedHost = 'external.fixtures.test.attacker.example.test';
    $resolver = (new FakeTargetResolver)
        ->respondWith('fixtures.test', ['8.8.8.8'])
        ->respondWith($blockedHost, ['8.8.8.8']);
    app()->instance(TargetResolver::class, $resolver);
    $url = $redirect ? 'https://fixtures.test/start' : 'https://'.$blockedHost.'/end';
    $request = new FetchRequest($url, redirects: RedirectPolicy::Untrusted, publicTargetsOnly: true);
    $config = app(OutboundHttp::class)->clientConfig($request, profileContext());
    $transport = new MockHandler($redirect
        ? [new Response(302, ['Location' => 'https://'.$blockedHost.'/end']), new Response(200)]
        : [new Response(200)]);
    $config['handler']->setHandler($transport);

    expect(fn (): ResponseInterface => new Client($config)->get($url))->toThrow(LogicException::class, 'Blocked non-fixture host '.$blockedHost);

    expect($transport->count())->toBe(1)
        ->and($resolver->deadlines)->toHaveCount($redirect ? 1 : 0);
})->with(['direct' => false, 'fixture redirect' => true]);

it('refuses non-fixture certificate and WHOIS endpoints before resolution or socket connection', function (string $transport): void {
    $host = 'registry.fixtures.test.attacker.example.test';
    $resolver = (new FakeTargetResolver)->respondWith($host, ['127.0.0.1']);
    // A bind retains TestCase's decorator; explicit instance fakes in other tests remain offline overrides.
    app()->bind(TargetResolver::class, static fn (): TargetResolver => $resolver);

    $fetchCertificate = [app(CertificateFetcher::class), 'fetch'];
    $attempt = $transport === 'certificate'
        ? fn () => $fetchCertificate($host)
        : fn () => app(BoundedWhoisClient::class)->query($host, 'example.test');

    expect($attempt)->toThrow(LogicException::class, 'Blocked non-fixture host '.$host);
    expect($resolver->deadlines)->toBeEmpty();
})->with(['certificate', 'whois']);

it('refuses non-fixture DNS questions and nameservers before dig', function (string $host, ?string $nameserver, ?string $configuredServer, string $blocked): void {
    $resolver = (new FakeDnsResolver)->respondWith($host, 'A', new DnsLookupResult([], [], $nameserver), $nameserver);
    app()->bind(DnsResolver::class, static fn (): DnsResolver => $resolver);
    config(['dns.resolver' => $configuredServer]);

    expect(fn () => app(DnsResolver::class)->resolve($host, 'A', $nameserver))
        ->toThrow(LogicException::class, 'Blocked non-fixture host '.$blocked);
})->with([
    'question' => ['attacker.example.test', 'dns-fixtures', null, 'attacker.example.test'],
    'explicit server' => ['fixtures.test', 'resolver.attacker.example.test', 'dns-fixtures', 'resolver.attacker.example.test'],
    'configured server' => ['fixtures.test', null, 'resolver.attacker.example.test', 'resolver.attacker.example.test'],
    'OS resolver' => ['fixtures.test', null, null, '(os resolver)'],
]);

it('preserves explicit fixture resolution and dig answers through the network guards', function (): void {
    $resolver = (new FakeTargetResolver)->respondWith('fixtures.test', ['127.0.0.1']);
    app()->bind(TargetResolver::class, static fn (): TargetResolver => $resolver);
    $answer = new DnsLookupResult([['type' => 'A', 'ip' => '127.0.0.1']], [], 'dns-fixtures');
    $dns = (new FakeDnsResolver)->respondWith('fixtures.test', 'A', $answer, 'dns-fixtures');
    app()->bind(DnsResolver::class, static fn (): DnsResolver => $dns);

    expect(app(TargetResolver::class)->resolve('fixtures.test'))->toBe(['127.0.0.1']);
    expect(app(DnsResolver::class)->resolve('fixtures.test', 'A', 'dns-fixtures'))->toBe($answer);
});
