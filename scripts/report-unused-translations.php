<?php

declare(strict_types=1);

// Print-only report of JSON catalog keys that no source names. It never fails: a key is reported only when no string
// literal in production code, tests, Blade, config, migrations or vendor translation calls equals it (or its plural
// base), and neither a declared dynamic prefix/suffix nor an observed concatenation head/tail could build it.
$root = realpath($argv[1] ?? dirname(__DIR__)) ?: throw new RuntimeException('Translation source root does not exist.');

/** Keys built at runtime or stored as data, which no source literal can name. Each reason names a construction site. */
$dynamicPrefixes = [
    'causes.' => 'stored run/incident summaries render through __($summary); FetchFailure and TlsFailureReason build causes.outbound.*/causes.tls.*',
    'checks.' => 'check-type keys: t(`checks.${key}.label`), __(\'checks.\'.$type->value.\'.label\') in commands and mail',
    'errors.' => 'errors/error.blade.php renders __(\'errors.\'.$status.\'.title|description\') per HTTP status',
    'client_messages.' => 'ClientMessageComposer builds client_messages.subject.* from the check type',
    'system_mail.' => 'SystemIssueMailer builds system_mail.severity|resolution|detail.* from the issue kind',
    'notifications.' => 'category, event-type and component keys built from enum values (\'notifications.category.\'.$value)',
    'recipient_settings.' => 'notification-explanation and channel-sheet build recipient_settings.category.${category}.*',
    'alert_sensitivity.preset.' => 't(`alert_sensitivity.preset.${value.preset}.label`)',
    'lighthouse.' => 't(`lighthouse.${category}`) and lighthouse.stage./cause./presentation. heads in the addon',
    'sitemap.' => 'sitemap.status./not_verifiable./not_checked./presentation. heads built from issue kinds (addon)',
    'crawl.' => 'crawl.not_verifiable./settings./start_page_unreachable. heads and t(`${translationPrefix}.…`) (addon)',
    'broken_links.' => 'broken_links.presentation. head built from finding results (addon)',
    'application_health.' => 'ApplicationHealthCheck returns \'application_health.\'.$reason as the run summary (addon)',
    'domain.' => 'DomainCheck emits AddonEvent(\'domain.\'.$event) event keys (addon)',
    'dns.guidance.' => 'resources/js/components/dns-comparison.tsx builds guidance keys with type.replace(\'dns.issue.\', \'dns.guidance.\')',
    'health.' => 't(`health.reason.${attempt.reason}`) and t(`health.probe.${result}`)',
    'freshness.' => 'FreshnessSuppression builds freshness.suppressed.*; t(`freshness.loss.${cause}`)',
    'disk.read_failure.' => 'disk read failure reasons built from DiskReadException kinds',
    'import.' => 't(`import.${row.operation}`)',
    'incidents.resolution.' => 'IncidentResolutionReason::labelKey()',
    'explanation.' => 't(`explanation.lane.${lane.key}`) and t(`explanation.kind.${recipient_kind}`)',
    'reminders.reason.' => 't(`reminders.reason.${reason}`)',
    'sites.not_ok_' => 't(`sites.not_ok_${state}`)',
    'site.checks.' => 't(`site.checks.${state}`)',
    'add_site.purpose.' => 't(`add_site.purpose.${purpose}`)',
    'icons.' => 'FetchSiteIcon returns \'icons.\'.$failure->kind->value',
    'emails.' => 'AlertMailBuilder translates \'emails.\'.$state and \'emails.\'.$state.\'_subject\' (addon)',
    'runs.note.' => 'RunsReader builds \'runs.note.\'.$note from the run outcome',
    'certificate_health.' => 'certificate_health.check./short. heads built from certificate findings (addon)',
    'mixed_content.' => 'CrawlEvaluation builds \'mixed_content.nofollow.\'.$outcome (addon)',
];
$dynamicSuffixes = [
    '.label' => 'app/Http/Resources/OhDear/CheckResource.php translates $check->type->value.\'.label\' for registered check types',
    '.not_verifiable.group' => 'crawl/sitemap share one component: t(`${translationPrefix}.not_verifiable.group`)',
];
$pluralSuffix = '/_(?:zero|one|two|few|many|other)$/';

$catalog = [];
foreach (['lang/en/*.json', 'addons/*/lang/en.json'] as $pattern) {
    foreach (glob($root.'/'.$pattern) as $file) {
        $relative = substr($file, strlen($root) + 1);
        foreach (array_keys(json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR)) as $key) {
            $catalog[(string) $key] = $relative;
        }
    }
}

