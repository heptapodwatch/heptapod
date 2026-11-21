<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Models\User;
use Illuminate\Support\Facades\DB;

final class RevokeApiToken
{
    public function __invoke(User $user, int $tokenId): void
    {
        DB::transaction(static function () use ($user, $tokenId): void {
            // NotificationLocks level 0: revoking a token changes who may write what (AuthorizeSiteWrite re-reads the token).
            NotificationLocks::accessExclusive();
            $user->tokens()->getQuery()->findOrFail($tokenId)->delete();
        });
    }
}
