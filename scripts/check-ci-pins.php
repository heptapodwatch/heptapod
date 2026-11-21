<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

$root = dirname(__DIR__);
$failures = [];

/**
 * @param  array<string, mixed>  $document
 * @return list<array{location: string, uses: string}>
 */
$collectUses = static function (array $document, bool $isWorkflow): array {
    $found = [];

    if ($isWorkflow) {
        $jobs = $document['jobs'] ?? null;
        if (! is_array($jobs)) {
            return $found;
        }

        foreach ($jobs as $jobId => $job) {
            if (! is_array($job)) {
                continue;
            }

            if (isset($job['uses']) && is_string($job['uses'])) {
                $found[] = ['location' => 'job '.$jobId, 'uses' => trim($job['uses'])];
            }

            $steps = $job['steps'] ?? null;
            if (! is_array($steps)) {
                continue;
            }

            foreach ($steps as $index => $step) {
                if (is_array($step) && isset($step['uses']) && is_string($step['uses'])) {
                    $found[] = ['location' => 'job '.$jobId.' step '.((int) $index + 1), 'uses' => trim($step['uses'])];
                }
            }
        }

        return $found;
    }

    $runs = $document['runs'] ?? null;
    $steps = is_array($runs) ? ($runs['steps'] ?? null) : null;
    if (! is_array($steps)) {
        return $found;
    }

    foreach ($steps as $index => $step) {
        if (is_array($step) && isset($step['uses']) && is_string($step['uses'])) {
            $found[] = ['location' => 'step '.((int) $index + 1), 'uses' => trim($step['uses'])];
        }
    }

    return $found;
};

$workflowFiles = array_merge(
    glob($root.'/.github/workflows/*.yml') ?: [],
    glob($root.'/.github/workflows/*.yaml') ?: [],
);
sort($workflowFiles);

$actionFiles = [];
if (is_dir($root.'/.github/actions')) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/.github/actions', FilesystemIterator::SKIP_DOTS));
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->isFile() && in_array($file->getFilename(), ['action.yml', 'action.yaml'], true)) {
            $actionFiles[] = $file->getPathname();
        }
    }
    sort($actionFiles);
}

if ($workflowFiles === [] || $actionFiles === []) {
    fwrite(STDERR, "CI pin guard failed: expected workflow and composite action files under .github/.\n");
    exit(1);
}

/** @var array<string, true> $scannedLocalTargets */
$scannedLocalTargets = [];
foreach ($workflowFiles as $workflowFile) {
    $resolved = realpath($workflowFile);
    if ($resolved !== false) {
        $scannedLocalTargets[$resolved] = true;
    }
}
foreach ($actionFiles as $actionFile) {
    $resolvedDir = realpath(dirname($actionFile));
    if ($resolvedDir !== false) {
        $scannedLocalTargets[$resolvedDir] = true;
    }
}

$externalActions = 0;
foreach ([...$workflowFiles, ...$actionFiles] as $file) {
    $relative = substr($file, strlen($root) + 1);
    $isWorkflow = str_starts_with($relative, '.github/workflows/');
    $parsed = Yaml::parseFile($file);
    if (! is_array($parsed)) {
        $failures[] = $relative.': unexpected YAML root';

        continue;
    }

    foreach ($collectUses($parsed, $isWorkflow) as ['location' => $location, 'uses' => $uses]) {
        if (str_starts_with($uses, './')) {
            $resolvedTarget = realpath($root.'/'.substr($uses, 2));
            if ($resolvedTarget === false || ! isset($scannedLocalTargets[$resolvedTarget])) {
                $failures[] = $relative.' ('.$location.'): local reference is not covered by scanned .github/workflows or .github/actions files: '.$uses;
            }

            continue;
        }

        $externalActions++;

        if (str_starts_with($uses, 'docker://')) {
            if (preg_match('/@sha256:[0-9a-f]{64}$/', $uses) !== 1) {
                $failures[] = $relative.' ('.$location.'): unpinned docker action: '.$uses;
            }

            continue;
        }

        if (preg_match('/@[0-9a-f]{40}$/', $uses) !== 1) {
            $failures[] = $relative.' ('.$location.'): action must be pinned to a 40-hex commit SHA: '.$uses;
        }
    }
}

