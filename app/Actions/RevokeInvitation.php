<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Models\Invitation;
use Heptapod\Models\User;

final class RevokeInvitation
{
    public function __invoke(User $actor, Invitation $invitation): void
    {
        abort_unless($actor->hasRole('Owner') || ($actor->hasRole('Admin') && $invitation->role === 'Member'), 403);
        $invitation->delete();
    }
}
