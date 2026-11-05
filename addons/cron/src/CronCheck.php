<?php

declare(strict_types=1);

namespace Heptapod\Addons\Cron;

use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Heptapod\Contracts\CheckResult;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;

/** @property int $id
 * @property int $check_id
 * @property string $uuid
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $latest_ping_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property string $name
 * @property string $type
 * @property int|null $frequency_in_minutes
 * @property int|null $grace_time_in_minutes
 * @property string|null $cron_expression
 * @property string|null $server_timezone
 * @property CarbonImmutable $next_due_at
 * @property CronTaskResult $latest_result
 * @property CheckResult $aggregate_result
 * @property CarbonImmutable|null $evaluated_failure_at
 * @property CheckResult|null $evaluated_result
 * @property int $ping_revision
 * @property int|null $evaluated_revision
 * @property int $failure_revision
 * @property int $evaluated_failure_revision
 * @property array<string, mixed>|null $ping_meta
 * @property int $late_by_seconds
 */
#[DateFormat('Y-m-d H:i:s.uP')]
#[Guarded(['id'])]
final class CronCheck extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'frequency_in_minutes' => 'integer',
            'grace_time_in_minutes' => 'integer',
            'ping_revision' => 'integer',
            'evaluated_revision' => 'integer',
            'failure_revision' => 'integer',
            'evaluated_failure_revision' => 'integer',
            'late_by_seconds' => 'integer',
            'ping_meta' => 'array',
            'latest_result' => CronTaskResult::class,
            'aggregate_result' => CheckResult::class,
            'evaluated_result' => CheckResult::class,
            'next_due_at' => 'immutable_datetime',
            'evaluated_failure_at' => 'immutable_datetime',
            'latest_ping_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function graceMinutes(): int
    {
        $minutes = $this->grace_time_in_minutes ?? Config::integer('cron.default_grace_time_in_minutes');
        if ($minutes < 0) {
            throw new \InvalidArgumentException('Cron grace must be nonnegative.');
        }

        return $minutes;
    }

    public function nextDueAt(CarbonImmutable $after): CarbonImmutable
    {
        if ($this->type === 'simple') {
            return $after->addMinutes($this->frequency_in_minutes ?? throw new \LogicException('Simple cron check requires a frequency.'));
        }

        $expression = $this->cron_expression ?? throw new \LogicException('Cron check requires an expression.');
        $timezone = $this->server_timezone ?? throw new \LogicException('Cron check requires a timezone.');

        return CarbonImmutable::instance(new CronExpression($expression)->getNextRunDate($after, timeZone: $timezone))->utc();
    }
}
