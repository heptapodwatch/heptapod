<?php

declare(strict_types=1);

/** @return list<string> */
function selectedTests(string $path): array
{
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        throw new RuntimeException("Cannot read selection: {$path}");
    }

    $tests = [];
    foreach ($lines as $line) {
        if (str_starts_with($line, ' - ')) {
            // PHPUnit appends a full stop to the last listed test in each class.
            $tests[] = rtrim(substr($line, 3), '.');
        }
    }
    if ($tests === [] || count($tests) !== count(array_unique($tests))) {
        throw new RuntimeException("Empty or duplicate selection: {$path}");
    }
    sort($tests);

    return $tests;
}

/** @return array{total: int, passed: int, failures: int, errors: int, skipped: int} */
function junitCounts(string $path): array
{
    $xml = simplexml_load_file($path);
    if ($xml === false) {
        throw new RuntimeException("Cannot read JUnit: {$path}");
    }
    $suite = $xml->getName() === 'testsuite' ? $xml : $xml->testsuite;
    $total = (int) $suite['tests'];
    $failures = (int) $suite['failures'];
    $errors = (int) $suite['errors'];
    $skipped = (int) $suite['skipped'];
    $cases = $xml->xpath('//testcase');
    if ($cases === false || $total < 1 || count($cases) !== $total || $failures + $errors + $skipped > $total) {
        throw new RuntimeException("Missing or inconsistent JUnit test cases: {$path}");
    }

    return [
        'total' => $total,
        'passed' => $total - $failures - $errors - $skipped,
        'failures' => $failures,
        'errors' => $errors,
        'skipped' => $skipped,
    ];
}

/**
 * @param  list<array{total: int, passed: int, failures: int, errors: int, skipped: int}>  $reports
 * @return array{total: int, passed: int, failures: int, errors: int, skipped: int}
 */
function totalCounts(array $reports): array
{
    $counts = ['total' => 0, 'passed' => 0, 'failures' => 0, 'errors' => 0, 'skipped' => 0];
    foreach ($reports as $report) {
        foreach ($counts as $key => $value) {
            $counts[$key] = $value + $report[$key];
        }
    }

    return $counts;
}

$mode = $argv[1] ?? '';
$path = $argv[2] ?? '';
switch ($mode) {
    case 'selection':
        $all = selectedTests($path);
        $shards = array_map(selectedTests(...), array_slice($argv, 3));
        if (count($shards) !== 4) {
            throw new RuntimeException('Expected all four native shard selections');
        }
        $union = array_merge(...$shards);
        sort($union);
        if ($union !== $all) {
            throw new RuntimeException('Native shard union is not disjoint and equal to the unsharded selection');
        }
        $report = ['total' => count($all), 'shards' => array_map(count(...), $shards), 'disjoint' => true, 'complete' => true];
        break;
    case 'junit':
        $report = junitCounts($path);
        break;
    case 'aggregate':
        $shards = [];
        foreach ([1, 2, 3, 4] as $shard) {
            $counts = junitCounts("{$path}/php-{$shard}.xml");
            if ($counts['total'] !== count(selectedTests("{$path}/php-selection-{$shard}.txt"))) {
                throw new RuntimeException("PHP shard {$shard} executed a different test count than its native selection");
            }
            $shards[] = $counts;
        }
        $php = totalCounts($shards);
        $browser = junitCounts("{$path}/browser.xml");
        if ($php['total'] !== count(selectedTests("{$path}/php-all.txt")) || $browser['total'] !== count(selectedTests("{$path}/browser-selection.txt"))) {
            throw new RuntimeException('Executed suite counts do not match unsharded selections');
        }
        $report = ['php' => $php, 'phpShards' => $shards, 'browser' => $browser, 'total' => totalCounts([$php, $browser])];
        if ($report['total']['failures'] > 0 || $report['total']['errors'] > 0) {
            throw new RuntimeException('Test results contain failures or errors');
        }
        break;
    default:
        throw new InvalidArgumentException('Usage: ci-test-summary.php {selection all.txt shard1.txt ... shard4.txt|junit results.xml|aggregate results-directory}');
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
