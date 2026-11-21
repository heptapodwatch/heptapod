<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Models\User;

/**
 * A signed-in user's own password change (Settings → Security). Other sessions end through their stored password hash;
 * unlike a forgotten-password reset (ResetUserPassword), it keeps the account's API tokens and live connections.
 */
final class ChangePassword
{
    public function __invoke(User $user, string $password): void
    {
        $user->forceFill(['password' => $password])->save();
    }
}
