<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Symfony\Component\Process\Process;

$usage = "Usage: php scripts/check-private-info.php [--all] [--base <ref> | --pushed] [--text <file>]...\n";
$options = getopt('', ['all', 'base:', 'pushed', 'text:'], $restIndex);
if ($restIndex !== $argc || $options === [] || is_array($options['base'] ?? null) || (isset($options['base']) && isset($options['pushed']))) {
    fwrite(STDERR, $usage);
    exit(2);
}
$git = static function (string ...$arguments): string {
    $process = new Process(['git', ...$arguments]);
    $process->mustRun();

    return $process->getOutput();
};
// The checked repository is the one in the current directory, so the test can run this script on a synthetic repository.
$root = rtrim($git('rev-parse', '--show-toplevel'));
chdir($root);
$policyPath = 'scripts/private-info-exceptions.json';
/** @var array{strings: array<string, list<string>>} $policy */
$policy = json_decode((string) file_get_contents($policyPath), true, flags: JSON_THROW_ON_ERROR);
$rules = [
    'home path' => '~(?<![\w.-])/(?:home|Users)/[\w.-]+/|\b[A-Za-z]:\\\\+Users\\\\+[\w.-]+~',
    'tailnet host' => '~(?<![\w-])(?:[a-z0-9-]+\.)+(?:ts|tailscale)\.net\b~i',
    'email address' => '~[\w.%+-]+@(?:[a-z0-9-]+\.)+[a-z]{2,}\b~i',
    'local URL in user-facing docs' => '~https?://(?:localhost|127\.0\.0\.1|0\.0\.0\.0|\[::1\]):\d+~i',
];
// Reserved example domains, the project's own public addresses and GitHub's public no-reply addresses.
$allowedEmail = '~@(?:[a-z0-9-]+\.)*(?:example(?:\.com|\.org|\.net)?|test|invalid|localhost|heptapod\.watch|users\.noreply\.github\.com)$~i';
// One regex per line, outside every checkout: the Git common directory is shared by all worktrees and mounted in the hook container.
$localPatternsPath = rtrim($git('rev-parse', '--path-format=absolute', '--git-common-dir')).'/info/private-info-patterns';
$localPatterns = [];
if (is_file($localPatternsPath)) {
    foreach (file($localPatternsPath, FILE_IGNORE_NEW_LINES) ?: [] as $number => $line) {
        if (trim($line) === '' || str_starts_with(trim($line), '#')) {
            continue;
        }
        $pattern = '~'.str_replace('~', '\~', $line).'~i';
        if (@preg_match($pattern, '') === false) {
            throw new RuntimeException('Invalid regex on line '.($number + 1).' of '.$localPatternsPath);
        }
        $localPatterns['local pattern line '.($number + 1)] = $pattern;
    }
}

$failures = [];
$scan = static function (string $label, string $text, ?string $path) use (&$failures, $rules, $allowedEmail, $localPatterns, $policy, $policyPath): void {
    // Approved values count only as complete matches in their named file, so a longer address around one still fails.
    $exceptions = array_flip(match ($path) {
        null => [],
        // The policy names every excepted value; the rest of it is checked like any other file.
        $policyPath => array_merge(...array_values($policy['strings'])),
        default => $policy['strings'][$path] ?? [],
    });
    $used = [];
    $active = $localPatterns;
    foreach ($rules as $name => $pattern) {
        if ($name !== 'local URL in user-facing docs' || ($path !== null && str_starts_with($path, 'docs/') && $path !== 'docs/deploy/operations.md')) {
            $active[$name] = $pattern;
        }
    }
    foreach ($active as $name => $pattern) {
        preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as [$match, $offset]) {
            if ($name === 'email address' && preg_match($allowedEmail, $match) === 1) {
                continue;
            }
            if (isset($exceptions[$match])) {
                $used[$match] = true;

                continue;
            }
            // Report only the match, never the surrounding line.
            $failures[] = $label.':'.(substr_count($text, "\n", 0, $offset) + 1).': '.$name.': '.$match;
        }
    }
    if ($path !== $policyPath) {
        foreach (array_keys(array_diff_key($exceptions, $used)) as $stale) {
            $failures[] = $label.': stale exception '.json_encode($stale);
        }
    }
};

$base = $options['base'] ?? null;
if (isset($options['all'])) {
    $paths = explode("\0", $git('ls-files', '--cached', '--others', '--exclude-standard', '-z'));
} elseif ($base !== null) {
    $mergeBase = rtrim($git('merge-base', $base, 'HEAD'));
    $paths = [
        ...explode("\0", $git('diff', '--name-only', '--diff-filter=d', '-z', $mergeBase)),
        ...explode("\0", $git('ls-files', '--others', '--exclude-standard', '-z')),
    ];
} else {
    $paths = [];
}
$checkedFiles = 0;
foreach (array_unique($paths) as $path) {
    if ($path === '' || ! is_file($path)) {
        continue;
    }
    $checkedFiles++;
    $scan($path, (string) file_get_contents($path), $path);
}
$commitRange = $base !== null ? [$base.'..HEAD'] : [];
if (isset($options['pushed'])) {
    // Git's pre-push stdin: "<local ref> <local sha> <remote ref> <remote sha>" per pushed ref. Deleted refs push no commits.
    foreach (file('php://stdin', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $localSha = explode(' ', $line)[1] ?? throw new RuntimeException('Unexpected pre-push input: '.$line);
        if (trim($localSha, '0') !== '') {
            $commitRange[] = $localSha;
        }
    }
    // Every commit no remote has yet, which also covers new branches and force pushes.
    $commitRange = $commitRange === [] ? [] : [...$commitRange, '--not', '--remotes'];
}
$checkedCommits = 0;
if ($commitRange !== []) {
    foreach (array_filter(array_map(ltrim(...), explode("\x1e", $git('log', '--format=%H%x00%B%x1e', ...$commitRange)))) as $commit) {
        [$sha, $message] = explode("\0", $commit, 2);
        $checkedCommits++;
        $scan('commit '.substr($sha, 0, 12).' message', $message, null);
    }
}
foreach ((array) ($options['text'] ?? []) as $textPath) {
    $text = file_get_contents($textPath);
    if ($text === false) {
        throw new RuntimeException('Cannot read '.$textPath);
    }
    $scan('text '.$textPath, $text, null);
}
if ($failures !== []) {
    fwrite(STDERR, "Private info guard failed:\n".implode("\n", $failures)."\nRemove the private value, or add a reviewed exact exception to ".$policyPath.".\n");
    exit(1);
}
echo 'Private info guard: '.$checkedFiles.' files, '.$checkedCommits.' commit messages, '.count((array) ($options['text'] ?? [])).' texts and '.count($localPatterns)." local patterns checked.\n";
