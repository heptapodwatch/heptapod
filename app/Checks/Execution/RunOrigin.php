<?php

declare(strict_types=1);

namespace Heptapod\Checks\Execution;

/** Manual runs are budgeted; scheduled and internal system reruns never consume or wait for manual budgets. */
enum RunOrigin: string
{
    case Scheduled = 'scheduled';
    case Manual = 'manual';
    case System = 'system';
}
