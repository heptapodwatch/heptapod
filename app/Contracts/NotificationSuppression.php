<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Carbon\CarbonImmutable;

interface NotificationSuppression
{
    /** Evaluate and store this decision inside the recorder transaction, including suppressed events.
     * A null addon-event subject means the current check; reject subjects belonging to another check.
     * Delivery must preserve the stored decision. Delayed uptime openers additionally
     * recheck current snooze/maintenance boundaries when due; they never un-suppress an event. */
    public function isSuppressed(SuppressionSubject $subject, CarbonImmutable $at): SuppressionDecision;
}