$files = static function (string $directory, array $extensions, array $skip = []): iterable {
    if (! is_dir($directory)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        $path = $file->getPathname();
        if (! $file->isFile() || preg_match('~/(node_modules|\.git)/~', $path) === 1) {
            continue;
        }
        foreach ($skip as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                continue 2;
            }
        }
        foreach ($extensions as $extension) {
            if (str_ends_with($path, $extension)) {
                yield $path;

                continue 2;
            }
        }
    }
};
$decode = static function (string $literal): string {
    $body = substr($literal, 1, -1);

    return $literal[0] === "'" ? str_replace(['\\\\', "\\'"], ['\\', "'"], $body) : stripcslashes($body);
};

/** @var array<string, array<string, true>> $literals production|tests => literal => true */
$literals = ['production' => [], 'tests' => []];
/** @var array<string, string> $heads concatenation head => first file */
$heads = [];
$tails = [];
$phpLiterals = static function (string $source, string $file, string $bucket) use (&$literals, &$heads, &$tails, $decode): void {
    $tokens = array_values(array_filter(token_get_all($source), static fn (array|string $token): bool => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
    foreach ($tokens as $index => $token) {
        if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
            $value = $decode($token[1]);
            $literals[$bucket][$value] = true;
            // 'a'.'b' names the key "ab" (the framework mail view splits one key over two lines).
            $next = $tokens[$index + 2] ?? null;
            if (($tokens[$index + 1] ?? null) === '.' && is_array($next) && $next[0] === T_CONSTANT_ENCAPSED_STRING) {
                $literals[$bucket][$value.$decode($next[1])] = true;
            }
            if (($tokens[$index + 1] ?? null) === '.') {
                $heads[$value] ??= $file;
            }
            if (($tokens[$index - 1] ?? null) === '.') {
                $tails[$value] ??= $file;
            }
        } elseif ($token === '"') {
            // Interpolated "prefix.{$value}" strings: the leading literal part is a head.
            $part = $tokens[$index + 1] ?? null;
            if (is_array($part) && $part[0] === T_ENCAPSED_AND_WHITESPACE) {
                $heads[$part[1]] ??= $file;
            }
        }
    }
};
$relative = static fn (string $path): string => substr($path, strlen($root) + 1);
$scan = static function (string $file, string $label, string $bucket) use (&$literals, &$heads, $phpLiterals, $decode): void {
    $source = (string) file_get_contents($file);
    if (! str_ends_with($file, '.blade.php')) {
        $phpLiterals($source, $label, $bucket);

        return;
    }
    // Blade echoes are not PHP tokens. Quote kinds nest in HTML attributes, so each kind is matched on its own.
    foreach (["'", '"'] as $quote) {
        preg_match_all('/'.$quote.'((?:\\\\.|[^'.$quote.'\\\\])*)'.$quote.'(\s*\.)?/s', $source, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $value = $decode($quote.$match[1].$quote);
            $literals[$bucket][$value] = true;
            if (isset($match[2])) {
                $heads[$value] ??= $label;
            }
        }
    }
    // Echo and directive bodies are PHP expressions; tokenizing them also joins 'a'.'b' concatenations.
    preg_match_all('/\{\{(.*?)\}\}|\{!!(.*?)!!\}|@(?:lang|php)\s*(\((?:[^()]++|(?3))*\))/s', $source, $expressions, PREG_SET_ORDER);
    foreach ($expressions as $expression) {
        $phpLiterals('<?php '.($expression[3] ?? $expression[2] ?? $expression[1]).';', $label, $bucket);
    }
};
$sourceRoots = ['app', 'addons', 'resources/views', 'config', 'database', 'routes', 'bootstrap', 'tests'];
foreach ($sourceRoots as $directory) {
    foreach ($files($root.'/'.$directory, ['.php'], ['~/addons/[^/]+/lang/~']) as $file) {
        $scan($file, $relative($file), preg_match('~/tests/~', $file) === 1 ? 'tests' : 'production');
    }
    foreach ($files($root.'/'.$directory, ['.json'], ['~/addons/[^/]+/lang/~']) as $file) {
        $bucket = preg_match('~/tests/~', $file) === 1 ? 'tests' : 'production';
        $decoded = json_decode((string) file_get_contents($file), true);
        if (! is_array($decoded)) {
            continue;
        }
        array_walk_recursive($decoded, static function (mixed $value, mixed $key) use (&$literals, $bucket): void {
            $literals[$bucket][(string) $key] = true;
            if (is_string($value)) {
                $literals[$bucket][$value] = true;
            }
        });
    }
}
// Package views, mails, notifications and exception messages (rendered through __($message)) name catalog keys too.
// laravel-lang only ships translations, so its literals are catalog sources, not uses.
foreach ($files(dirname(__DIR__).'/vendor', ['.php'], ['~/vendor/laravel-lang/~']) as $file) {
    $scan($file, 'vendor', 'production');
}
$command = ['node', __DIR__.'/translation-literals.mjs', $root];
$process = proc_open($command, [1 => ['pipe', 'w'], 2 => STDERR], $pipes);
if (! is_resource($process)) {
    throw new RuntimeException('Could not start the TypeScript literal scanner.');
}
$output = stream_get_contents($pipes[1]);
fclose($pipes[1]);
if (proc_close($process) !== 0) {
    throw new RuntimeException('TypeScript literal scanner failed.');
}
$javascript = json_decode((string) $output, true, flags: JSON_THROW_ON_ERROR);
foreach ($javascript['literals'] as $value) {
    $literals['production'][$value] = true;
}
foreach ($javascript['testLiterals'] as $value) {
    $literals['tests'][$value] = true;
}
foreach ($javascript['heads'] as $value) {
    $heads[$value] ??= 'resources/js';
}
foreach ($javascript['tails'] as $value) {
    $tails[$value] ??= 'resources/js';
}

$isKeyPrefix = static fn (string $part): bool => preg_match('/^[a-z0-9_]+(?:\.[a-z0-9_]+)*[._]$/', $part) === 1;
$isKeySuffix = static fn (string $part): bool => preg_match('/^[._][a-z0-9_]+(?:\.[a-z0-9_]+)*$/', $part) === 1;
$observedPrefixes = array_filter(array_keys($heads), static fn (string $head): bool => $isKeyPrefix($head));
$observedSuffixes = array_filter(array_keys($tails), static fn (string $tail): bool => $isKeySuffix($tail));
$covers = static function (string $key, iterable $prefixes, iterable $suffixes): bool {
    foreach ($prefixes as $prefix) {
        if (str_starts_with($key, (string) $prefix)) {
            return true;
        }
    }
    foreach ($suffixes as $suffix) {
        if (str_ends_with($key, (string) $suffix)) {
            return true;
        }
    }

    return false;
};

// laravel-lang publishes these sources into lang/; `php artisan lang:update` re-adds any key deleted here.
$published = [];
foreach ([...glob(dirname(__DIR__).'/vendor/laravel-lang/*/source/*.json'), ...glob(dirname(__DIR__).'/vendor/laravel-lang/*/source/*/*/*.json'), ...glob(dirname(__DIR__).'/vendor/laravel-lang/*/source/*/*.json')] as $file) {
    foreach (array_keys(json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR)) as $key) {
        $published[(string) $key] = true;
    }
}
$counts = ['production' => 0, 'tests only' => 0, 'laravel-lang published' => 0, 'declared dynamic' => 0, 'observed dynamic, undeclared' => 0, 'unused' => 0];
$unused = [];
$undeclared = [];
$protected = [];
foreach ($catalog as $key => $file) {
    $base = preg_replace($pluralSuffix, '', $key) ?? $key;
    if (isset($literals['production'][$key]) || isset($literals['production'][$base])) {
        $counts['production']++;
    } elseif (isset($literals['tests'][$key]) || isset($literals['tests'][$base])) {
        $counts['tests only']++;
    } elseif (isset($published[$key])) {
        $counts['laravel-lang published']++;
    } elseif ($covers($key, array_keys($dynamicPrefixes), array_keys($dynamicSuffixes))) {
        $counts['declared dynamic']++;
        $protected[] = $key;
    } elseif ($covers($key, $observedPrefixes, $observedSuffixes)) {
        $counts['observed dynamic, undeclared']++;
        $undeclared[] = $key;
    } else {
        $counts['unused']++;
        $unused[] = $file."\t".(preg_match('/[\x00-\x1f]/', $key) === 1 ? json_encode($key, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : $key);
    }
}
echo 'Translation keys (EN JSON catalogs): '.count($catalog).PHP_EOL;
foreach ($counts as $label => $count) {
    echo '  '.$label.': '.$count.PHP_EOL;
}
foreach ([...$dynamicPrefixes, ...$dynamicSuffixes] as $part => $reason) {
    $kept = count(array_filter($protected, static fn (string $key): bool => str_starts_with($key, $part) || str_ends_with($key, $part)));
    echo 'allowlisted '.$part.' ('.$kept.' keys) — '.$reason.PHP_EOL;
}
foreach ($dynamicPrefixes as $prefix => $reason) {
    if (array_filter($observedPrefixes, static fn (string $head): bool => str_starts_with($head, $prefix) || str_starts_with($prefix, $head)) === []) {
        echo 'allowlisted prefix without an observed source head: '.$prefix.PHP_EOL;
    }
}
foreach ($undeclared as $key) {
    $matched = array_values(array_filter([...$observedPrefixes, ...$observedSuffixes], static fn (string $part): bool => str_starts_with($key, $part) || str_ends_with($key, $part)));
    echo 'undeclared dynamic match (kept; add its prefix to the allowlist): '.$key.' via '.implode(', ', array_map(static fn (string $part): string => $part.' ('.($heads[$part] ?? $tails[$part]).')', $matched)).PHP_EOL;
}
sort($unused);
foreach ($unused as $line) {
    echo 'unused: '.$line.PHP_EOL;
}
