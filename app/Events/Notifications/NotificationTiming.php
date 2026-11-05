<?php

declare(strict_types=1);

namespace Heptapod\Events\Notifications;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Heptapod\Contracts\Notifications\AlertSeverity;
use Heptapod\Models\NotificationSchedule;
use Heptapod\Settings\InstanceSettings;

final readonly class NotificationTiming
{
    /**
     * Step 2 of routing (design §2.1): when one channel gets one event. Digest only collects the Warning and
     * Error severity; Down/Failed and incident recoveries bypass quiet hours (NotificationEventTypes::critical).
     *
     * @return array{due: CarbonImmutable, digest: bool, reason: string}
     */
    public function schedule(RoutingContext $context, NotificationSchedule $schedule, CarbonImmutable $at, bool $digestCell): array
    {
        $digest = $digestCell && in_array($context->severity, [AlertSeverity::Warning, AlertSeverity::Error], true);
        $notBefore = $context->notBefore;
        $from = $notBefore instanceof CarbonImmutable && $notBefore->greaterThan($at) ? $notBefore : $at;
        $due = $digest ? $this->nextDailyDigest($from, $schedule->effectiveDigestWeekdays(), $schedule->digest_time, $schedule->timezone())
            : ($context->critical ? $from : $this->afterQuietHours($schedule, $from));
        $reason = match (true) {
            $digest => 'digest',
            $due->greaterThan($from) => 'quiet_hours',
            $due->greaterThan($at) => 'alert_delay',
            default => 'now',
        };

        return ['due' => $due, 'digest' => $digest, 'reason' => $reason];
    }

    public function afterQuietHours(NotificationSchedule $schedule, CarbonImmutable $at): CarbonImmutable
    {
        $local = $at->setTimezone($schedule->timezone());
        if ($schedule->quiet_weekends && $local->dayOfWeekIso >= 6) {
            $monday = $local->startOfWeek()->addWeek();

            return $this->boundary($monday, $schedule->quiet_end ?? '07:00', true);
        }
        if ($schedule->quiet_start === null || $schedule->quiet_end === null) {
            return $at;
        }
        // Calendar windows, not wall-clock comparisons: a spring gap must not
        // leak messages before its normalised end; a fold includes both hours.
        foreach ([-1, 0] as $days) {
            $day = $local->addDays($days);
            $endDay = $schedule->quiet_start > $schedule->quiet_end ? $day->addDay() : $day;
            $start = $this->boundary($day, $schedule->quiet_start, false);
            $end = $this->boundary($endDay, $schedule->quiet_end, true);
            if ($at->greaterThanOrEqualTo($start) && $at->lessThan($end)) {
                return $end;
            }
        }

        return $at;
    }

    /** @param list<int>|null $weekdays */
    public function nextDailyDigest(CarbonImmutable $at, ?array $weekdays = null, string $time = '08:00', ?string $timezone = null): CarbonImmutable
    {
        if ($weekdays === []) {
            throw new \LogicException('Digest schedule needs at least one weekday.');
        }
        $local = $at->setTimezone($timezone ?? app(InstanceSettings::class)->timezone);
        for ($days = 0; $days <= 7; $days++) {
            $day = $local->addDays($days);
            if ($weekdays !== null && ! in_array($day->dayOfWeekIso, $weekdays, true)) {
                continue;
            }
            $next = $this->boundary($day, $time, true);
            if ($next->greaterThan($at)) {
                return $next;
            }
        }

        throw new \LogicException('Digest schedule has no upcoming day.');
    }

    private function boundary(CarbonImmutable $day, string $time, bool $later): CarbonImmutable
    {
        $wall = $day->format('Y-m-d').' '.$time;
        $native = $day->setTimeFromTimeString($time)->setMicrosecond(0);
        $nominal = CarbonImmutable::parse($wall, 'UTC')->getTimestamp();
        $zone = new DateTimeZone($day->getTimezone()->getName());
        $transitions = $zone->getTransitions($nominal - 172800, $nominal + 172800);
        $matches = [];
        foreach (array_unique(array_column($transitions, 'offset')) as $offset) {
            $candidate = CarbonImmutable::createFromTimestampUTC($nominal - $offset);
            if ($candidate->setTimezone($zone)->format('Y-m-d H:i') === $wall) {
                $matches[] = $candidate->getTimestamp();
            }
        }

        // A nonexistent spring time uses native forward normalisation. Repeated
        // fall starts use the earlier occurrence; ends/digests use the later one.
        return $matches === [] ? $native->utc()
            : CarbonImmutable::createFromTimestampUTC($later ? max($matches) : min($matches));
    }
}
