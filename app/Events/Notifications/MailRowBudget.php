<?php

declare(strict_types=1);

namespace Heptapod\Events\Notifications;

/**
 * Aggregate byte budget for the rows of an automatic list mail (digest, catch-up). The rows share it, so twenty long rows
 * stay within the 16000-byte body budget of alert mail together with the headline, notes and footer.
 */
final class MailRowBudget
{
    public const int Rows = 10000;

    /** The bytes one row may use when $rows rows are shown, never more than the 1000-byte value limit. */
    public static function perRow(int $rows): int
    {
        return min(1000, intdiv(self::Rows, max(1, $rows)));
    }

    /**
     * Shortens text to at most $bytes by cutting its middle. The end stays as long as possible, because that is where
     * same-named site labels differ (path, port, #id). Never splits a UTF-8 sequence.
     */
    public static function middle(string $text, int $bytes): string
    {
        if (strlen($text) <= $bytes) {
            return $text;
        }
        $room = max(0, $bytes - strlen('…'));
        $head = mb_strcut($text, 0, intdiv($room, 4), 'UTF-8');
        $tail = '';
        foreach (array_reverse(mb_str_split($text)) as $char) {
            if (strlen($head) + strlen($char.$tail) > $room) {
                break;
            }
            $tail = $char.$tail;
        }

        return $head.'…'.$tail;
    }

    /**
     * Fits list rows into the budget. Labels keyed by row stay unique: a label whose shortened form would collide with
     * another is replaced by its short fallback (which carries the site ID).
     *
     * @param  list<array{text: string, meta: string, key: int}>  $rows
     * @param  array<int, string>  $shortLabels  short unique labels by row key, used when a shortened label collides
     * @return list<array{text: string, meta: string}>
     */
    public static function fit(array $rows, array $shortLabels): array
    {
        $row = self::perRow(count($rows));
        $fitted = array_map(static fn (array $item): array => ['text' => self::middle($item['text'], max(16, $row - strlen($item['meta']) - 8)),
            'meta' => self::middle($item['meta'], intdiv($row, 2)), 'key' => $item['key']], $rows);
        $seen = [];
        foreach ($fitted as $item) {
            $seen[$item['text']][$item['key']] = true;
        }

        return array_map(static fn (array $item): array => [
            'text' => count($seen[$item['text']]) > 1 ? self::middle($shortLabels[$item['key']] ?? throw new \LogicException('A shortened row needs its fallback label.'), max(16, $row - strlen($item['meta']) - 8)) : $item['text'],
            'meta' => $item['meta'],
        ], $fitted);
    }
}
