<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

/** Optional addon projection; the caller must first authorize the site and check. */
interface CheckHistoryProvider
{
    public function history(int $checkId, bool $canViewCapabilities = false): CheckHistoryData;
}
