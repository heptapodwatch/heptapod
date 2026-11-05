<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Actions\RotateCronPingUrl;
use Heptapod\Contracts\CheckKey;
use Heptapod\Models\Check;
use Heptapod\Models\User;
use Heptapod\Policies\SitePolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

final class RotateCronPingUrlController extends Controller
{
    public function __invoke(Request $request, int $site, int $check, int $task, SitePolicy $sites, RotateCronPingUrl $rotate): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $sites->visibleSites($actor)->whereKey($site)->firstOrFail();
        $target = Check::query()->where('site_id', $site)->findOrFail($check);
        abort_unless($target->type === CheckKey::Cron, 404);
        Gate::authorize('update', $target);
        $rotate($actor, $target, $task);

        Inertia::flash('toast', ['type' => 'warning', 'message' => __('Cron ping URL rotated. Update your schedules.')]);

        return redirect()->route('sites.checks.show', ['site' => $site, 'check' => $check]);
    }
}
