<?php

namespace Heptapod\Checks;

use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\SiteCheckSettings;
use Heptapod\Contracts\SiteCheckSettingsReader;
use Heptapod\Models\Check;

final class DatabaseSiteCheckSettingsReader implements SiteCheckSettingsReader
{
    public function read(int|string $siteId, CheckKey $key): ?SiteCheckSettings
    {
        $check = Check::query()->where('site_id', $siteId)->where('type', $key)->first();

        return $check === null ? null : new SiteCheckSettings($check->enabled, $check->executionConfig());
    }
}
