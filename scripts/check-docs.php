<?php

declare(strict_types=1);

// Style guard for the public docs; the rules are in .agents/skills/heptapod-docs/SKILL.md.
$root = dirname(__DIR__);
// The operator reference is not synced to the website.
$internal = ['docs/deploy/operations.md'];
// Pages written before the style rules skip the sentence and word checks. Remove a page here when you rewrite it.
$notYetRewritten = [
    'docs/api.md',
    'docs/checks/sitemap.md',
    'docs/deploy/hetzner.md',
    'docs/notifications.md',
    'docs/ui.md',
    'docs/webhook-receivers.md',
];
$maxWords = 30;
$banned = '/\b(simply|just|easy|easily|please note|note that|in order to|now|no longer|previously|used to|legacy)\b/i';

$pages = new RegexIterator(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/docs')), '/\.md$/');
$failures = [];
$checked = 0;
foreach ($pages as $file) {
    $path = substr((string) $file, strlen($root) + 1);
    if (in_array($path, $internal, true)) {
        continue;
    }
    $checked++;
    $markdown = (string) file_get_contents((string) $file);
    if (preg_match('/\A---\n(.*?)\n---\n(.*)\z/s', $markdown, $parts) !== 1) {
        $failures[] = $path.': needs front matter with title and description';

        continue;
    }
    if (preg_match('/^description: (\S.{0,159})$/m', $parts[1], $description) !== 1 || preg_match('/[.!?] \S/', $description[1]) === 1) {
        $failures[] = $path.': description must be one sentence of at most 160 characters on one line';
    }
    if (in_array($path, $notYetRewritten, true)) {
        continue;
    }
    $offset = substr_count($parts[1], "\n") + 3;
    $inCode = false;
    foreach (explode("\n", $parts[2]) as $index => $line) {
        if (str_starts_with(ltrim($line), '```')) {
            $inCode = ! $inCode;

            continue;
        }
        if ($inCode) {
            continue;
        }
        // UI labels, quoted copy and code keep their exact wording, so they count as one word and skip the word list.
        $prose = preg_replace(['/`[^`]*`/', '/\*\*[^*]+\*\*/', '/"[^"]*"|“[^”]*”/', '/\[([^\]]*)\]\([^)]*\)/'], ['x', 'x', 'x', '$1'], $line);
        $where = $path.':'.($index + $offset);
        if (preg_match($banned, $prose, $word) === 1) {
            $failures[] = $where.': avoid "'.$word[1].'"';
        }
        // shortcut: one paragraph per line, as in docs/ today; join wrapped lines if a page starts wrapping prose.
        foreach (preg_split('/(?<=[.!?])\s+|\s\|\s/', $prose) as $sentence) {
            $words = str_word_count($sentence, 0, '0123456789-–_/{}');
            if ($words > $maxWords) {
                $failures[] = $where.': sentence has '.$words.' words (max '.$maxWords.')';
            }
        }
    }
}
if ($failures !== []) {
    fwrite(STDERR, "Docs style check failed (.agents/skills/heptapod-docs):\n".implode("\n", $failures)."\n");
    exit(1);
}
echo 'Docs style: '.$checked." pages checked.\n";
