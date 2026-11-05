<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Http;

interface SameSite
{
    /** Crawl coverage, including default-port HTTP to HTTPS upgrades, but never HTTPS downgrades. */
    public function isSameSite(string $siteUrl, string $candidateUrl): bool;
}
