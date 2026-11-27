<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Carbon\CarbonImmutable;

/**
 * How server issues (servers addon) reach the team: core records one event per issue and phase and routes it under the
 * Servers notification category, through the shared delivery ledger. The caller holds the issue's row lock.
 *
 * @phpstan-type ServerIssueFacts array<string, string|int|float|null>
 */
interface ServerIssueAlerts
{
    /**
     * An issue opened. While the server is snoozed (ServerIssues::snoozedUntil) its openings wait until the snooze ends
     * and send then, if the issue is still open.
     *
     * @param  ServerIssueFacts  $facts  what ServerIssues::message() renders the alert from later
     */
    public function opened(int $issueId, int $serverId, string $serverName, CarbonImmutable $at, array $facts): void;

    /**
     * An issue resolved. Openings still waiting are dropped; a recovery goes to the channels its opening reached, unless
     * $silencedBy names why it ends without one (`snooze`, `server_removed`).
     *
     * @param  ServerIssueFacts  $facts
     */
    public function resolved(int $issueId, int $serverId, string $serverName, CarbonImmutable $at, array $facts, ?string $silencedBy): void;

    /**
     * A snooze set, changed or ended: the held openings and recurring digest lines of these open issues are due now, so
     * their next claim or digest applies the server's current snooze.
     *
     * @param  list<int>  $openIssueIds
     */
    public function snoozeChanged(array $openIssueIds): void;
}
