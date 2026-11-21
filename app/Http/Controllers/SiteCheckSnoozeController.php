<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Carbon\CarbonImmutable;
use Heptapod\Actions\AuthorizeSiteWrite;
use Heptapod\Actions\SnoozeCheck;
use Heptapod\Actions\UnsnoozeCheck;
use Heptapod\Models\Check;
use Heptapod\Models\User;
use Heptapod\Policies\SitePolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/** Snooze from the check page: the check keeps running, its alerts wait until the end (SnoozeCheck). */
final class SiteCheckSnoozeController extends Controller
{
    public function store(Request $request, int $site, int $check, SitePolicy $policy, SnoozeCheck $snooze, AuthorizeSiteWrite $authorize): RedirectResponse
    {
        [$user, $target] = $this->target($request, $site, $check, $policy);
        $authorize($user, 'snooze', static function () use ($request, $target, $user, $snooze): void {
            $absolute = $request->has('snooze_until');
            Validator::make($request->all(), $absolute
                ? ['snooze_until' => ['required', 'string', 'date', 'regex:/(Z|[+-]\d{2}:\d{2})$/', 'after:now', 'before_or_equal:'.now()->addMinutes(525600)->toIso8601String()]]
                : ['minutes' => ['required', 'integer', 'between:1,525600']], ['snooze_until' => __('check_snooze.until_invalid')])->validate();
            $snooze($target, $user, $absolute
                ? CarbonImmutable::parse($request->string('snooze_until')->toString())->utc()
                : CarbonImmutable::now('UTC')->addMinutes($request->integer('minutes')));
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
