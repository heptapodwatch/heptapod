<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

interface SiteCheckSettingsReader
{
    public function read(int|string $siteId, CheckKey $key): ?SiteCheckSettings;
}
