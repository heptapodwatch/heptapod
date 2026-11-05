<?php

use Tests\Quarantine;

/** @return list<string> */
function phpunitSuiteEntries(string $suite, string $element): array
{
    $document = new DOMDocument;
    expect($document->load(dirname(__DIR__, 2).'/phpunit.xml'))->toBeTrue();
    $nodes = new DOMXPath($document)->query("/phpunit/testsuites/testsuite[@name='{$suite}']/{$element}");
    expect($nodes)->not->toBeFalse();

    return array_map(static fn (DOMNode $node): string => trim($node->textContent), iterator_to_array($nodes));
}

// The CI selection excludes exactly the documented quarantine, and a removed or renamed file must leave the list.
test('the Feature suite excludes exactly the documented quarantine', function (): void {
    $excluded = phpunitSuiteEntries('Feature', 'exclude');
    $listed = array_keys(Quarantine::Files);
    sort($excluded);
    sort($listed);

    expect($excluded)->toBe($listed);
});

test('every quarantined file still exists and is not in the Gate', function (string $file): void {
    $gate = phpunitSuiteEntries('Gate', 'file');

    expect(is_file(dirname(__DIR__, 2).'/'.$file))->toBeTrue("Stale quarantine entry {$file}: remove it from tests/Quarantine.php and phpunit.xml.")
        ->and($gate)->not->toContain($file);
})->with(array_keys(Quarantine::Files));

test('every quarantine entry names a cause class and an owner area', function (string $file, array $entry): void {
    expect($entry['cause'])->toBeIn(['product', 'test'])
        ->and($entry['owner'])->not->toBe('')
        ->and($entry['note'])->not->toBe('');
})->with(array_map(null, array_keys(Quarantine::Files), array_values(Quarantine::Files)));
