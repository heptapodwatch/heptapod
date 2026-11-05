<?php

namespace Heptapod\Maintenance;

use Carbon\CarbonImmutable;
use Heptapod\Models\RecurringMaintenancePeriod;
use RRule\RRule;

final readonly class MaintenanceOccurrences
{
    public function __construct(private LocalMaintenanceTime $localTime) {}

    /** @return iterable<array{CarbonImmutable, CarbonImmutable}> */
    public function __invoke(RecurringMaintenancePeriod $period, string $from, string $through): iterable
    {
        // RRULE enumerates calendar dates in UTC; resolve local wall times separately so DST never loses an occurrence.
        $rule = ['FREQ' => strtoupper($period->recurrence_type), 'DTSTART' => $period->anchor_date?->format('Y-m-d') ?? $from,
            'UNTIL' => $through.' 23:59:59', 'INTERVAL' => $period->interval ?? 1];
        if ($period->recurrence_type === 'weekly') {
            $days = ['SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA'];
            $rule['BYDAY'] = array_map(fn (int $day): string => $days[$day], $period->days_of_week ?? []);
        }
        if ($period->recurrence_type === 'monthly') {
            if ($period->nth_weekday !== null) {
                $weekday = $period->day_of_week ?? throw new \LogicException('Nth weekday requires a day of week.');
                $rule['BYDAY'] = $period->nth_weekday.(['SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA'][$weekday]
                    ?? throw new \LogicException('Invalid maintenance weekday.'));
            } else {
                $rule['BYMONTHDAY'] = $period->day_of_month;
            }
        }
        /** @var \DateTime $date */
        foreach (new RRule($rule) as $date) {
            $day = CarbonImmutable::instance($date);
            if ($day->format('Y-m-d') < $from) {
                continue;
            }
            $start = $this->localTime->resolve($day->format('Y-m-d'), $period->start_time, $period->timezone);
            $endDay = $period->end_time <= $period->start_time ? $day->addDay() : $day;
            $end = $this->localTime->resolve($endDay->format('Y-m-d'), $period->end_time, $period->timezone);
            // A start inside the gap shifts the whole window, preserving its wall-clock duration.
            $shift = $start->setTimezone($period->timezone)->getTimestamp() + $start->setTimezone($period->timezone)->offset - CarbonImmutable::parse($day->format('Y-m-d').' '.$period->start_time, 'UTC')->getTimestamp();
            if ($shift > 0) {
                $duration = CarbonImmutable::parse($day->format('Y-m-d').' '.$period->start_time, 'UTC')->diffInSeconds(CarbonImmutable::parse($endDay->format('Y-m-d').' '.$period->end_time, 'UTC'));
                $end = $start->addSeconds($duration);
            }
            yield [$start, $end];
        }
    }
}
