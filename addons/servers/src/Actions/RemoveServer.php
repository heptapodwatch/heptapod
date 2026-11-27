<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers\Actions;

use Carbon\CarbonImmutable;
use Heptapod\Addons\Servers\Server;
use Illuminate\Support\Facades\DB;

final readonly class RemoveServer
{
    public function __construct(private EvaluateServerIssues $issues) {}

    /**
     * Deletes the server; its samples go with it (cascade), and with the row its token stops matching any server. Its open
     * issues end without a recovery alert; the issues and their events stay as alert history with the server's name.
     */
    public function __invoke(Server $server): void
    {
        DB::transaction(function () use ($server): void {
            $locked = $this->issues->lock($server->id);
            if ($locked === null) {
                return;
            }
            $this->issues->end($locked, 'server_removed', CarbonImmutable::now('UTC'));
            $locked->delete();
        });
    }
}
