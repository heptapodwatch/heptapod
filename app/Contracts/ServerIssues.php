<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

/** What core needs from the servers addon to deliver and show server issues. */
interface ServerIssues
{
    /**
     * The alert for one server issue event, localized, from the facts the event keeps (the server may be gone).
     *
     * @param  array<string, mixed>  $facts  the event payload ServerIssueAlerts recorded
     */
    public function message(array $facts, string $serverName, bool $recovered, string $locale, string $timezone): ServerIssueMessage;

    /**
     * Whether a reporting server is this instance's own host. Its disk alert then speaks for the instance's disk, so the
     * DiskSpaceLow system issue is not delivered as well.
     */
    public function monitorsInstanceHost(): bool;
}
