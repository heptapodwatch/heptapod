<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Actions\AuthorizeSiteWrite;
use Heptapod\Actions\EndMaintenanceWindow;
use Heptapod\Http\MaintenanceReturnContext;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class EndMaintenanceController extends Controller
{
    public function __invoke(Request $request, MaintenancePeriod $window, EndMaintenanceWindow $end, AuthorizeSiteWrite $authorize): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($user->hasAnyRole(['Owner', 'Admin']), 403);
        $returnContext = MaintenanceReturnContext::parameters($request);
        $authorize->asManager($user, static fn (): MaintenancePeriod => $end($user, $window));

        return to_route('maintenance.index', $returnContext);
    }
}
