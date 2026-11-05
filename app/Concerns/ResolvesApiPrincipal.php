<?php

declare(strict_types=1);

namespace Heptapod\Concerns;

use Heptapod\Models\User;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

trait ResolvesApiPrincipal
{
    private function principal(Request $request, int $status = 401): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, $status);

        return $user;
    }

    private function token(User $user): PersonalAccessToken
    {
        $token = $user->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken, 401); // @phpstan-ignore function.alreadyNarrowedType, instanceof.alwaysTrue

        return $token;
    }
}
