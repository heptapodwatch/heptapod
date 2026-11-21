<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

/** External seam that checks whether this monitor can resolve outside names and reach outside IPs directly. */
interface ControlProbe
{
    public function probe(): ControlProbeResult;
}