$dockerfiles = [];
if (is_dir($root.'/docker')) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/docker', FilesystemIterator::SKIP_DOTS));
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        $name = $file->getFilename();
        if ($file->isFile() && ($name === 'Dockerfile' || str_starts_with($name, 'Dockerfile.') || str_ends_with($name, '.Dockerfile'))) {
            $dockerfiles[] = $file->getPathname();
        }
    }
    sort($dockerfiles);
}

if ($dockerfiles === []) {
    fwrite(STDERR, "CI pin guard failed: expected Dockerfiles under docker/.\n");
    exit(1);
}

$externalImages = 0;
foreach ($dockerfiles as $file) {
    $relative = substr($file, strlen($root) + 1);
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        throw new RuntimeException('Cannot read '.$relative);
    }

    $instructions = [];
    $buffer = '';
    $startLine = 0;

    foreach ($lines as $index => $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        if ($buffer === '') {
            $startLine = $index + 1;
        }

        if (str_ends_with(rtrim($line), '\\')) {
            $buffer .= substr(rtrim($line), 0, -1).' ';

            continue;
        }

        $instructions[] = ['line' => $startLine, 'text' => trim($buffer.$line)];
        $buffer = '';
    }

    if ($buffer !== '') {
        $instructions[] = ['line' => $startLine, 'text' => trim($buffer)];
    }

    /** @var array<string, true> $stages */
    $stages = [];

    foreach ($instructions as ['line' => $lineNumber, 'text' => $instruction]) {
        if (preg_match('/^FROM\s+(.+)$/i', $instruction, $match) === 1) {
            $rest = trim($match[1]);
            while (preg_match('/^--\S+\s+(.*)$/', $rest, $flagMatch) === 1) {
                $rest = trim($flagMatch[1]);
            }

            if (preg_match('/^(\S+)(?:\s+AS\s+(\S+))?$/i', $rest, $fromMatch) !== 1) {
                $failures[] = $relative.':'.$lineNumber.': could not parse FROM instruction: '.$instruction;

                continue;
            }

            $image = $fromMatch[1];
            $stageKey = strtolower($image);

            if ($stageKey !== 'scratch' && ! isset($stages[$stageKey])) {
                $externalImages++;
                if (preg_match('/@sha256:[0-9a-f]{64}$/', $image) !== 1) {
                    $failures[] = $relative.':'.$lineNumber.': FROM image must be pinned by @sha256 digest: '.$image;
                }
            }

            if (isset($fromMatch[2]) && $fromMatch[2] !== '') {
                $stages[strtolower($fromMatch[2])] = true;
            }

            continue;
        }

        if (preg_match('/^COPY\s+(.+)$/i', $instruction, $match) === 1) {
            $rest = trim($match[1]);
            while (preg_match('/^(--\S+)\s+(.*)$/', $rest, $flagMatch) === 1) {
                $flag = $flagMatch[1];
                $rest = trim($flagMatch[2]);
                if (preg_match('/^--from=(\S+)$/i', $flag, $fromMatch) === 1) {
                    $source = $fromMatch[1];
                    $sourceKey = strtolower($source);
                    if (preg_match('/^\d+$/', $source) !== 1 && ! isset($stages[$sourceKey])) {
                        $externalImages++;
                        if (preg_match('/@sha256:[0-9a-f]{64}$/', $source) !== 1) {
                            $failures[] = $relative.':'.$lineNumber.': COPY --from image must be pinned by @sha256 digest: '.$source;
                        }
                    }
                }
            }
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, "CI pin guard failed:\n".implode("\n", $failures)."\n");
    exit(1);
}

echo 'CI pin guard: '.(count($workflowFiles) + count($actionFiles)).' workflow/action files ('.$externalActions.' external actions) and '.count($dockerfiles).' Dockerfiles ('.$externalImages." external images) checked; all pinned.\n";
