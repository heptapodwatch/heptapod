<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Models\Invitation;

final class RenewInvitationLink
{
    public function __invoke(Invitation $invitation): string
    {
        $token = bin2hex(random_bytes(32));
        $invitation->update(['token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(7)]);

        return route('invitations.show', ['token' => $token]);
    }
}
