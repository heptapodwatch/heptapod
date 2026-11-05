<?php

declare(strict_types=1);

namespace Heptapod\Observers;

use Heptapod\Enums\SiteIconBacking;
use Heptapod\Events\LiveSignal;
use Heptapod\Jobs\RefreshSiteIcon;
use Heptapod\Models\Site;

final class SiteLiveObserver
{
    public function created(Site $site): void
    {
        RefreshSiteIcon::dispatch($site->id)->afterCommit();
        LiveSignal::forSite($site);
    }

    public function updated(Site $site): void
    {
        $clientChanged = $site->wasChanged('client_id');
        /** @var int $previousClientId */
        $previousClientId = $site->getOriginal('client_id');
        if ($site->wasChanged('url')) {
            $reset = ['icon_attempted_at' => null, 'icon_manual_result' => null];
            // Another host's icon would misidentify the site; show letters until the new host's icon is fetched.
            /** @var string $previousUrl */
            $previousUrl = $site->getOriginal('url');
            if (parse_url($previousUrl, PHP_URL_HOST) !== parse_url($site->url, PHP_URL_HOST)) {
                $reset['icon_hash'] = null;
                $reset['icon_backing'] = SiteIconBacking::None;
            }
            $site->forceFill($reset)->saveQuietly();
            RefreshSiteIcon::dispatch($site->id)->afterCommit();
        }
        LiveSignal::forSite($site);
        if ($clientChanged) {
            LiveSignal::forSite($site, clientId: $previousClientId);
        }
    }

    public function deleted(Site $site): void
    {
        LiveSignal::forSite($site);
    }
}
