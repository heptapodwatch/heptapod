<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Actions\RequestSiteChecks;
use Heptapod\Models\User;
use Heptapod\Policies\SitePolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

final class RequestSiteChecksController extends Controller
{
    public function __invoke(Request $request, int $site, SitePolicy $policy, RequestSiteChecks $run): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $target = $policy->visibleSites($user)->whereKey($site)->firstOrFail();
        Gate::authorize('update', $target);
        /** @var array{confirmed: bool, fast_only?: bool} $data */
        $data = Validator::make($request->all(), ['confirmed' => ['required', 'accepted'], 'fast_only' => ['sometimes', 'boolean']])->validate();
        $result = $run($target, $user, $data['fast_only'] ?? false);

        return redirect()->route('sites.show', $site)->with('run_all', $result);
    }
}
