<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Auth\MemberAccessGuard;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class DeactivateMember
{
    public function __construct(private MemberAccessGuard $guard, private RevokeMemberAccess $revoke) {}

    public function __invoke(User $actor, User $member): void
    {
        DB::transaction(function () use ($actor, $member): void {
            $this->guard->lockOwners();
            $currentActor = User::query()->findOrFail($actor->id);
            $query = User::query()->whereKey($member->id);
            NotificationLocks::forUpdate($query->getQuery());
            $current = $query->firstOrFail();
            abort_if($current->disabled_at !== null, 404);
            abort_if($currentActor->disabled_at !== null, 403);
            $this->guard->authorize($currentActor, $current);
            if ($current->hasRole('Owner')) {
                $this->guard->protectLastOwner();
            }
            $current->forceFill(['disabled_at' => now()])->save();
            $current->clients()->detach();
            ($this->revoke)($current);
        });
    }
}
