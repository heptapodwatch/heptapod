<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Mail;

enum AlertMailEvent: string
{
    case Opened = 'incident.opened';
    case Reminder = 'incident.still_down';
    case Recovered = 'incident.resolved';
    case Warning = 'warning';
    case Changed = 'changed';
    case CheckError = 'check-error';
    case CauseChanged = 'incident.cause_changed';
}
