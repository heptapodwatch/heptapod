<?php

declare(strict_types=1);

namespace Heptapod\Export;

/** The one CSV encoding of every export and the client-message attachment: UTF-8 with a byte order mark, Windows line ends, formula-safe cells. */
final class Csv
{
    public const string ByteOrderMark = "\xEF\xBB\xBF";

    /**
     * One encoded line, so callers that bound the file size know it before writing.
     *
     * @param  list<string>  $cells
     */
    public static function line(array $cells): string
    {
        $buffer = fopen('php://memory', 'w+b');
        if ($buffer === false || fputcsv($buffer, array_map(self::safe(...), $cells), ',', '"', '', "\r\n") === false || ! rewind($buffer)) {
            throw new \RuntimeException('Cannot encode a CSV line.');
        }
        $line = stream_get_contents($buffer);
        fclose($buffer);

        return $line === false ? throw new \RuntimeException('Cannot encode a CSV line.') : $line;
    }

    /**
     * Reads a cell of our own CSV back as text: drops the quote safe() added.
     * shortcut: a value that itself started with a quote before a formula sign loses that quote; only Markdown and plain-text views of a stored file read cells back.
     */
    public static function readCell(string $cell): string
    {
        $rest = substr($cell, 1);

        return str_starts_with($cell, "'") && self::safe($rest) !== $rest ? $rest : $cell;
    }

    /**
     * Spreadsheets run a cell that starts with a formula sign, also after blanks or control characters and in its
     * full-width form (= + - @ ＝ ＋ － ＠), or with a control character; a leading quote keeps it text.
     */
    private static function safe(string $text): string
    {
        return preg_match('/^(?:[\x00-\x20]*[=+@\-\x{FF1D}\x{FF0B}\x{FF0D}\x{FF20}]|[\t\r\n])/u', $text) === 1 ? "'".$text : $text;
    }
}
