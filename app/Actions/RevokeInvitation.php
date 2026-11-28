<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Enums\Role;
use Heptapod\Models\Invitation;
use Heptapod\Models\User;

final class RevokeInvitation
{
    public function __invoke(User $actor, Invitation $invitation): void
    {
        abort_unless(Role::of($actor) === Role::Owner || (Role::of($actor) === Role::Admin && $invitation->role === 'Member'), 403);
        $invitation->delete();
    }
}
