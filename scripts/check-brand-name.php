<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Symfony\Component\Process\Process;

$root = dirname(__DIR__);
$policyPath = 'scripts/brand-name-exceptions.json';
/** @var array{files: array<string, string>, strings: array<string, list<string>>} $policy */
$policy = json_decode(file_get_contents($root.'/'.$policyPath), true, flags: JSON_THROW_ON_ERROR);
$oldName = implode('', ['lyn', 'keus']);
$process = new Process(['git', 'ls-files', '--cached', '--others', '--exclude-standard', '-z'], $root);
$process->mustRun();
$failures = [];
$checked = 0;
foreach (explode("\0", $process->getOutput()) as $path) {
    if ($path === '' || ! is_file($root.'/'.$path)) {
        continue;
    }
    $checked++;
    if (stripos($path, $oldName) !== false) {
        $failures[] = $path.': old brand in filename';
    }
    // This policy is the reviewed list of exact exceptions, not application content.
    if ($path === $policyPath) {
        continue;
    }
    if (isset($policy['files'][$path])) {
        if (hash_file('sha256', $root.'/'.$path) !== $policy['files'][$path]) {
            $failures[] = $path.': frozen historical file changed';
        }

        continue;
    }
    $text = file_get_contents($root.'/'.$path);
    if ($text === false) {
        throw new RuntimeException('Cannot read '.$path);
    }
    // Only remove exact approved strings in their named files. New occurrences elsewhere fail.
    $text = str_replace($policy['strings'][$path] ?? [], '', $text);
    foreach (explode("\n", $text) as $index => $line) {
        if (stripos($line, $oldName) !== false) {
            $failures[] = $path.':'.($index + 1).': old brand found';
        }
    }
}
if ($failures !== []) {
    fwrite(STDERR, "Brand guard failed:\n".implode("\n", $failures)."\n");
    exit(1);
}
echo 'Brand guard: '.$checked." files checked; only reviewed exact exceptions remain.\n";
