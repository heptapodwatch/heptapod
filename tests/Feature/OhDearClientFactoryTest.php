<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Contracts\OhDear\OhDearClientFactory;
use Heptapod\Providers\LocalFixtureServiceProvider;
use Heptapod\Support\OhDear\SdkOhDearClientFactory;
use Heptapod\Testing\RecordedOhDearClientFactory;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-29T12:00:00Z'));
});

/** Re-registers the local fixture provider as the application would boot it in the given environment. */
function bootLocalFixtures(string $environment, ?string $fixture): OhDearClientFactory
{
    app()->forgetInstance(OhDearClientFactory::class);
    app()->bind(OhDearClientFactory::class, SdkOhDearClientFactory::class);
    config(['services.ohdear.import_fixture' => $fixture]);
    app()->detectEnvironment(static fn (): string => $environment);
    app()->register(LocalFixtureServiceProvider::class, force: true);

    return app(OhDearClientFactory::class);
}

it('never resolves the recorded Oh Dear fixture outside the local environment, even with the fixture configured', function (string $environment): void {
    expect(bootLocalFixtures($environment, 'tests/Fixtures/ohdear/real'))->toBeInstanceOf(SdkOhDearClientFactory::class);
})->with(['production', 'testing', 'staging']);

it('resolves the recorded Oh Dear fixture only locally and only when it is configured', function (?string $fixture, string $expected): void {
    expect(bootLocalFixtures('local', $fixture))->toBeInstanceOf($expected);
})->with([
    'configured' => ['tests/Fixtures/ohdear/real', RecordedOhDearClientFactory::class],
    'unset' => [null, SdkOhDearClientFactory::class],
    'empty' => ['', SdkOhDearClientFactory::class],
]);
