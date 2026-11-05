<?php

declare(strict_types=1);

namespace Heptapod\Concerns;

use Heptapod\Models\User;
use Illuminate\Http\Request;

trait AuthorizesSettingsOperator
{
    private function operator(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($user->hasAnyRole(['Owner', 'Admin']) && $user->disabled_at === null, 403);

        return $user;
    }
}
