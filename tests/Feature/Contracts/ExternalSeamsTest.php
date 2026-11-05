<?php

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CertificateFetcher;
use Heptapod\Contracts\CertificateFetchResult;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\ChromiumRenderer;
use Heptapod\Contracts\DnsLookupResult;
use Heptapod\Contracts\DnsResolver;
use Heptapod\Contracts\DomainRegistrationLookup;
use Heptapod\Contracts\DomainRegistrationResult;
use Heptapod\Contracts\DomainRegistrationSource;
use Heptapod\Contracts\ExternalNetworkException;
use Heptapod\Contracts\ExternalTimeoutException;
use Heptapod\Contracts\Http\FetchException;
use Heptapod\Contracts\Http\FetchFailure;
use Heptapod\Contracts\Http\FetchFailureKind;
use Heptapod\Contracts\LighthouseReport;
use Heptapod\Testing\FakeCertificateFetcher;
use Heptapod\Testing\FakeChromiumRenderer;
use Heptapod\Testing\FakeDnsResolver;
use Heptapod\Testing\FakeDomainRegistrationLookup;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05T12:00:00Z'));
    config(['dns.resolver' => null]);
});

// Each row resolves the addon-facing contract from the real container, not a domain double.
dataset('external seams', [
    'dns' => [DnsResolver::class, FakeDnsResolver::class, 'respondWith', 'resolve', ['example.test', 'A', 'ns.provider.test'], ['example.test', 'AAAA', 'ns.provider.test'], fn (): DnsLookupResult => new DnsLookupResult([['ip' => '192.0.2.1']], ['example.com A 192.0.2.1'], null), []],
    'certificate' => [CertificateFetcher::class, FakeCertificateFetcher::class, 'respondWith', 'fetch', ['example.com'], ['example.com', 8443], fn (): CertificateFetchResult => new CertificateFetchResult(['PEM fixture'], 'example.com', 443, true), []],
    'registration' => [DomainRegistrationLookup::class, FakeDomainRegistrationLookup::class, 'respondWith', 'fetch', ['example.com'], ['different.example.com'], fn (): DomainRegistrationResult => new DomainRegistrationResult(DomainRegistrationSource::Rdap, ['objectClassName' => 'domain'], 'https://rdap.example.com'), []],
    'chromium lighthouse' => [ChromiumRenderer::class, FakeChromiumRenderer::class, 'respondWithLighthouse', 'lighthouse', ['https://example.com', ['device' => 'mobile']], ['https://example.com', ['device' => 'desktop', 'password' => 'credential-never-log']], fn (): LighthouseReport => new LighthouseReport(['audits' => []], 'https://example.com'), [['device' => 'mobile']]],
]);

it('matches configured boundary responses and rejects mismatched or unconfigured arguments without leaking credentials', function (string $contract, string $fakeClass, string $configure, string $operation, array $arguments, array $mismatch, Closure $responseFactory, array $configurationTail): void {
    $fake = new $fakeClass;
    $response = $responseFactory();
    $fake->{$configure}($arguments[0], ...($configure === 'respondWith' && $operation === 'resolve' ? [$arguments[1], $response, $arguments[2]] : [$response, ...$configurationTail]));
    app()->singleton($contract, static fn (): object => $fake);
    $boundary = app($contract);
    if (in_array($contract, [DomainRegistrationLookup::class, ChromiumRenderer::class], true)) {
        $at = CarbonImmutable::now();
        $context = new CheckContext(1, 2, 3, 'token', 'https://example.com', $at, $at->addSeconds(120), [], [], null);
        if ($contract === ChromiumRenderer::class) {
            array_splice($arguments, 1, 0, [$context]);
            array_splice($mismatch, 1, 0, [$context]);
        } else {
            $arguments[] = $context;
            $mismatch[] = $context;
        }
    }
    expect($boundary->{$operation}(...$arguments))->toBe($response);
    foreach ([$mismatch, $contract === DnsResolver::class ? ['example.test', 'credential-never-log', $arguments[2]] : ['credential-never-log', ...array_slice($arguments, 1)]] as $unexpected) {
        try {
            $boundary->{$operation}(...$unexpected);
            $this->fail('Unconfigured boundary request was accepted.');
        } catch (LogicException $exception) {
            expect($exception->getMessage())->toContain('No configured', $operation)
                ->not->toContain('credential-never-log', 'Authorization', 'password', 'example.com');
        }
    }
})->with('external seams');

it('propagates typed boundary failures with their original cause', function (string $contract, string $fakeClass, string $configure, string $operation, array $arguments, array $mismatch, Closure $responseFactory, array $configurationTail, string $errorClass): void {
    $fake = new $fakeClass;
    $cause = new RuntimeException('Transport failed');
    $error = new $errorClass('External request failed', previous: $cause);
    $fake->{$configure}($arguments[0], ...($configure === 'respondWith' && $operation === 'resolve' ? [$arguments[1], $error, $arguments[2]] : [$error, ...$configurationTail]));
    app()->singleton($contract, static fn (): object => $fake);
    $boundary = app($contract);
    if (in_array($contract, [DomainRegistrationLookup::class, ChromiumRenderer::class], true)) {
        $at = CarbonImmutable::now();
        $context = new CheckContext(1, 2, 3, 'token', 'https://example.com', $at, $at->addSeconds(120), [], [], null);
        if ($contract === ChromiumRenderer::class) {
            array_splice($arguments, 1, 0, [$context]);
        } else {
            $arguments[] = $context;
        }
    }
    try {
        $boundary->{$operation}(...$arguments);
        $this->fail('Boundary failure was swallowed.');
    } catch (ExternalNetworkException $exception) {
        expect($exception)->toBe($error);
        expect($exception->getPrevious())->toBe($cause);
    }
})->with('external seams')->with([ExternalNetworkException::class, ExternalTimeoutException::class]);

it('propagates configured HTTP failures through the shared registration fake', function (): void {
    app()->singleton(DomainRegistrationLookup::class, FakeDomainRegistrationLookup::class);
    $boundary = app(DomainRegistrationLookup::class);
    $error = new FetchException(new FetchFailure(FetchFailureKind::InvalidRequest));
    $boundary->respondWith('example.com', $error);
    $at = CarbonImmutable::now();
    $context = new CheckContext(1, 2, 3, 'token', 'https://example.com', $at, $at->addSeconds(120), [], [], null);
    try {
        $boundary->fetch('example.com', $context);
        $this->fail('Configured HTTP failure was swallowed.');
    } catch (FetchException $exception) {
        expect($exception)->toBe($error);
    }
});

it('rejects mismatched registration source payloads before supplying them to an addon', function (DomainRegistrationSource $source, array|string $raw): void {
    app()->singleton(DomainRegistrationLookup::class, FakeDomainRegistrationLookup::class);
    expect(fn () => app(DomainRegistrationLookup::class)->respondWith('example.com', new DomainRegistrationResult($source, $raw, 'registry.example.com')))->toThrow(InvalidArgumentException::class);
})->with([
    [DomainRegistrationSource::Rdap, 'WHOIS text'],
    [DomainRegistrationSource::Whois, ['objectClassName' => 'domain']],
]);
