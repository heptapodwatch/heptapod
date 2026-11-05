<?php

namespace Heptapod\Maintenance;

use Carbon\CarbonImmutable;
use DateTimeZone;

final class LocalMaintenanceTime
{
    /** RFC 5545: choose the first ambiguous instant, and the pre-gap offset for missing times. */
    public function resolve(string $date, string $time, string $timezone): CarbonImmutable
    {
        $wall = CarbonImmutable::parse($date.' '.$time, 'UTC')->getTimestamp();
        $zone = new DateTimeZone($timezone);
        $transitions = $zone->getTransitions($wall - 172800, $wall + 172800);
        $candidates = [];
        foreach ($transitions as $transition) {
            $candidate = CarbonImmutable::createFromTimestampUTC($wall - $transition['offset']);
            if ($candidate->setTimezone($zone)->format('Y-m-d H:i') === $date.' '.$time) {
                $candidates[] = $candidate->getTimestamp();
            }
        }
        if ($candidates !== []) {
            return CarbonImmutable::createFromTimestampUTC(min($candidates));
        }
        $previousOffset = $transitions[0]['offset'];
        foreach ($transitions as $transition) {
            if ($wall >= $transition['ts'] + $previousOffset && $wall < $transition['ts'] + $transition['offset']) {
                return CarbonImmutable::createFromTimestampUTC($wall - $previousOffset);
            }
            $previousOffset = $transition['offset'];
        }
        throw new \LogicException('Cannot resolve maintenance wall time.');
    }
}
