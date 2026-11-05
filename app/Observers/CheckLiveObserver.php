<?php

declare(strict_types=1);

namespace Heptapod\Observers;

use Heptapod\Events\LiveSignal;
use Heptapod\Models\Check;

final class CheckLiveObserver
{
    public function created(Check $check): void
    {
        LiveSignal::forSite($check->site()->getQuery()->firstOrFail(), ['check' => $check->id]);
    }

    public function updated(Check $check): void
    {
        if ($check->wasChanged(['site_id', 'type', 'config', 'config_secrets', 'enabled', 'interval_seconds', 'failure_threshold'])) {
            LiveSignal::forSite($check->site()->getQuery()->firstOrFail(), ['check' => $check->id]);
        }
    }

    public function deleted(Check $check): void
    {
        LiveSignal::forSite($check->site()->getQuery()->firstOrFail(), ['check' => $check->id]);
    }
}
