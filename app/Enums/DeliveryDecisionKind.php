<?php

declare(strict_types=1);

namespace Heptapod\Enums;

enum DeliveryDecisionKind
{
    /** Hand the delivery out (claim) or transmit the rendered payload (send). */
    case Send;
    /** Never send it. */
    case Suppress;
    /** Not now: due again at a later time. */
    case Hold;
    /** A digest whose items changed goes back to the claim, which builds it again from the items that still pass. */
    case Rebuild;
    /** The plan already moved the row itself (a deferred episode opener: HandleDeferredUptimeOpening). */
    case Moved;
}
