<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Actions\StopMaintenance;
use Heptapod\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

final class EndSiteMaintenanceController extends Controller
{
    public function __invoke(Site $site, StopMaintenance $stop): RedirectResponse
    {
        Gate::authorize('update', $site);

        $stop($site);

        return back();
    }
}
