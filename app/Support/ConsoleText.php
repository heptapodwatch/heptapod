<?php

declare(strict_types=1);

namespace Heptapod\Support;

final class ConsoleText
{
    /** Strips ANSI/VT escape sequences and C0/C1 control characters before printing stored text to a terminal. */
    public static function sanitize(string $text): string
    {
        $clean = mb_scrub($text, 'UTF-8');
        $clean = preg_replace([
            // OSC / DCS / SOS / PM / APC sequences (7-bit ESC or 8-bit C1, terminated by BEL, ST, or end of string)
            '/(?:\x1b[\]PX^_]|[\x{0090}\x{0098}\x{009D}\x{009E}\x{009F}])[^\x07\x1b\x{009C}]*(?:\x07|\x1b\\\\|\x{009C}|$)/u',
            // CSI sequences (7-bit ESC [ or 8-bit 0x9B)
            '/(?:\x1b\[|\x{009B})[0-?]*[ -\/]*[@-~]/u',
            // Remaining 2-byte ESC sequences
            '/\x1b[ -\/]*[@-~]/u',
            // Line breaks and tabs become single spaces so table cells and bullet lines stay single-line
            '/[\r\n\t]+/u',
            // Remaining C0/C1 control characters
            '/[\x00-\x1F\x7F-\x{009F}]+/u',
        ], ['', '', '', ' ', ''], $clean);

        return trim($clean ?? '');
    }
}
