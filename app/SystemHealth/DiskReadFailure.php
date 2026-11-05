<?php

declare(strict_types=1);

namespace Heptapod\SystemHealth;

/** Why a filesystem could not be measured; UI and mail name it through `disk.read_failure.<value>` with the path as parameter. */
enum DiskReadFailure: string
{
    case Missing = 'missing';
    case InvalidCapacity = 'invalid_capacity';
    case ReadFailed = 'read_failed';
}
