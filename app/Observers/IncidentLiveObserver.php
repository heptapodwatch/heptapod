<?php

declare(strict_types=1);

namespace Heptapod\Observers;

use Heptapod\Events\LiveSignal;
use Heptapod\Models\Incident;

final class IncidentLiveObserver
{
    public function created(Incident $incident): void
    {
        LiveSignal::forSite($incident->site()->getQuery()->firstOrFail(), ['incident' => $incident->id]);
    }

    public function updated(Incident $incident): void
    {
        LiveSignal::forSite($incident->site()->getQuery()->firstOrFail(), ['incident' => $incident->id]);
    }
}
