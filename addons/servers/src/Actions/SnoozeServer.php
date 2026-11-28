<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers\Actions;

use Carbon\CarbonImmutable;
use Heptapod\Addons\Servers\Server;
use Illuminate\Support\Facades\DB;

final readonly class SnoozeServer
{
    public function __construct(private EvaluateServerIssues $issues) {}

    /**
     * Snoozes the server's alerts until $until (Contracts\SnoozeEnd parses it from the page or the API), or ends the
     * snooze (null). The server keeps reporting and its issues keep opening and resolving; an opening waits for the
     * snooze's end and is sent then if its issue is still open. An absolute end makes retries converge on the same snooze.
     */
    public function __invoke(Server $server, ?CarbonImmutable $until): void
    {
        DB::transaction(function () use ($server, $until): void {
            $this->issues->snooze($this->issues->lock($server->id) ?? abort(404), $until);
        });
    }
}
