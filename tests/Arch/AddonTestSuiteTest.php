<?php

// A test file outside the suite's directories never runs and never fails, so CI must notice the gap.
test('every addon test outside tests/Manual runs in the Feature suite', function (): void {
    $root = dirname(__DIR__, 2);
    $document = new DOMDocument;
    expect($document->load($root.'/phpunit.xml'))->toBeTrue();
    $suiteDirectories = [];
    foreach (new DOMXPath($document)->query("/phpunit/testsuites/testsuite[@name='Feature']/directory") ?: [] as $node) {
        // PHPUnit resolves wildcards in <directory> with glob() as well.
        foreach (glob($root.'/'.trim($node->textContent), GLOB_ONLYDIR) ?: [] as $directory) {
            $suiteDirectories[] = $directory.'/';
        }
    }

    $outsideSuite = [];
    foreach (glob($root.'/addons/*/tests', GLOB_ONLYDIR) ?: [] as $testRoot) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testRoot)) as $file) {
            $path = $file->getPathname();
            if (str_ends_with($path, 'Test.php') && ! str_contains($path, '/tests/Manual/')
                && array_find($suiteDirectories, fn (string $directory): bool => str_starts_with($path, $directory)) === null) {
                $outsideSuite[] = substr($path, strlen($root) + 1);
            }
        }
    }

    expect($outsideSuite)->toBe([]);
});
