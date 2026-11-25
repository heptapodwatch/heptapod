<?php

declare(strict_types=1);

namespace Heptapod\Support;

/**
 * Reads the parts of a Heptapod release note an Owner needs before upgrading, as plain text.
 * The notes follow one shape (`.agents/skills/heptapod-release-notes`): a headline sentence, then `## ` sections
 * with `- ` bullets. The body comes from the network, so nothing but capped plain text leaves this class.
 */
final class ReleaseNotes
{
    private const int MaxBodyBytes = 65536;

    private const int MaxHeadline = 280;

    private const int MaxItem = 400;

    private const int MaxItems = 8;

    /** @return array{headline: string|null, before: list<string>, security: list<string>} */
    public static function parse(string $body): array
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", mb_scrub(substr($body, 0, self::MaxBodyBytes), 'UTF-8')));
        $headline = null;
        $section = null;
        $before = [];
        $security = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s{0,3}#{1,6}\s+(.*)$/u', $line, $heading) === 1) {
                $section = mb_strtolower(self::text($heading[1], self::MaxHeadline));

                continue;
            }
            if (preg_match('/^\s*[-*+]\s+(.*)$/u', $line, $bullet) === 1) {
                $item = self::text($bullet[1], self::MaxItem);
                if ($item === '' || $section === 'upgrade') {
                    continue;
                }
                if ($section === 'before you upgrade') {
                    $before[] = $item;
                }
                // The prefix only classifies the item; the list heading already says Security.
                $prefixed = preg_match('/^security:\s*/i', $item, $prefix) === 1;
                if ($section === 'security' || $prefixed) {
                    $security[] = $prefixed ? ucfirst(substr($item, strlen($prefix[0]))) : $item;
                }

                continue;
            }
            if ($section === null && $headline === null && trim($line) !== '') {
                $headline = self::text($line, self::MaxHeadline);
            }
        }

        return ['headline' => $headline === '' ? null : $headline,
            'before' => array_slice($before, 0, self::MaxItems), 'security' => array_slice($security, 0, self::MaxItems)];
    }

    /** Markdown and HTML to one line of plain text. */
    private static function text(string $markdown, int $limit): string
    {
        $text = strip_tags($markdown);
        // Images and links keep their visible text only.
        $text = (string) preg_replace('/!?\[([^\]]*)\]\((?:[^()]|\([^()]*\))*\)/u', '$1', $text);
        $text = str_replace(['**', '__', '~~', '`'], '', $text);
        // Control characters and bidirectional overrides could disguise the text.
        $text = (string) preg_replace('/[\p{Cc}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', ' ', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > $limit ? rtrim(mb_substr($text, 0, $limit - 1)).'…' : $text;
    }
}
