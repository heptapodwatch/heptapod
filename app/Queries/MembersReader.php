<?php

declare(strict_types=1);

namespace Heptapod\Queries;

use Heptapod\Actions\ResolveClientAvatar;
use Heptapod\Auth\MemberAccessGuard;
use Heptapod\Data\Ui\MembersPageData;
use Heptapod\Models\Client;
use Heptapod\Models\Invitation;
use Heptapod\Models\User;

final readonly class MembersReader
{
    public function __construct(private MemberAccessGuard $guard) {}

    public function read(User $actor): MembersPageData
    {
        $userQuery = User::query()->with(['roles', 'clients']);
        $userQuery->getQuery()->orderBy('name')->orderBy('id');
        $users = $userQuery->get();
        $ownerCount = $users->filter(static fn (User $user): bool => $user->disabled_at === null && $user->hasRole('Owner'))->count();
        $members = array_values($users->map(function (User $user) use ($actor, $ownerCount): array {
            $canManage = $this->guard->canManage($actor, $user);
            $lastOwner = $user->disabled_at === null && $user->hasRole('Owner') && $ownerCount === 1;
            $clientIds = $user->clients->map(static fn (Client $client): int => $client->id)->all();
            /** @var string|null $role */
            $role = $user->getRoleNames()->first();

            return [
                'id' => $user->id, 'name' => $user->name, 'email' => $user->email,
                'role' => $role, 'client_ids' => array_values($clientIds),
                'client_scope' => $user->client_scope->value,
                'effective_client_scope' => $user->isRestrictedMember() ? 'restricted' : 'all',
                'last_active_at' => $user->last_active_at?->toIso8601String(), 'disabled' => $user->disabled_at !== null,
                'is_last_owner' => $lastOwner, 'can_edit' => $canManage && $user->disabled_at === null,
                'can_deactivate' => $canManage && $user->disabled_at === null && ! $lastOwner,
                'can_reinvite' => $canManage && $user->disabled_at !== null,
                'allowed_roles' => ! $canManage || $user->disabled_at !== null ? [] : ($lastOwner ? ['Owner'] : ($actor->hasRole('Owner') ? ['Owner', 'Admin', 'Member'] : ['Member'])),
            ];
        })->all());
        $invitationQuery = Invitation::query()->with('inviter');
        $invitationQuery->getQuery()->orderBy('email')->orderBy('id');
        $invitations = array_values($invitationQuery->get()->map(static function (Invitation $invitation) use ($actor): array {
            /** @var list<int> $clientIds */
            $clientIds = $invitation->getAttribute('client_ids');

            return [
                'id' => $invitation->id, 'email' => $invitation->email, 'role' => $invitation->role,
                'client_ids' => $clientIds, 'client_scope' => $invitation->client_scope,
                'inviter' => $invitation->inviter?->name, 'expires_at' => $invitation->expires_at->toIso8601String(),
                'expired' => $invitation->expires_at->isPast(),
                'can_manage' => $actor->hasRole('Owner') || ($actor->hasRole('Admin') && $invitation->role === 'Member'),
            ];
        })->all());
        $clientQuery = Client::query()->with(Client::AvatarRelations);
        $clientQuery->getQuery()->orderBy('name');
        $clients = array_values($clientQuery->get(['id', 'name', 'archived_at', 'short_name', 'accent_color', 'avatar_site_id', 'is_internal'])->map(static fn (Client $client): array => [
            'id' => $client->id, 'name' => $client->displayName(), 'archived' => $client->archived_at !== null,
            'short_name' => $client->short_name, 'accent_color' => $client->accent_color, 'avatar' => app(ResolveClientAvatar::class)($client), 'is_internal' => $client->is_internal,
        ])->all());

        return new MembersPageData($members, $invitations, $clients, $actor->hasRole('Owner') ? ['Admin', 'Member'] : ['Member']);
    }
}
