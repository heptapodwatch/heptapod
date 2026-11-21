<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Actions\StopMaintenance;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class EndSiteMaintenanceController extends Controller
{
    public function __invoke(Request $request, Site $site, StopMaintenance $stop): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $stop($site, actor: $user);

        return back();
    }
}
