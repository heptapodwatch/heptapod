<?php

use Heptapod\Addons\Performance\PerformanceCheck;
use Heptapod\Addons\Uptime\UptimeCheck;
use Heptapod\Contracts\CheckType;
use Heptapod\Jobs\RunCheck;
use Heptapod\Providers\LocalFixtureServiceProvider;
use Illuminate\Contracts\Queue\ShouldBeUnique;

$loader = require dirname(__DIR__, 3).'/vendor/autoload.php';
$addonPrefixes = array_values(array_filter(array_keys($loader->getPrefixesPsr4()), fn (string $prefix): bool => str_starts_with($prefix, 'Heptapod\\Addons\\')));

// One target per dataset row: Pest's not->toUse over an array of targets fails only when every target uses the dependency.
arch('production code does not import boundary fakes', function (string $namespace): void {
    expect($namespace)->not->toUse('Heptapod\\Testing')
        ->ignoring(LocalFixtureServiceProvider::class); // Binds the recorded Oh Dear import client only in the local environment.
})->with(['Heptapod\\Checks', 'Heptapod\\Models', 'Heptapod\\Providers', ...$addonPrefixes]);
arch('check types live in addons')->expect('Heptapod')->classes()->not->toImplement(CheckType::class)->ignoring($addonPrefixes);

arch('installed check types implement the addon contract')->expect([
    UptimeCheck::class,
    PerformanceCheck::class,
])->toImplement(CheckType::class);

arch('check jobs are unique')->expect(RunCheck::class)->toImplement(ShouldBeUnique::class);

arch('check jobs declare execution timeout', function (): void {
    $timeout = new ReflectionClass(RunCheck::class)->getProperty('timeout');
    expect($timeout->isPublic())->toBeTrue()
        ->and((string) $timeout->getType())->toBe('int');
});
