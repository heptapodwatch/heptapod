<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Checks\HealthSettingsProbe;
use Heptapod\Contracts\CheckKey;
use Heptapod\Models\Check;
use Heptapod\Models\User;
use Heptapod\Policies\SitePolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class HealthSettingsProbeController extends Controller
{
    public function __invoke(Request $request, int $site, int $check, SitePolicy $policy, HealthSettingsProbe $probe): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $policy->visibleSites($user)->whereKey($site)->firstOrFail();
        $target = Check::query()->where('site_id', $site)->findOrFail($check);
        abort_unless($target->type === CheckKey::ApplicationHealth, 404);
        Gate::authorize('update', $target);

        /** @var array<string, mixed> $settings */
        $settings = $request->except('_token');

        return response()->json($probe($target, $user, $settings));
    }
}
