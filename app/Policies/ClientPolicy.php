<?php

declare(strict_types=1);

namespace Heptapod\Policies;

use Heptapod\Enums\Capability;
use Heptapod\Models\Client;
use Heptapod\Models\User;
use Illuminate\Auth\Access\Response;

final class ClientPolicy
{
    public function create(User $user): Response
    {
        return Capability::Manage->allows($user) ? Response::allow() : Response::deny();
    }

    public function view(User $user, Client $client): Response
    {
        if (! Capability::ViewInstance->allows($user)) {
            return Response::deny();
        }

        return Capability::ViewClient->allows($user, $client->id) ? Response::allow() : Response::denyAsNotFound();
    }

    public function update(User $user, Client $client): Response
    {
        return Capability::ViewClient->allows($user, $client->id) && Capability::Manage->allows($user) ? Response::allow() : Response::denyAsNotFound();
    }

    public function restore(User $user, Client $client): Response
    {
        return $this->archive($user, $client);
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
