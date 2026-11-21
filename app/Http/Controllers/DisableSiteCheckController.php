<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Actions\AuthorizeSiteWrite;
use Heptapod\Actions\DisableCheck;
use Heptapod\Models\Check;
use Heptapod\Models\User;
use Heptapod\Policies\SitePolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class DisableSiteCheckController extends Controller
{
    public function __invoke(Request $request, int $site, int $check, SitePolicy $policy, DisableCheck $disable, AuthorizeSiteWrite $authorize): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $policy->visibleSites($user)->whereKey($site)->firstOrFail();
        $target = Check::query()->where('site_id', $site)->findOrFail($check);
        $authorize($user, 'update', static fn () => $disable($target), $target);

        return redirect()->route('sites.checks.show', ['site' => $site, 'check' => $check]);
    }
}
