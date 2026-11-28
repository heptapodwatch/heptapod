<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Api;

use Dedoc\Scramble\Attributes\Group;
use Heptapod\Actions\AuthorizeSiteWrite;
use Heptapod\Actions\UnsnoozeCheck;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Http\Resources\OhDear\CheckResource;
use Heptapod\Models\Check;
use Heptapod\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Support\Facades\Gate;

#[Group('Checks')]
final class UnsnoozeCheckController extends Controller
{
    public function __invoke(#[CurrentUser] User $user, Check $check, UnsnoozeCheck $unsnooze, AuthorizeSiteWrite $authorize): CheckResource
    {
        // Early and unlocked, so a hidden check answers 404 like a missing one even while the access lock is busy.
        Gate::authorize('snooze', $check);
        // The resource reads the site loaded under the lock: a site deleted after the commit must not fail the response.
        $authorize($user, 'snooze', static function () use ($unsnooze, $check, $user): void {
            $unsnooze($check, $user);
            $check->load(['snoozes', 'site.checks']);
        }, $check);

        return new CheckResource($check);
    }
}
