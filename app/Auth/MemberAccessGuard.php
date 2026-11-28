<?php

declare(strict_types=1);

namespace Heptapod\Auth;

use Heptapod\Enums\Role;
use Heptapod\Exceptions\ExpectedRefusal;
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
        return $actor->disabled_at === null && (Role::of($actor) === Role::Owner || (Role::of($actor) === Role::Admin && Role::of($member) === Role::Member));
    }

    public function lockOwners(): void
    {
        DB::table('roles')->where('name', 'Owner')->where('guard_name', 'web')->lockForUpdate()->first()
            ?? throw new \LogicException('Owner role must exist.');
    }

    public function protectLastOwner(): void
    {
        if ($this->activeOwnerCount() <= 1) {
            throw new ExpectedRefusal(
                status: 422,
                messageKey: 'members.last_owner',
                errorCode: 'last_owner',
            );
        }
    }

    public function activeOwnerCount(): int
    {
        return DB::table('model_has_roles')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->join('users', 'users.id', '=', 'model_has_roles.model_id')
            ->where('roles.name', 'Owner')->where('model_has_roles.model_type', (new User)->getMorphClass())
            ->whereNull('users.disabled_at')->count();
    }
}
