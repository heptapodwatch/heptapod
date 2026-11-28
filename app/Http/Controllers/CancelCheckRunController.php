<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Actions\CancelCheckRun;
use Heptapod\Models\Check;
use Heptapod\Models\User;
use Heptapod\Policies\SitePolicy;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

final class CancelCheckRunController extends Controller
{
    public function __invoke(#[CurrentUser] User $user, int $site, int $check, SitePolicy $policy, CancelCheckRun $cancel): RedirectResponse
    {
        $policy->visibleSites($user)->whereKey($site)->firstOrFail();
        $target = Check::query()->where('site_id', $site)->findOrFail($check);
        Gate::authorize('update', $target);
        $cancel($target);

        return redirect()->route('sites.checks.show', ['site' => $site, 'check' => $check]);
    }
}
