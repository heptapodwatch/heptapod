<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

interface ReportsAvailability
{
    public function availability(): CheckAvailability;
}
