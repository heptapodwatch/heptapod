<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Actions\StopMaintenance;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class EndSiteMaintenanceController extends Controller
{
    public function __invoke(#[CurrentUser] User $user, Site $site, StopMaintenance $stop): RedirectResponse
    {
        $stop($site, actor: $user);

        return back();
    }
}
