<?php

declare(strict_types=1);

namespace Heptapod\Settings;

use Heptapod\Contracts\ServersAccess;
use Heptapod\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

final readonly class InstanceServersAccess implements ServersAccess
{
    public function __construct(private InstanceSettings $settings) {}

    public function enabled(): bool
    {
        return config()->boolean('heptapod.servers.enabled_by_server') && $this->settings->servers_enabled;
    }

    /** Servers belong to no client, so a client-limited token sees none, like a site outside its client (SitePolicy). */
    public function allows(Authenticatable $user): bool
    {
        return $user instanceof User && $user->disabled_at === null && $user->hasAnyRole(['Owner', 'Admin'])
            && $user->tokenClientConstraint() === null;
    }
}
