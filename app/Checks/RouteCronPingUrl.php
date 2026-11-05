<?php

declare(strict_types=1);

namespace Heptapod\Checks;

use Heptapod\Contracts\CronPingUrl;

final class RouteCronPingUrl implements CronPingUrl
{
    public function for(string $uuid): string
    {
        return route('cron.ping', ['uuid' => $uuid]);
    }
}
