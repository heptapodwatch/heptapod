<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

/**
 * Engines write dotted message keys; older rows retained free text in the same summary column. The app renders free
 * text verbatim (i18next shows a missing key as itself), so key shape, not catalog membership, decides: a dotted key
 * that is missing from the catalog is a maintenance bug and still fails loud.
 */
final class SummaryKey
{
    public static function isFreeText(string $summary): bool
    {
        return preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)+$/', $summary) !== 1;
    }
}
