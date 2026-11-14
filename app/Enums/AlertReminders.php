<?php

declare(strict_types=1);

namespace Heptapod\Enums;

/** When an ongoing uptime outage sends "still down" reminders. */
enum AlertReminders: string
{
    case Standard = 'standard';
    case Daily = 'daily';
}
