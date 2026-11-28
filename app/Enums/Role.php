<?php

declare(strict_types=1);

namespace Heptapod\Enums;

use Heptapod\Models\User;

/**
 * The one place that reads an account's role. Access rules ask Capability; Role is for code about a role itself:
 * member management, role labels, and who may hear system mail.
 */
enum Role: string
{
    case Owner = 'Owner';
    case Admin = 'Admin';
    case Member = 'Member';

    /**
     * Invitations, role changes and CreateOwner each set exactly one role; an account given several directly counts as
     * its highest. The cases are ordered from highest to lowest.
     */
    public static function of(User $user): ?self
    {
        $names = $user->getRoleNames();

        return array_find(self::cases(), static fn (self $role): bool => $names->contains($role->value));
    }
}
