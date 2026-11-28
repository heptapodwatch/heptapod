<?php

declare(strict_types=1);

namespace Heptapod\Concerns;

use Heptapod\Enums\Capability;
use Heptapod\Models\User;
use Illuminate\Http\Request;

trait AuthorizesSettingsOperator
{
    private function operator(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless(Capability::Manage->allows($user), 403);

        return $user;
    }
}
