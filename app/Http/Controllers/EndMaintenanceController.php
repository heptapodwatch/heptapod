<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Actions\AuthorizeSiteWrite;
use Heptapod\Actions\EndMaintenanceWindow;
use Heptapod\Http\MaintenanceReturnContext;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class EndMaintenanceController extends Controller
{
    public function __invoke(#[CurrentUser] User $user, Request $request, MaintenancePeriod $window, EndMaintenanceWindow $end, AuthorizeSiteWrite $authorize): RedirectResponse
    {
        $returnContext = MaintenanceReturnContext::parameters($request);
        $authorize->asManager($user, static fn (User $current): MaintenancePeriod => $end($current, $window));

        return to_route('maintenance.index', $returnContext);
    }
}
