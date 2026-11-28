<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Api;

use Dedoc\Scramble\Attributes\Group;
use Heptapod\Actions\AuthorizeSiteWrite;
use Heptapod\Actions\DisableCheck;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Models\Check;
use Heptapod\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

#[Group('Checks')]
final class DisableCheckController extends Controller
{
    public function __invoke(#[CurrentUser] User $user, Check $check, DisableCheck $disable, AuthorizeSiteWrite $authorize): JsonResponse
    {
        // Early and unlocked, so a hidden check answers 404 like a missing one even while the access lock is busy.
        Gate::authorize('update', $check);
        $authorize($user, 'update', static fn () => $disable($check), $check);

        return response()->json(['id' => $check->id, 'type' => $check->type->value, 'enabled' => $check->enabled]);
    }
}
