<?php

declare(strict_types=1);

namespace Heptapod\Models;

use Carbon\CarbonImmutable;
use Database\Factories\RecurringMaintenancePeriodFactory;
use Heptapod\Concerns\AuditsConfiguration;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int|null $site_id
 * @property string $target_type
 * @property int|null $target_id
 * @property string|null $reason
 * @property bool $mute_alerts
 * @property bool $catch_up_at_end
 * @property int $interval
 * @property int|null $nth_weekday
 * @property int|null $day_of_week
 * @property CarbonImmutable|null $anchor_date
 * @property string|null $name
 * @property string $recurrence_type
 * @property list<int>|null $days_of_week
 * @property int|null $day_of_month
 * @property string $start_time
 * @property string $end_time
 * @property string $timezone
 * @property CarbonImmutable|null $last_generated_until
 */
#[DateFormat('Y-m-d H:i:s.uP')]
#[Guarded(['id'])]
final class RecurringMaintenancePeriod extends Model
{
    use AuditsConfiguration;

    /** @use HasFactory<RecurringMaintenancePeriodFactory> */
    use HasFactory;

    protected function auditedAttributes(): array
    {
        return ['site_id', 'target_type', 'target_id', 'name', 'reason', 'mute_alerts', 'catch_up_at_end', 'recurrence_type', 'interval', 'nth_weekday', 'day_of_week', 'days_of_week', 'day_of_month', 'start_time', 'end_time', 'timezone', 'anchor_date'];
    }

    protected function casts(): array
    {
        return ['days_of_week' => 'array', 'day_of_month' => 'integer', 'interval' => 'integer', 'nth_weekday' => 'integer', 'day_of_week' => 'integer',
            'anchor_date' => 'immutable_date', 'mute_alerts' => 'boolean', 'catch_up_at_end' => 'boolean', 'last_generated_until' => 'immutable_date'];
    }
}
