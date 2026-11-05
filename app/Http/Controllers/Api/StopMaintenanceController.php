<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Api;

use Heptapod\Actions\StopMaintenance;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

final class StopMaintenanceController extends Controller
{
    public function __invoke(Request $request, Site $monitor, StopMaintenance $stop): Response
    {
        Gate::authorize('update', $monitor);
        /** @var array{delay_seconds?: int} $data */
        $data = Validator::make($request->all(), ['delay_seconds' => 'sometimes|integer|min:1'])->validate();
        $stop($monitor, $data['delay_seconds'] ?? 0);

        return response()->noContent();
    }
}
