<?php

declare(strict_types=1);

namespace Heptapod\Enums;

use Heptapod\Models\User;

/**
 * What an account may do, defined once from its role and reach. Routes declare these as Gates (`can:` middleware);
 * policies, broadcast channels and shared props ask allows(). A disabled account may do nothing.
 */
enum Capability: string
{
    /** Instance settings, mail, backups and updates: Owners. */
    case ManageInstance = 'manage-instance';

    /** The team's clients, sites, checks, maintenance, members and channels: Owners and Admins. */
    case Manage = 'manage';

    /** Monitoring inside the account's reach: every role. */
    case ViewInstance = 'view-instance';

    /** One client: every role that reaches it. */
    case ViewClient = 'view-client';

    public function allows(User $user, ?int $clientId = null): bool
    {
        if ($user->disabled_at !== null) {
            return false;
        }
        $role = Role::of($user);

        return match ($this) {
            self::ManageInstance => $role === Role::Owner,
            self::Manage => $role === Role::Owner || $role === Role::Admin,
            self::ViewInstance => $role instanceof Role,
            self::ViewClient => $role instanceof Role && self::reaches($user, $clientId ?? throw new \LogicException('view-client needs a client.')),
        };
    }

    /**
     * The PHP form of the reach rule, on the account as loaded. The SQL forms are SitePolicy::visibleSites and
     * visibleClients for lists, and viewableUsers for batches and for site authorization, which reads the stored account.
     * A client-limited API token reaches only its client, a restricted Member only the assigned clients.
     */
    private static function reaches(User $user, int $clientId): bool
    {
        $constraint = $user->tokenClientConstraint();

        return ($constraint === null || $constraint === $clientId)
            && (! $user->isRestrictedMember() || $user->clients()->toBase()->where('clients.id', $clientId)->exists());
    }
}
