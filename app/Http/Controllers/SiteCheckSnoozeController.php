<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Actions\AuthorizeSiteWrite;
use Heptapod\Actions\SnoozeCheck;
use Heptapod\Actions\UnsnoozeCheck;
use Heptapod\Contracts\SnoozeEnd;
use Heptapod\Models\Check;
use Heptapod\Models\User;
use Heptapod\Policies\SitePolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Snooze from the check page: the check keeps running, its alerts wait until the end (SnoozeCheck). */
final class SiteCheckSnoozeController extends Controller
{
    public function store(Request $request, int $site, int $check, SitePolicy $policy, SnoozeCheck $snooze, AuthorizeSiteWrite $authorize): RedirectResponse
    {
        [$user, $target] = $this->target($request, $site, $check, $policy);
        $authorize($user, 'snooze', static function () use ($request, $target, $user, $snooze): void {
            $snooze($target, $user, SnoozeEnd::from($request->all()));
        }, $target);

        return back();
    }

    public function destroy(Request $request, int $site, int $check, SitePolicy $policy, UnsnoozeCheck $unsnooze, AuthorizeSiteWrite $authorize): RedirectResponse
    {
        [$user, $target] = $this->target($request, $site, $check, $policy);
        $authorize($user, 'snooze', static fn () => $unsnooze($target, $user), $target);

        return back();
    }

    /** @return array{User, Check} the check, authorized by its write (AuthorizeSiteWrite) */
    private function target(Request $request, int $site, int $check, SitePolicy $policy): array
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $policy->visibleSites($user)->whereKey($site)->firstOrFail();

        return [$user, Check::query()->where('site_id', $site)->findOrFail($check)];
    }
}
