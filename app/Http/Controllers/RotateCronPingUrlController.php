<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Actions\RotateCronPingUrl;
use Heptapod\Contracts\CheckKey;
use Heptapod\Models\Check;
use Heptapod\Models\User;
use Heptapod\Policies\SitePolicy;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

final class RotateCronPingUrlController extends Controller
{
    public function __invoke(#[CurrentUser] User $actor, int $site, int $check, int $task, SitePolicy $sites, RotateCronPingUrl $rotate): RedirectResponse
    {
        $sites->visibleSites($actor)->whereKey($site)->firstOrFail();
        $target = Check::query()->where('site_id', $site)->findOrFail($check);
        abort_unless($target->type === CheckKey::Cron, 404);
        Gate::authorize('update', $target);
        $rotate($actor, $target, $task);

        Inertia::flash('toast', ['type' => 'warning', 'message' => __('Cron ping URL rotated. Update your schedules.')]);

        return redirect()->route('sites.checks.show', ['site' => $site, 'check' => $check]);
    }
}
