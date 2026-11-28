<?php

declare(strict_types=1);

namespace Heptapod\Settings;

use Heptapod\Contracts\ServersAccess;
use Heptapod\Contracts\ServersSwitch;
use Heptapod\Models\User;
use Heptapod\Queries\UpdateStatusReader;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

final readonly class InstanceServersAccess implements ServersAccess
{
    /** @param  ?ServersSwitch  $switch  null without the servers addon */
    public function __construct(private ?ServersSwitch $switch = null) {}

    public function enabled(): bool
    {
        return config()->boolean('heptapod.servers.enabled_by_server') && $this->switch?->isOn() === true;
    }

    /** Servers belong to no client, so a client-limited token sees none, like a site outside its client (SitePolicy). */
    public function allows(Authenticatable $user): bool
    {
        return $user instanceof User && $user->disabled_at === null && $user->hasAnyRole(['Owner', 'Admin'])
            && $user->tokenClientConstraint() === null;
    }

    /** A source or Git install runs a commit build or `unknown`, which has no agent release of its own. */
    public function agentReleaseTag(): ?string
    {
        $version = config()->string('heptapod.version');
        if (UpdateStatusReader::isReleaseTag($version)) {
            return $version;
        }
        $latest = DB::table('update_checks')->where('id', 1)->value('latest_version');

        return is_string($latest) && UpdateStatusReader::isReleaseTag($latest) ? $latest : null;
    }
}
