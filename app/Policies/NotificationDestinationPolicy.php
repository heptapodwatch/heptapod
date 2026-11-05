<?php

declare(strict_types=1);

namespace Heptapod\Policies;

use Heptapod\Enums\RecipientKind;
use Heptapod\Models\Client;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Who may manage a recipient's notification settings (design §7.1): the team's channels → an active Owner or Admin;
 * a user's own channels → that user; a client's channels → ClientPolicy::update. Channels the actor cannot see are 404.
 */
final readonly class NotificationDestinationPolicy
{
    public function __construct(private ClientPolicy $clients) {}

    public function update(User $user, NotificationDestination $destination): Response
    {
        if ($destination->removed_at !== null) {
            return Response::denyAsNotFound();
        }

        return match ($destination->recipient_kind) {
            RecipientKind::Team => $this->manageTeam($user),
            RecipientKind::User => $this->manageUser($user, $destination->user_id ?? 0),
            RecipientKind::Client => $this->manageClient($user, $destination->client_id ?? 0),
        };
    }

    /** Whoever manages a channel may test it; an Owner or Admin may also test a colleague's account channel. */
    public function sendTest(User $user, NotificationDestination $destination): Response
    {
        $manage = $this->update($user, $destination);
        if ($manage->allowed() || ! $destination->account_mail || $destination->removed_at !== null) {
            return $manage;
        }

        return $this->manageTeam($user);
    }

    public function manageTeam(User $user): Response
    {
        return $user->disabled_at === null && $user->hasAnyRole(['Owner', 'Admin']) ? Response::allow() : Response::deny();
    }

    public function manageUser(User $user, int $userId): Response
    {
        return $user->disabled_at === null && $user->id === $userId ? Response::allow() : Response::deny();
    }

    public function manageClient(User $user, int $clientId): Response
    {
        $client = Client::query()->find($clientId);

        return $client instanceof Client && $user->disabled_at === null ? $this->clients->update($user, $client) : Response::denyAsNotFound();
    }
}
