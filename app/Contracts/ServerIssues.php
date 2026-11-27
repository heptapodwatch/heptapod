<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Carbon\CarbonImmutable;

/** What core needs from the servers addon to deliver and show server issues. */
interface ServerIssues
{
    /**
     * The alert for one server issue event, localized, from the facts the event keeps (the server may be gone).
     *
     * @param  array<string, mixed>  $facts  the event payload ServerIssueAlerts recorded
     */
    public function message(array $facts, string $serverName, bool $recovered, string $locale, string $timezone): ServerIssueMessage;

    /** Until when the server's alerts are snoozed; null when they are not, or the server is gone. Read live, never cached. */
    public function snoozedUntil(int $serverId): ?CarbonImmutable;
}
