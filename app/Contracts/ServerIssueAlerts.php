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
     * An issue opened. While the server is snoozed its openings wait until the snooze ends and send then, if the issue is
     * still open.
     *
     * @param  ServerIssueFacts  $facts  what ServerIssues::message() renders the alert from later
     */
    public function opened(int $issueId, int $serverId, string $serverName, CarbonImmutable $at, array $facts, ?CarbonImmutable $snoozedUntil): void;

    /**
     * An issue resolved. Openings still waiting are dropped; a recovery goes to the channels its opening reached, unless
     * $silencedBy names why it ends without one (`snooze`, `server_removed`).
     *
     * @param  ServerIssueFacts  $facts
     */
    public function resolved(int $issueId, int $serverId, string $serverName, CarbonImmutable $at, array $facts, ?string $silencedBy): void;

    /**
     * A snooze set or changed: the waiting openings of these open issues wait until $until, or send now when the snooze
     * ended (null).
     *
     * @param  list<int>  $openIssueIds
     */
    public function snoozeChanged(array $openIssueIds, ?CarbonImmutable $until): void;
}
