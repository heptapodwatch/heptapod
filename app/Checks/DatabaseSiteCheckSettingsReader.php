<?php

namespace Heptapod\Checks;

use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\SiteCheckSettings;
use Heptapod\Contracts\SiteCheckSettingsReader;
use Heptapod\Models\Check;
use Heptapod\Support\RedactionContext;

final class DatabaseSiteCheckSettingsReader implements SiteCheckSettingsReader
{
    public function read(int|string $siteId, CheckKey $key): ?SiteCheckSettings
    {
        $check = Check::query()->where('site_id', $siteId)->where('type', $key)->first();

        if ($check === null) {
            return null;
        }
        $config = $check->executionConfig();
        $site = $key === CheckKey::BrokenLinks && ($config['whitelisted_urls'] ?? []) !== [] ? $check->site()->getQuery()->firstOrFail() : null;

        return new SiteCheckSettings($check->enabled, $config, $site->url ?? '', $site === null ? [] : RedactionContext::forSite($site, null)->secrets);
    }
}
