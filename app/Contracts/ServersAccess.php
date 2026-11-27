<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/** What the experimental servers addon needs from core: whether servers exist on this instance, and who may see them. */
interface ServersAccess
{
    /** The environment allows servers and an Owner switched them on; off, no server route, page or job exists. */
    public function enabled(): bool;

    /** Owners and Admins only, and never through an API token limited to one client, whatever the role. */
    public function allows(Authenticatable $user): bool;

    /** The release whose agent the install command fetches: this version when it is a release, else the newest release the update check knows; null when neither exists. */
    public function agentReleaseTag(): ?string;
}
