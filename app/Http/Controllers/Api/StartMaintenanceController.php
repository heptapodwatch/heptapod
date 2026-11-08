<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Api;

use Dedoc\Scramble\Attributes\Group;
use Heptapod\Actions\StartMaintenance;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Http\Resources\OhDear\MaintenancePeriodResource;
use Heptapod\Models\Site;
use Heptapod\Support\Api\Idempotency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

#[Group('Maintenance')]
final class StartMaintenanceController extends Controller
{
    public function __invoke(Request $request, Site $monitor, StartMaintenance $start, Idempotency $idempotency): JsonResponse
    {
        Gate::authorize('update', $monitor);
        /** @var array{stop_maintenance_after_seconds?: int, name?: string|null} $data */
        $data = Validator::make($request->all(), [
            'stop_maintenance_after_seconds' => 'sometimes|integer|min:5',
            'name' => 'nullable|string|max:255',
        ])->validate();

        $effective = ['name' => $data['name'] ?? null, 'stop_maintenance_after_seconds' => $data['stop_maintenance_after_seconds'] ?? 3600];

        // A replay returns the first window with its original start and end, not a window relative to the retry.
        return $idempotency->run($request, 'maintenance.start', 'site:'.$monitor->id, $effective, static fn (): JsonResponse => new MaintenancePeriodResource(
            $start($monitor, $effective['name'], $effective['stop_maintenance_after_seconds']))->response()->setStatusCode(200));
    }
}
