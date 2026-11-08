<?php

declare(strict_types=1);

// Native PHP tokens and the installed TypeScript parser inspect source, not catalog parity alone.
$root = $argv[1] ?? dirname(__DIR__);
$root = realpath($root) ?: throw new RuntimeException('Translation source root does not exist.');
$catalog = ['en' => [], 'de' => []];
$sources = ['en' => [], 'de' => []];
$references = [];
$dynamic = 0;
$failures = 0;
$files = static function (string $directory): array {
    if (! is_dir($directory)) {
        return [];
    }
    $paths = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile() && ! preg_match('~/(vendor|node_modules|\.git)/~', $file->getPathname())) {
            $paths[] = $file->getPathname();
        }
    }
    sort($paths);

    return $paths;
};
$flatten = static function (array $values, string $prefix = '') use (&$flatten): array {
    $result = [];
    foreach ($values as $key => $value) {
        $name = $prefix.$key;
        if (is_array($value)) {
            $result += $flatten($value, $name.'.');
        } elseif (is_string($value)) {
            $result[$name] = $value;
        }
    }

    return $result;
};
$load = static function (string $file, string $locale, string $prefix = '') use (&$catalog, &$sources, &$failures, $flatten, $root): void {
    $source = file_get_contents($file);
    if ($source === false) {
        throw new RuntimeException('Could not read translation file: '.$file);
    }
    $values = str_ends_with($file, '.json') ? json_decode($source, true, flags: JSON_THROW_ON_ERROR) : require $file;
    if (! is_array($values)) {
        throw new RuntimeException('Translation catalog must be an array: '.$file);
    }
    foreach ($flatten($values, $prefix) as $key => $value) {
        $catalog[$locale][$key] = $value;
        $needle = json_encode($key, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $offset = strpos($source, $needle);
        $sources[$locale][$key] = str_replace($root.'/', '', $file).':'.($offset === false ? 1 : substr_count(substr($source, 0, $offset), "\n") + 1);
        if ($locale === 'de' && preg_match('/Namenskonflikt|Vorfall/iu', $value)) {
            echo $sources[$locale][$key].' forbidden DE glossary in '.$key.': '.$value.PHP_EOL;
            $failures++;
        }
    }
};
foreach (['en', 'de'] as $locale) {
    foreach ([$root.'/lang/'.$locale.'.json', ...glob($root.'/lang/'.$locale.'/*.json'), ...glob($root.'/addons/*/lang/'.$locale.'.json')] as $file) {
        if (is_file($file)) {
            $load($file, $locale);
        }
    }
    foreach ($files($root.'/lang/'.$locale) as $file) {
        if (str_ends_with($file, '.php')) {
            $group = str_replace('/', '.', substr($file, strlen($root.'/lang/'.$locale.'/'), -4));
            $load($file, $locale, $group.'.');
        }
    }
    foreach (glob($root.'/lang/vendor/*/'.$locale.'/*.php') as $file) {
        $load($file, $locale, basename(dirname($file, 2)).'::'.basename($file, '.php').'.');
    }
}
// Package namespaces are declared by their providers; resolve their real language directories.
$vendor = dirname(__DIR__).'/vendor';
foreach (glob($vendor.'/*/*/src/*ServiceProvider.php') as $provider) {
    $source = file_get_contents($provider);
    preg_match_all('/loadTranslationsFrom\(\s*__DIR__\s*\.\s*[\x27\x22]([^\x27\x22]+)[\x27\x22]\s*,\s*[\x27\x22]([^\x27\x22]+)[\x27\x22]/', $source, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        foreach (['en', 'de'] as $locale) {
            foreach (glob(dirname($provider).$match[1].'/'.$locale.'/*.php') as $file) {
                $load($file, $locale, $match[2].'::'.basename($file, '.php').'.');
            }
        }
    }
}
// One word per concept (heptapod-ui-rules glossary): EN Down is DE "ausgefallen", EN uptime is DE "Erreichbarkeit".
$glossary = [
    'EN "down" must read "ausgefallen" in DE, not "nicht erreichbar"' => ['/\bdown\b/i', '/nicht erreichbar/iu'],
    'EN "uptime" must read "Erreichbarkeit" in DE' => ['/\buptime\b/i', '/Verfügbarkeit|Uptime/u'],
    'EN "check" must read "Prüfung" in DE, not "Check"' => ['/\bchecks?\b/i', '/\bChecks?\b|\bCheck-/u'],
    'EN "owner" must read "Inhaber" in DE' => ['/\bowners?\b/i', '/Eigentümer/u'],
    'EN "backup" must read "Sicherung" in DE' => ['/\bbackups?\b/i', '/Backup/u'],
    'EN "worker" must read "Hintergrundprozess" in DE' => ['/\bworkers?\b/i', '/Worker/u'],
    'EN "agent" must read "KI-Agent" in DE' => ['/\bagents?\b/i', '/Agents\b|Coding-Agent/u'],
];
// UI copy names API abilities in words; the raw scope ("checks:read") lives only in the TokenAbilities tooltip.
foreach (['en', 'de'] as $locale) {
    foreach ($catalog[$locale] as $key => $value) {
        // Overflow is "Show all N" (a link to the full list) or "and N more" in running text, never a "+N more" counter.
        if (preg_match('/\+\s*(?:\d+|\{\{\w+\}\}|:\w+)\s+(?:more|weiter)/iu', $value) === 1) {
            echo $sources[$locale][$key].' "+N more" in UI copy '.$key.': '.$value.PHP_EOL;
            $failures++;
        }
        if (preg_match('/\b(?:identity|monitors|checks|incidents|uptime|downtime|tags|maintenance|activity|upcoming):(?:read|write|run|snooze|note)\b|\bagent-read\b/', $value) === 1) {
            echo $sources[$locale][$key].' raw API scope in UI copy '.$key.': '.$value.PHP_EOL;
            $failures++;
        }
    }
}
// Descriptions under a title are one sentence (heptapod-ui-rules Copy); detail goes to the `detail` prop (InfoTooltip).
$sentences = static fn (string $text): int => (int) preg_match_all('/[.!?](?:\s|$)/u', $text);
foreach ($files($root.'/resources/js') as $file) {
    if (! str_ends_with($file, '.tsx') || str_contains($file, '.test.') || str_contains($file, '/pages/dev/')) {
        continue;
    }
    $source = (string) file_get_contents($file);
    preg_match_all('~\bdescription=\{\s*t\(\s*([\x27\x22])((?:\\\\.|(?!\1).)*)\1~s', $source, $props, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    foreach ($props as $prop) {
        $key = stripcslashes($prop[2][0]);
        $tag = preg_match_all('~<([A-Z][A-Za-z]*)\b~', substr($source, 0, $prop[0][1]), $tags) > 0 ? end($tags[1]) : '';
        if (! in_array($tag, ['FormSection', 'SheetForm', 'PageHeader'], true)) {
            continue;
        }
        foreach (['en', 'de'] as $locale) {
            if ($sentences($catalog[$locale][$key] ?? $key) > 1) {
                echo str_replace($root.'/', '', $file).':'.(substr_count(substr($source, 0, $prop[0][1]), "\n") + 1)." {$tag} description has more than one sentence ({$locale}); move the rest to detail: ".$key.PHP_EOL;
                $failures++;
            }
        }
    }
}
// A standalone helper paragraph (a `<p>` or AlertDescription whose text is one t() call) is at most two short sentences: no
// third sentence in either locale and no second sentence once the EN text runs past 120 characters. The rest goes to an
// InfoTooltip after the first sentence (heptapod-ui-rules Copy). Status/alert lines (`role=`) are announcements, not helpers.
foreach ($files($root.'/resources/js') as $file) {
    if (! str_ends_with($file, '.tsx') || str_contains($file, '.test.') || str_contains($file, '/pages/dev/')) {
        continue;
    }
    $source = (string) file_get_contents($file);
    preg_match_all('~<(p|AlertDescription)\b([^>]*)>\s*\{\s*t\(\s*([\x27\x22])((?:\\\\.|(?!\3).)*)\3~s', $source, $helpers, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    foreach ($helpers as $helper) {
        if (str_contains($helper[2][0], 'role=')) {
            continue;
        }
        $key = stripcslashes($helper[4][0]);
        $english = $catalog['en'][$key] ?? $key;
        $tooLong = max($sentences($english), $sentences($catalog['de'][$key] ?? $key)) > 2
            || ($sentences($english) > 1 && mb_strlen($english) > 120);
        if ($tooLong) {
            echo str_replace($root.'/', '', $file).':'.(substr_count(substr($source, 0, $helper[0][1]), "\n") + 1)." helper paragraph <{$helper[1][0]}> runs past two short sentences; keep the first and move the rest to InfoTooltip: ".$key.PHP_EOL;
            $failures++;
        }
    }
}
foreach (['en', 'de'] as $locale) {
    foreach ($catalog[$locale] as $key => $value) {
        if (str_starts_with($key, 'pages.') && str_ends_with($key, '.description') && $sentences($value) > 1) {
            echo $sources[$locale][$key].' page description has more than one sentence: '.$key.PHP_EOL;
            $failures++;
        }
    }
}
// Counts are plural-aware: an EN value with :count/{{count}} before a plural noun needs `_one`/`_other` keys or a "one|many"
// form. Named exceptions say why the singular cannot occur or where the caller already picks the singular key.
$pluralExceptions = [
    'This password reset link will expire in :count minutes.' => 'framework expiry, configured in whole minutes > 1',
    '{{count}} checks · {{warnings}} warnings' => 'design fixture; the caller picks the singular variant',
    '{{count}} checks · {{warnings}} warning' => 'design fixture singular variant',
    'No changes · {{count}} records' => 'design fixture',
    '{{count}} URLs · {{issues}} issues' => 'design fixture',
    '{{changes}} records changed · {{count}} records' => 'design fixture',
    '{{count}} new changes ↑' => 'callers pick "{{count}} new change ↑" for one',
    'Incident export exceeds :count rows. Narrow the filters.' => 'the export limit constant is far above one',
    'sites.list_attention' => 'no counted noun: ":count with failures or warnings"',
    '{{count}} clients' => 'design fixture',
    'mixed_content.readable_only' => 'legacy summary; MixedContentCheck presents readable_only_one/_other',
    'sitemap.summary.urls_404' => 'SitemapIssueSummary picks sitemap.summary.url_404 for one',
    'sitemap.summary.issues' => 'SitemapIssueSummary picks sitemap.summary.issue for one',
];
foreach ($catalog['en'] as $key => $value) {
    if (isset($pluralExceptions[$key]) || preg_match('/_(?:one|other)$/', $key) === 1 || isset($catalog['en'][$key.'_one']) || str_contains($value, '|')) {
        continue;
    }
    if (preg_match('/(?::count|\{\{count\}\}|\{\{formattedCount\}\})\s+(?:[a-z-]+\s+)?([a-z]+s)\b/i', $value, $match) === 1
        && ! in_array(strtolower($match[1]), ['is', 'was', 'has', 'this', 'its', 'as', 'us', 'ms'], true)) {
        echo $sources['en'][$key].' count without plural form in '.$key.': use _one/_other keys or "one|many", or name an exception: '.$value.PHP_EOL;
        $failures++;
    }
}
// German addresses the reader with the formal "Sie": never du, dein or dich (lang/de and addons/*/lang/de.json).
foreach ($catalog['de'] as $key => $value) {
    if (preg_match('/(?<![\p{L}\p{N}_-])(?:du|dich|dir|dein|deine|deinem|deinen|deiner|deines)(?![\p{L}\p{N}_-])/iu', $value, $match) === 1) {
        echo $sources['de'][$key].' informal pronoun "'.$match[0].'" in '.$key.'; use the formal Sie: '.$value.PHP_EOL;
        $failures++;
    }
}
// Gender-fair German: role names (Inhaber, Admin, Mitglied) appear as role labels or "Rolle Inhaber"; prose about people
// uses neutral or verbal wording, never a generic-masculine person noun. Named exceptions are fixed technical field names.
$personNounExceptions = [
    'SMTP username' => 'SMTP-Benutzername is the standard name of the SMTP credential field',
    'channels.field.user_key' => 'Pushover names this credential "User Key"',
];
foreach ($catalog['de'] as $key => $value) {
    if (isset($personNounExceptions[$key]) || $key === 'Owner') {
        continue;
    }
    $prose = preg_replace('/\bRollen? (?:Inhaber|Admin|Mitglied)\b/u', '', $value) ?? $value;
    if (preg_match('/\b(?:Administrator\w*|Benutzer\w*|Nutzer\w*|Empfänger\w*|Mitarbeiter\w*|Entwickler\w*|Betreiber\w*|Eigentümer\w*|Inhaber(?:n|s)?)\b(?!-)/u', $prose, $match) === 1) {
        echo $sources['de'][$key].' generic-masculine person noun "'.$match[0].'" in '.$key.'; name the role ("Rolle Inhaber") or write neutrally: '.$value.PHP_EOL;
        $failures++;
    }
}
foreach ($catalog['de'] as $key => $value) {
    foreach ($glossary as $rule => [$english, $german]) {
        if (preg_match($english, $catalog['en'][$key] ?? '') === 1 && preg_match($german, $value) === 1) {
            echo $sources['de'][$key].' '.$rule.' in '.$key.': '.$value.PHP_EOL;
            $failures++;
        }
    }
}
// One label per destination (heptapod-ui-rules Copy): copy that names a nav destination uses that destination's DE nav label,
// so "Open System health" reads "Systemzustand öffnen", never a second word such as "Systemstatus". The EN side matches the
// destination phrase: the nav title as written (a capitalised proper name, so "recent activity" is not the Activity page) or a
// named alias that also means the destination. The DE side needs a word that starts with the nav label: the label itself,
// an inflection or a compound ("Wartungsfenster"); a label buried inside a longer word does not count.
$navigationSource = (string) file_get_contents($root.'/resources/js/lib/navigation.ts');
preg_match_all("~\btitle:\s*'([^']+)'~", $navigationSource, $navigationTitles);
$navigationTitleExceptions = [
    'Import' => 'the nav label is the verb "Importieren"; prose names the operation "Import" (noun), not the destination',
];
/** EN phrases (case-insensitive) that name a destination without its exact nav title. */
$navigationAliases = [];
$englishTitles = array_map(static fn (string $key): string => $catalog['en'][$key] ?? $key, $navigationTitles[1]);
foreach (array_unique($navigationTitles[1]) as $title) {
    $english = $catalog['en'][$title] ?? $title;
    $german = $catalog['de'][$title] ?? null;
    if ($german === null || isset($navigationTitleExceptions[$english])) {
        continue;
    }
    $phrases = ['(?<![\w-])'.preg_quote($english, '/').'(?![\w-])'];
    foreach ($navigationAliases[$english] ?? [] as $alias) {
        $phrases[] = '(?i:(?<![\w-])'.$alias.'(?![\w-]))';
    }
    $destination = '/'.implode('|', $phrases).'/u';
    $label = '/(?<!\p{L})'.preg_quote($german, '/').'/u';
    foreach ($catalog['en'] as $key => $value) {
        if ($key === $title || ! isset($catalog['de'][$key]) || preg_match($destination, $value) !== 1
            || preg_match($label, $catalog['de'][$key]) === 1) {
            continue;
        }
        echo $sources['de'][$key].' nav destination "'.$english.'" must read "'.$german.'" in DE ('.$key.'): '.$catalog['de'][$key].PHP_EOL;
        $failures++;
    }
}
foreach ([...array_keys($navigationTitleExceptions), ...array_keys($navigationAliases)] as $title) {
    if (! in_array($title, $englishTitles, true)) {
        echo 'resources/js/lib/navigation.ts nav-title exception or alias "'.$title.'" names no destination'.PHP_EOL;
        $failures++;
    }
}
$decode = static function (string $literal): string {
    $body = substr($literal, 1, -1);

    return $literal[0] === "'" ? str_replace(['\\\\', "\\'"], ['\\', "'"], $body) : stripcslashes($body);
};
foreach (['app', 'addons', 'resources/views'] as $directory) {
    foreach ($files($root.'/'.$directory) as $file) {
        if (! str_ends_with($file, '.php')) {
            continue;
        }
        $source = file_get_contents($file);
        $relative = substr($file, strlen($root) + 1);
        if (str_ends_with($file, '.blade.php')) {
            $source = preg_replace_callback('/\{\{--[\s\S]*?--\}\}/', static fn (array $match): string => preg_replace('/[^\n]/', ' ', $match[0]), $source);
            preg_match_all('/(?<![\w])(?:t|__|trans|@lang)\s*\(\s*/', $source, $calls, PREG_OFFSET_CAPTURE);
            foreach ($calls[0] as [$call, $offset]) {
                $argument = substr($source, $offset + strlen($call));
                if (preg_match('/^([\x27\x22])((?:\\\\.|(?!\1)[\s\S])*)\1\s*(?=[,)])/', $argument, $match)) {
                    $literal = $match[1].$match[2].$match[1];
                    $literalTokens = token_get_all('<?php '.$literal);
                    if (! preg_match('/^t\s*\(/', $call) && (! is_array($literalTokens[1]) || $literalTokens[1][0] !== T_CONSTANT_ENCAPSED_STRING)) {
                        $dynamic++;

                        continue;
                    }
                    $references[] = ['file' => $relative, 'line' => substr_count(substr($source, 0, $offset), "\n") + 1, 'key' => $decode($literal)];
                } else {
                    $dynamic++;
                }
            }

            continue;
        }
        $tokens = token_get_all($source);
        foreach ($tokens as $index => $token) {
            if (! is_array($token) || ! in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                || ! in_array(basename(str_replace('\\', '/', $token[1])), ['t', '__', 'trans'], true)) {
                continue;
            }
            $next = $index + 1;
            $skip = static function () use (&$next, $tokens): void {
                while (isset($tokens[$next]) && is_array($tokens[$next]) && in_array($tokens[$next][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    $next++;
                }
            };
            $skip();
            if (($tokens[$next] ?? null) !== '(') {
                continue;
            }
            $next++;
            $skip();
            $argumentIndex = $next;
            if (is_array($tokens[$next] ?? null) && $tokens[$next][0] === T_STRING && in_array($tokens[$next][1], ['key', 'id'], true)) {
                $next++;
                $skip();
                if (($tokens[$next] ?? null) === ':') {
                    $next++;
                    $skip();
                } else {
                    $next = $argumentIndex;
                }
            }
            $argument = $tokens[$next] ?? null;
            $next++;
            $skip();
            if (is_array($argument) && $argument[0] === T_CONSTANT_ENCAPSED_STRING && in_array($tokens[$next] ?? null, [',', ')'], true)) {
                $references[] = ['file' => $relative, 'line' => $token[2], 'key' => $decode($argument[1])];
            } else {
                $dynamic++;
            }
        }
    }
}
$command = ['node', __DIR__.'/translation-keys.mjs', $root];
$process = proc_open($command, [1 => ['pipe', 'w'], 2 => STDERR], $pipes);
if (! is_resource($process)) {
    throw new RuntimeException('Could not start TypeScript translation parser.');
}
$output = stream_get_contents($pipes[1]);
fclose($pipes[1]);
if (proc_close($process) !== 0) {
    throw new RuntimeException('TypeScript translation parser failed.');
}
$javascript = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
$dynamic += $javascript['dynamic'];
$references = [...$references, ...$javascript['references']];
foreach ($references as $reference) {
    foreach (['en', 'de'] as $locale) {
        $key = $reference['key'];
        if (! isset($catalog[$locale][$key]) && ! (isset($catalog[$locale][$key.'_one']) && isset($catalog[$locale][$key.'_other']))) {
            echo $reference['file'].':'.$reference['line'].' missing '.$locale.' translation: '.$key.PHP_EOL;
            $failures++;
        }
    }
}
echo 'Translations: '.count($references).' literal keys checked; '.$dynamic.' dynamic calls skipped; '.$failures.' failures.'.PHP_EOL;
exit($failures === 0 ? 0 : 1);
