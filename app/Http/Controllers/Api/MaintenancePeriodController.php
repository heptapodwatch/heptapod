<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Api;

use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Group;
use Heptapod\Actions\AuthorizeSiteWrite;
use Heptapod\Actions\CreateMaintenanceWindow;
use Heptapod\Actions\DeleteMaintenancePeriod;
use Heptapod\Actions\UpdateMaintenancePeriod;
use Heptapod\Concerns\ResolvesApiPrincipal;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Http\Resources\OhDear\MaintenancePeriodResource;
use Heptapod\Maintenance\LocalMaintenanceTime;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Models\RecurringMaintenancePeriod;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Support\Api\Idempotency;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

#[Group('Maintenance')]
final class MaintenancePeriodController extends Controller
{
    use ResolvesApiPrincipal;

    public function index(Request $request, Site $monitor): JsonResponse
    {
        Gate::authorize('view', $monitor);
        /** @var array{filter?: array{started_at?: string, ended_at?: string}} $data */
        $data = Validator::make($request->all(), [
            'filter' => ['sometimes', 'array:started_at,ended_at'],
            'filter.started_at' => 'sometimes|date_format:YmdHis',
            'filter.ended_at' => 'sometimes|date_format:YmdHis',
        ])->validate();
        $query = MaintenancePeriod::targetingSite($monitor->id);
        $query->getQuery()->leftJoin('maintenance_site_exclusions as site_exit', static fn (JoinClause $join): JoinClause => $join
            ->on('site_exit.maintenance_period_id', '=', 'maintenance_periods.id')->where('site_exit.site_id', $monitor->id))
            ->select('maintenance_periods.*', 'site_exit.left_at as site_ended_at');
        $user = $this->principal($request);
        if ($user->isRestrictedMember()) {
            $query->getQuery()->where('target_type', '!=', 'tag');
        }
        if (isset($data['filter']['started_at'])) {
            $query->getQuery()->where('maintenance_periods.starts_at', '>=', CarbonImmutable::createFromFormat('!YmdHis', $data['filter']['started_at'], 'UTC'));
        }
        if (isset($data['filter']['ended_at'])) {
            $end = CarbonImmutable::createFromFormat('!YmdHis', $data['filter']['ended_at'], 'UTC');
            if (! $end instanceof CarbonImmutable) {
                throw new \LogicException('Validated maintenance filter must be a date.');
            }
            $query->getQuery()->whereRaw('least(maintenance_periods.ends_at, coalesce(site_exit.left_at, maintenance_periods.ends_at)) <= ?', [
                $end->format('Y-m-d H:i:s.uP'),
            ]);
        }
        $query->getQuery()->orderByDesc('maintenance_periods.starts_at')->orderByDesc('maintenance_periods.id');

        return response()->json(['data' => array_map(static function (MaintenancePeriod $period) use ($monitor): array {
            $siteEndAt = $period->getAttribute('site_ended_at');
            if ($siteEndAt !== null && ! is_string($siteEndAt)) {
                throw new \LogicException('Site-specific maintenance end must be a timestamp.');
            }

            return new MaintenancePeriodResource($period, $monitor->id,
                $siteEndAt === null ? null : CarbonImmutable::parse($siteEndAt))->resolve();
        }, $query->get()->all())]);
    }

