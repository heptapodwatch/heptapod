<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

interface RunControl
{
    /** @throws RunCancelled */
    public function assertActive(): void;
}
