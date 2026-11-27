<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers\Actions;

use Heptapod\Addons\Servers\Server;

final class RemoveServer
{
    /** Deletes the server; its samples go with it (cascade), and with the row its token stops matching any server. */
    public function __invoke(Server $server): void
    {
        $server->delete();
    }
}
