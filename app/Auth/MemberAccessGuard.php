<?php

declare(strict_types=1);

namespace Heptapod\Auth;

use Heptapod\Models\User;
use Illuminate\Support\Facades\DB;

final class MemberAccessGuard
{
    public function authorize(User $actor, User $member): void
    {
        abort_unless($this->canManage($actor, $member), 403);
    }

    public function canManage(User $actor, User $member): bool
    {
        return $actor->disabled_at === null && ($actor->hasRole('Owner') || ($actor->hasRole('Admin') && $member->hasRole('Member')));
    }

    public function lockOwners(): void
    {
        DB::table('roles')->where('name', 'Owner')->where('guard_name', 'web')->lockForUpdate()->first()
            ?? throw new \LogicException('Owner role must exist.');
    }

    public function protectLastOwner(): void
    {
        abort_if($this->activeOwnerCount() <= 1, 422, __('members.last_owner'));
    }

    public function activeOwnerCount(): int
    {
        return DB::table('model_has_roles')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->join('users', 'users.id', '=', 'model_has_roles.model_id')
            ->where('roles.name', 'Owner')->where('model_has_roles.model_type', (new User)->getMorphClass())
            ->whereNull('users.disabled_at')->count();
    }
}
