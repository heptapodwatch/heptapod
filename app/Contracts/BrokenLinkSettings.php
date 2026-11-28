<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Heptapod\Contracts\Findings\FindingsView;

/** Editing and viewing a broken-links check’s ignored URLs. */
interface BrokenLinkSettings extends CheckHistoryProvider
{
    /** @param list<string> $entries
     * @return list<string>
     */
    public function editIgnoredUrls(array $entries, string $url, bool $ignore): array;

    public function history(int $checkId, bool $canViewCapabilities = false, FindingsView $view = FindingsView::Current): CheckHistoryData;
}
