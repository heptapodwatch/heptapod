<?php

declare(strict_types=1);

namespace Heptapod\Enums;

/** Why an alert mail is built from retained event facts instead of the live check's mail evidence. */
enum RetainedAlert
{
    /** The site was deleted after the claim: its retained name, client and check type remain, without links. */
    case SiteDeleted;
    /** The check was removed after the claim: the site remains, the check's details do not. */
    case CheckRemoved;
    /** The check's add-on is not installed, so nothing can present its evidence. */
    case EvidenceUnavailable;
    /** A reminder that no addon declares (non-uptime reminders predate the uptime-only rule). */
    case Reminder;
    /** A component event whose observation was pruned and whose addon cannot present it from the event alone. */
    case ComponentDetailsPruned;
    /**
     * The incident was closed without a measured recovery: monitoring stopped (IncidentResolutionReason), or the check
     * itself closed it because nothing is left to check (cron without tasks). Both retain a resolution_key.
     */
    case ClosedWithoutRecovery;
}
