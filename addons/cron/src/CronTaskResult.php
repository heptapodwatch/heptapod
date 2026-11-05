<?php

declare(strict_types=1);

namespace Heptapod\Addons\Cron;

// TODO: Verify these protocol values against real Oh Dear cron observations; no real cron data has been available.
enum CronTaskResult: string
{
    case Pending = 'pending';
    case Finished = 'pingFinished';
    case Failed = 'pingFailed';
    case Missed = 'pingMissed';
}
