<?php

declare(strict_types=1);

/** Gate is opt-in by file. This scan rejects known nondeterministic test APIs, not production code. */
$root = dirname(__DIR__);
$config = simplexml_load_file($root.'/phpunit.xml');
if ($config === false) {
    throw new RuntimeException('Cannot read phpunit.xml.');
}
$suites = $config->xpath('/phpunit/testsuites/testsuite[@name="Gate"]');
if ($suites === false || count($suites) !== 1 || count($suites[0]->directory) !== 0) {
    throw new RuntimeException('Gate must be exactly one explicit file list, without directories.');
}
$files = array_map(strval(...), iterator_to_array($suites[0]->file, false));
if ($files === [] || count($files) !== count(array_unique($files))) {
    throw new RuntimeException('Gate must contain a nonempty, unique file list.');
}
$failures = [];
$checked = [];
$testRoot = static function (string $absolute) use ($root): ?string {
    if (str_starts_with($absolute, $root.'/tests/')) {
        return $root.'/tests/';
    }
    $relative = substr($absolute, strlen($root) + 1);
    if (str_starts_with($absolute, $root.'/') && preg_match('~^addons/[a-z_]+/tests/~', $relative, $match) === 1) {
        return $root.'/'.$match[0];
    }

    return null;
};
$scan = function (string $path, bool $inheritedClock = false) use (&$scan, &$failures, &$checked, $root, $testRoot): void {
    if (isset($checked[$path])) {
        return;
    }
    $checked[$path] = true;
    $absolute = realpath($root.'/'.$path);
    if ($absolute === false || $testRoot($absolute) === null || ! str_ends_with($absolute, '.php')) {
        throw new RuntimeException('Invalid Gate test path: '.$path);
    }
    $source = file_get_contents($absolute);
    if ($source === false) {
        throw new RuntimeException('Cannot read '.$path);
    }
    // Keep literals for the fixed clock/include checks; strip comments and strings for API checks.
    $code = '';
    $literals = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
            $literals .= $token[1]."\n";
        }
        $code .= is_array($token)
            ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true) ? str_repeat("\n", substr_count($token[1], "\n")) : $token[1])
            : $token;
    }
    $fixedClock = $inheritedClock || preg_match('/beforeEach\s*\(function\s*\([^)]*\)[^{]*\{\s*(?:\/\/[^\n]*\n\s*)?\$this->travelTo\(CarbonImmutable::parse\(\x27\d{4}-\d{2}-\d{2}T[^\x27]+\x27\)\);/s', $source) === 1;
    $rules = [
        'sleep, retry or repeat helper' => '/\b(?:sleep|usleep|time_nanosleep|time_sleep_until|retry\w*)\s*\(|\bSleep\s*::|->\s*(?:repeat|flaky)\s*\(/i',
        'real network API' => '/\b(?:curl_\w+|gethostby(?:name|namel|addr)|dns_\w+|checkdnsrr|fsockopen|pfsockopen|stream_socket_\w+|socket_(?:addrinfo_lookup|connect|sendto))\s*\(/i',
        'wall clock API' => '/\b(?:time|microtime|date|gmdate)\s*\(|\bnew\s+\\\\?(?:DateTime|DateTimeImmutable)\b/i',
        'production database clock' => '/\bPostgresDatabaseClock\b|\bCheckSchedule\s*::\s*databaseNow\b/i',
        'clock reset or silent quarantine' => '/->\s*(?:travelBack|skip\w*|todo|markTestSkipped|markTestIncomplete)\s*\(|\b(?:markTestSkipped|markTestIncomplete)\s*\(|::\s*setTestNow\s*\(\s*(?:null|false)?\s*\)/i',
        'subprocess or native network client' => '/\bnew\s+\\\\?(?:(?:Symfony\\\\Component\\\\Process\\\\)?Process|NativeTargetResolver|CurlHandle|SoapClient)\b|\b(?:exec|shell_exec|proc_open|popen|system|passthru|pcntl_fork|pcntl_exec)\s*\(|\bProcess\s*::\s*\w+\s*\(/i',
        'unbounded file URL read' => '/\b(?:file_get_contents|fopen)\s*\(\s*[\x22\x27]https?:/i',
    ];
    foreach ($rules as $reason => $pattern) {
        if (preg_match($pattern, $reason === 'unbounded file URL read' ? $source : $code) === 1) {
            $failures[] = $path.': '.$reason;
        }
    }
    // SQL clocks live inside raw string/heredoc tokens, which the API scan above deliberately strips.
    if (preg_match('/\b(?:CURRENT_(?:TIMESTAMP|DATE|TIME)|LOCAL(?:TIMESTAMP|TIME))\b|\b(?:clock_timestamp|statement_timestamp|transaction_timestamp|now|timeofday)\s*\(/i', $literals) === 1) {
        $failures[] = $path.': database-clock SQL literal';
    }
    if (preg_match('/\bPostgresDatabaseClock\b|\bCheckSchedule\s*::\s*databaseNow\b/i', $literals) === 1) {
        $failures[] = $path.': production database clock in a literal';
    }
    if (preg_match('/\b(?:now|today|freezeTime|freezeSecond)\s*\(/i', $code) === 1 && ! $fixedClock) {
        $failures[] = $path.': clock read without a fixed literal travelTo() in beforeEach';
    }
    if (preg_match('/\bHttp\s*::\s*\w+\s*\(/', $code) === 1 && (! str_contains($code, 'Http::preventStrayRequests(') || ! str_contains($code, 'Http::fake('))) {
        $failures[] = $path.': HTTP facade without fake and preventStrayRequests';
    }
    if (preg_match('/\bnew\s+\\\\?(?:GuzzleHttp\\\\)?(?:Client|GuzzleClient)\s*\(/', $code) === 1
        && (! str_contains($code, '->setHandler(new MockHandler(') || ! str_contains($code, 'new FakeTargetResolver') || ! str_contains($code, '->clientConfig('))) {
        $failures[] = $path.': Guzzle client without production config, fake resolver and MockHandler transport';
    }
    if (str_contains($code, '->fetch(') && ! str_contains($code, 'new FakeOutboundHttp')) {
        $failures[] = $path.': outbound fetch without the fake HTTP seam';
    }
    // Included test helpers inherit their owning file's clock. Do not scan application dependencies as test code.
    preg_match_all('/require_once\s+__DIR__\.\x27([^\x27]+)\x27\s*;/', $source, $includes);
    foreach ($includes[1] as $include) {
        $resolved = realpath(dirname($absolute).$include);
        if ($resolved === false || $testRoot($resolved) !== $testRoot($absolute)) {
            throw new RuntimeException('Missing Gate helper: '.$include);
        }
        $scan(substr($resolved, strlen($root) + 1), $fixedClock);
    }
};
foreach (['tests/Pest.php', ...$files] as $file) {
    $scan($file);
}
if ($failures !== []) {
    fwrite(STDERR, "Gate determinism guard failed:\n".implode("\n", $failures)."\n");
    exit(1);
}
echo 'Gate determinism guard: '.count($files).' explicit files, '.count($checked)." files including helpers; no forbidden APIs.\n";
