<?php

declare(strict_types=1);

namespace Heptapod\Addons\Cron;

use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Heptapod\Contracts\UpcomingEventSource;
use Heptapod\Contracts\UpcomingOccurrence;
use Heptapod\Contracts\UpcomingOccurrencePage;
use Heptapod\Contracts\UpcomingPosition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

final readonly class CronUpcomingSource implements UpcomingEventSource
{
    /** Upper bound on rare cron tasks returned by a direct read() call. */
    public const int MaxReadItems = 2000;

    public function read(array $visibleCheckIds, CarbonImmutable $from, CarbonImmutable $until): array
    {
        return $this->page($visibleCheckIds, $from, $until, null, self::MaxReadItems)->occurrences;
    }

    public function page(array $visibleCheckIds, CarbonImmutable $from, CarbonImmutable $until, ?UpcomingPosition $after, int $limit): UpcomingOccurrencePage
    {
        if ($visibleCheckIds === []) {
            return new UpcomingOccurrencePage([], false);
        }
        $query = $this->candidates($visibleCheckIds, $from, $until);
        $key = "('task:' || id::text) COLLATE \"C\"";
        if ($after instanceof UpcomingPosition) {
            $query->getQuery()->whereRaw('(next_due_at, '.$key.') > (?::timestamptz, ? COLLATE "C")', [$after->at->format('Y-m-d H:i:s.uP'), $after->key]);
        }
        $query->getQuery()->orderBy('next_due_at')->orderByRaw($key);
        $items = [];
        // Rarity needs the cron expression, so filter before deciding whether the page is full.
        foreach ($query->lazy(200) as $task) {
            if (! $this->isRare($task)) {
                continue;
            }
            if (count($items) === $limit) {
                return new UpcomingOccurrencePage($items, true);
            }
            $items[] = new UpcomingOccurrence($task->check_id, 'task:'.$task->id, $task->next_due_at, 'task', $task->name);
        }

        return new UpcomingOccurrencePage($items, false);
    }

    /** @param list<int> $visibleCheckIds
     * @return Builder<CronCheck>
     */
    private function candidates(array $visibleCheckIds, CarbonImmutable $from, CarbonImmutable $until): Builder
    {
        $query = CronCheck::query();
        $query->getQuery()->whereIntegerInRaw('check_id', $visibleCheckIds)
            ->where('next_due_at', '>=', $from)->where('next_due_at', '<=', $until)
            ->where(static function (QueryBuilder $query): void {
                $query->where('frequency_in_minutes', '>=', 1440)->orWhere('type', 'cron');
            });

        return $query;
    }

    private function isRare(CronCheck $task): bool
    {
        if ($task->type !== 'cron') {
            return true;
        }
        $expression = new CronExpression($task->cron_expression ?? throw new \LogicException('Cron check requires an expression.'));
        $timezone = $task->server_timezone ?? throw new \LogicException('Cron check requires a timezone.');
        // Compare consecutive schedule slots in UTC from the local wall clock so DST shifts do not shrink a daily slot below 1440 minutes.
        $wallClock = CarbonImmutable::parse($task->next_due_at->setTimezone($timezone)->format('Y-m-d H:i:s'), 'UTC');
        $first = CarbonImmutable::instance($expression->getNextRunDate($wallClock, allowCurrentDate: true, timeZone: 'UTC'));
        $second = CarbonImmutable::instance($expression->getNextRunDate($first, timeZone: 'UTC'));

        return $first->diffInMinutes($second) >= 1440;
    }
}
