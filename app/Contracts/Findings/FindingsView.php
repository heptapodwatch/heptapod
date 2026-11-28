<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Findings;

/** Check report filters. Historical exports use the unfiltered snapshot. */
enum FindingsView: string
{
    case Raw = 'raw';
    case Current = 'current';
    case Ignored = 'ignored';
}
