<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers;

use Heptapod\Contracts\ServersAccess;
use Illuminate\Contracts\Auth\Authenticatable;

/** Servers are team-only: every server is visible to exactly the users ServersAccess allows (Owners and Admins). */
final readonly class ServerPolicy
{
    public function __construct(private ServersAccess $access) {}

    public function viewAny(Authenticatable $user): bool
    {
        return $this->access->allows($user);
    }

    public function view(Authenticatable $user, Server $server): bool
    {
        return $this->access->allows($user);
    }

    public function create(Authenticatable $user): bool
    {
        return $this->access->allows($user);
    }

    public function update(Authenticatable $user, Server $server): bool
    {
        return $this->access->allows($user);
    }

    public function delete(Authenticatable $user, Server $server): bool
    {
        return $this->access->allows($user);
    }
}
