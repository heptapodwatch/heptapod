<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

interface CronPingUrl
{
    public function for(string $uuid): string;
}
