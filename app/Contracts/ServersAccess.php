<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/** The experimental servers addon's two core facts: whether servers exist on this instance, and who may see them. */
interface ServersAccess
{
    /** The environment allows servers and an Owner switched them on; off, no server route, page or job exists. */
    public function enabled(): bool;

    /** Owners and Admins only, and never through an API token limited to one client, whatever the role. */
    public function allows(Authenticatable $user): bool;
}
