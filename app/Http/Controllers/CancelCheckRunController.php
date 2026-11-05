<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Actions\CancelCheckRun;
use Heptapod\Models\Check;
use Heptapod\Models\User;
use Heptapod\Policies\SitePolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class CancelCheckRunController extends Controller
{
    public function __invoke(Request $request, int $site, int $check, SitePolicy $policy, CancelCheckRun $cancel): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $policy->visibleSites($user)->whereKey($site)->firstOrFail();
        $target = Check::query()->where('site_id', $site)->findOrFail($check);
        Gate::authorize('update', $target);
        $cancel($target);

        return redirect()->route('sites.checks.show', ['site' => $site, 'check' => $check]);
    }
}
