<?php

use PHPUnit\TextUI\XmlConfiguration\Loader;
use SebastianBergmann\FileIterator\Facade;

// A test file outside the suite's directories never runs and never fails, so CI must notice the gap.
test('every addon test outside tests/Manual runs in the Feature suite', function (): void {
    $root = dirname(__DIR__, 2);
    $suites = array_filter(iterator_to_array(new Loader()->load($root.'/phpunit.xml')->testSuite()), fn ($suite): bool => $suite->name() === 'Feature');
    expect($suites)->toHaveCount(1);
    $suite = array_values($suites)[0];
    $excluded = array_map(fn ($exclude): string => $exclude->path(), iterator_to_array($suite->exclude()));
    $inSuite = [];
    foreach ($suite->directories() as $directory) {
        $inSuite = [...$inSuite, ...new Facade()->getFilesAsArray($directory->path(), $directory->suffix(), $directory->prefix(), $excluded)];
    }

    $addonTests = array_filter(
        new Facade()->getFilesAsArray($root.'/addons/*/tests', 'Test.php'),
        fn (string $file): bool => ! str_contains($file, '/tests/Manual/'),
    );

    expect($addonTests)->not->toBeEmpty()
        ->and(array_values(array_diff($addonTests, $inSuite)))->toBe([]);
});
