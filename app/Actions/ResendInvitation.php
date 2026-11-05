<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Models\Invitation;
use Heptapod\Models\User;
use Heptapod\Notifications\TeamInvitation;
use Illuminate\Support\Facades\Notification;

final readonly class ResendInvitation
{
    public function __construct(private RenewInvitationLink $renewLink) {}

    public function __invoke(User $actor, Invitation $invitation): void
    {
        abort_unless($actor->hasRole('Owner') || ($actor->hasRole('Admin') && $invitation->role === 'Member'), 403);
        Notification::route('mail', $invitation->email)->notify(new TeamInvitation($invitation, ($this->renewLink)($invitation)));
    }
}
