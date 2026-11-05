<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Models\User;

final class RevokeApiToken
{
    public function __invoke(User $user, int $tokenId): void
    {
        $user->tokens()->getQuery()->findOrFail($tokenId)->delete();
    }
}