    public function store(Request $request, CreateMaintenanceWindow $create, Idempotency $idempotency, AuthorizeSiteWrite $authorize): JsonResponse
    {
        /** @var array{monitor_id: int, starts_at: string, ends_at: string, name?: string|null} $data */
        $data = Validator::make($request->all(), [
            'monitor_id' => 'required|integer|exists:sites,id', 'starts_at' => 'required|date_format:Y-m-d H:i',
            'ends_at' => 'required|date_format:Y-m-d H:i', 'name' => 'nullable|string|max:255',
        ])->validate();
        $site = Site::query()->findOrFail($data['monitor_id']);
        // Also refuses a replay, which answers from its record; a new window authorizes again under the lock.
        Gate::authorize('update', $site);
        $user = $this->principal($request);
        $window = ['target_type' => 'site', 'target_id' => $site->id,
            'starts_local' => $data['starts_at'], 'ends_local' => $data['ends_at'], 'timezone' => 'UTC',
            'recurrence_type' => 'once', 'name' => $data['name'] ?? null];

        return $idempotency->run($request, 'maintenance_period.create', 'site:'.$site->id, $window, static function () use ($create, $user, $window, $authorize, $site): JsonResponse {
            $period = $authorize($user, 'update', static fn (): MaintenancePeriod|RecurringMaintenancePeriod => $create($user, $window), $site);
            if (! $period instanceof MaintenancePeriod) {
                throw new \LogicException('Scheduled one-time maintenance must create a window.');
            }

            return new MaintenancePeriodResource($period)->response()->setStatusCode(201);
        });
    }

    public function update(Request $request, MaintenancePeriod $maintenancePeriod, UpdateMaintenancePeriod $update,
        LocalMaintenanceTime $localTime, AuthorizeSiteWrite $authorize): MaintenancePeriodResource
    {
        [$user, $site] = $this->writable($request, $maintenancePeriod);
        /** @var array{starts_at: string, ends_at: string, name?: string|null} $data */
        $data = Validator::make($request->all(), [
            'starts_at' => 'required|date_format:Y-m-d H:i', 'ends_at' => 'required|date_format:Y-m-d H:i',
            'name' => 'nullable|string|max:255',
        ])->validate();
        $start = $localTime->resolve(substr($data['starts_at'], 0, 10), substr($data['starts_at'], 11), 'UTC');
        $end = $localTime->resolve(substr($data['ends_at'], 0, 10), substr($data['ends_at'], 11), 'UTC');

        return new MaintenancePeriodResource($authorize($user, 'update', static fn (): MaintenancePeriod => $update($maintenancePeriod, $data['name'] ?? $maintenancePeriod->name, $start, $end,
            authorize: static function (MaintenancePeriod $locked) use ($site): void {
                self::sameSiteWindow($locked, $site);
                if ($locked->ends_at <= CarbonImmutable::now('UTC')) {
                    throw ValidationException::withMessages(['starts_at' => __('maintenance.completed_window_web_only')]);
                }
            }), $site));
    }

    public function destroy(Request $request, MaintenancePeriod $maintenancePeriod, DeleteMaintenancePeriod $delete, AuthorizeSiteWrite $authorize): Response
    {
        [$user, $site] = $this->writable($request, $maintenancePeriod);
        $authorize($user, 'update', static fn () => $delete($maintenancePeriod, static function (MaintenancePeriod $locked) use ($site): void {
            self::sameSiteWindow($locked, $site);
            if ($locked->starts_at <= CarbonImmutable::now('UTC')) {
                throw ValidationException::withMessages(['starts_at' => __('maintenance.completed_window_web_only')]);
            }
        }), $site);

        return response()->noContent();
    }

    /** @return array{User, Site} */
    private function writable(Request $request, MaintenancePeriod $period): array
    {
        $user = $this->principal($request, 403);
        abort_unless($user->hasAnyRole(['Owner', 'Admin']), 403);
        if ($period->target_type !== 'site') {
            throw ValidationException::withMessages(['maintenance_period_id' => __('maintenance.shared_window_web_only')]);
        }
        $site = Site::query()->findOrFail($period->site_id);
        Gate::authorize('update', $site);

        return [$user, $site];
    }

    /** The locked window must still be the one-site window of the site authorized under the lock. */
    private static function sameSiteWindow(MaintenancePeriod $locked, Site $site): void
    {
        if ($locked->target_type !== 'site') {
            throw ValidationException::withMessages(['maintenance_period_id' => __('maintenance.shared_window_web_only')]);
        }
        abort_unless($locked->site_id === $site->id, 404);
    }
}
