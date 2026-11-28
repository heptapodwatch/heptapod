<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Enums\Role;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Models\Invitation;
use Heptapod\Models\User;
use Heptapod\Notifications\TeamInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

final readonly class ResendInvitation
{
    public function __construct(private RenewInvitationLink $renewLink) {}

    public function __invoke(User $actor, Invitation $invitation): void
    {
        abort_unless(Role::of($actor) === Role::Owner || (Role::of($actor) === Role::Admin && $invitation->role === 'Member'), 403);
        DB::transaction(function () use ($actor, $invitation): void {
            $locked = Invitation::query()->whereKey($invitation->id);
            NotificationLocks::forUpdate($locked->getQuery());
            $current = $locked->firstOrFail();
            abort_unless(Role::of($actor) === Role::Owner || (Role::of($actor) === Role::Admin && $current->role === 'Member'), 403);
            $url = ($this->renewLink)($current);
            Notification::route('mail', $current->email)->notify(new TeamInvitation($current, $url));
        });
    }
}
