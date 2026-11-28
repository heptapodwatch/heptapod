<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Actions\AuthorizeSiteWrite;
use Heptapod\Actions\DisableCheck;
use Heptapod\Models\Check;
use Heptapod\Models\User;
use Heptapod\Policies\SitePolicy;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class DisableSiteCheckController extends Controller
{
    public function __invoke(#[CurrentUser] User $user, int $site, int $check, SitePolicy $policy, DisableCheck $disable, AuthorizeSiteWrite $authorize): RedirectResponse
    {
        $policy->visibleSites($user)->whereKey($site)->firstOrFail();
        $target = Check::query()->where('site_id', $site)->findOrFail($check);
        $authorize($user, 'update', static fn () => $disable($target), $target);

        return redirect()->route('sites.checks.show', ['site' => $site, 'check' => $check]);
    }
}
