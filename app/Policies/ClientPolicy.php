<?php

declare(strict_types=1);

namespace Heptapod\Policies;

use Heptapod\Models\Client;
use Heptapod\Models\User;
use Illuminate\Auth\Access\Response;

final class ClientPolicy
{
    public function create(User $user): Response
    {
        return $user->hasAnyRole(['Owner', 'Admin']) ? Response::allow() : Response::deny();
    }

    public function view(User $user, Client $client): Response
    {
        if (! $user->hasAnyRole(['Owner', 'Admin', 'Member'])) {
            return Response::deny();
        }

        return $user->canAccessClient($client->id) ? Response::allow() : Response::denyAsNotFound();
    }

    public function update(User $user, Client $client): Response
    {
        return $user->canAccessClient($client->id) && $user->hasAnyRole(['Owner', 'Admin']) ? Response::allow() : Response::denyAsNotFound();
    }

    public function archive(User $user, Client $client): Response
    {
        $update = $this->update($user, $client);
        if (! $update->allowed()) {
            return $update;
        }

        return $client->is_internal ? Response::deny() : Response::allow();
    }
}
