<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Heptapod\Contracts\Findings\FindingsView;
use Heptapod\Contracts\Schema\ValidatedConfig;

/** Configuration and ignored findings for a broken-links check. */
interface BrokenLinkSettings extends CheckHistoryProvider
{
    /** @param list<string> $entries
     * @return list<string>
     */
    public function editIgnoredUrls(array $entries, string $url, bool $ignore): array;

    public function foldExcludedUrls(ValidatedConfig $config): ValidatedConfig;

    public function history(int $checkId, bool $canViewCapabilities = false, FindingsView $view = FindingsView::Current): CheckHistoryData;
}
