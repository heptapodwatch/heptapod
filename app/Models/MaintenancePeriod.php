<?php

namespace Heptapod\Models;

use Carbon\CarbonImmutable;
use Database\Factories\MaintenancePeriodFactory;
use Heptapod\Actions\HandleDeferredUptimeOpening;
use Heptapod\Concerns\AuditsConfiguration;
use Heptapod\Maintenance\MaintenanceTargets;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * @property int $id
 * @property int|null $site_id
 * @property string $target_type
 * @property int|null $target_id
 * @property string|null $name
 * @property string|null $reason
 * @property string|null $timezone
 * @property bool $mute_alerts
 * @property bool $catch_up_at_end
 * @property CarbonImmutable|null $catch_up_processed_at
 * @property int|null $recurring_maintenance_period_id
 * @property RecurringMaintenancePeriod|null $recurrence
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 */
#[DateFormat('Y-m-d H:i:s.uP')]
#[Guarded(['id'])]
final class MaintenancePeriod extends Model
{
    use AuditsConfiguration;

    /** @use HasFactory<MaintenancePeriodFactory> */
    use HasFactory;

    protected function auditedAttributes(): array
    {
        return ['site_id', 'target_type', 'target_id', 'name', 'reason', 'mute_alerts', 'catch_up_at_end', 'starts_at', 'ends_at', 'recurring_maintenance_period_id'];
    }

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'catch_up_processed_at' => 'immutable_datetime', 'start_signalled_at' => 'immutable_datetime', 'end_signalled_at' => 'immutable_datetime', 'mute_alerts' => 'boolean', 'catch_up_at_end' => 'boolean'];
    }

    /**
     * A moved boundary must be announced again, so only the marker of a changed boundary is reset.
     * A boundary moved into the past is announced by the same save: the mutation's own live signal covers it.
     */
    protected static function booted(): void
    {
        self::saving(static function (self $period): void {
            if ($period->isDirty('starts_at')) {
                $period->start_signalled_at = $period->starts_at->lessThanOrEqualTo(CarbonImmutable::now('UTC')) ? CarbonImmutable::now('UTC') : null;
            }
            if ($period->isDirty('ends_at')) {
                $period->end_signalled_at = $period->ends_at->lessThanOrEqualTo(CarbonImmutable::now('UTC')) ? CarbonImmutable::now('UTC') : null;
            }
        });
        self::updated(static function (self $period): void {
            if ($period->wasChanged(['ends_at', 'mute_alerts'])) {
                HandleDeferredUptimeOpening::wakeWindow($period->id, $period->mute_alerts ? $period->ends_at : CarbonImmutable::now('UTC'));
            }
        });
    }

    /** @return BelongsTo<RecurringMaintenancePeriod, $this> */
    public function recurrence(): BelongsTo
    {
        return $this->belongsTo(RecurringMaintenancePeriod::class, 'recurring_maintenance_period_id');
    }

    /** @return Builder<self> */
    public static function activeForSite(int $siteId, CarbonImmutable $at): Builder
    {
        $query = self::activeAt($at);
        $query->getQuery()->whereIn('maintenance_periods.id', self::forSite($siteId, $at)->getQuery()->select('maintenance_periods.id'));

        return $query;
    }

    /** @return Builder<self> */
    public static function targetingSite(int $siteId): Builder
    {
        $query = self::query();
        MaintenanceTargets::whereCoversSiteId($query->getQuery(), $siteId);

        return $query;
    }

    /** @return Builder<self> */
    public static function forSite(int $siteId, ?CarbonImmutable $at = null): Builder
    {
        $query = self::targetingSite($siteId);
        $timestamp = ($at ?? CarbonImmutable::now('UTC'))->format('Y-m-d H:i:s.uP');
        $query->getQuery()->whereNotExists(static fn (QueryBuilder $exclusions): QueryBuilder => $exclusions
            ->selectRaw('1')->from('maintenance_site_exclusions as exclusion')
            ->whereColumn('exclusion.maintenance_period_id', 'maintenance_periods.id')
            ->where('exclusion.site_id', $siteId)->where('exclusion.left_at', '<=', $timestamp));

        return $query;
    }

    /** @return Builder<self> */
    public static function activeAt(CarbonImmutable $at): Builder
    {
        // Laravel's default date binding loses microseconds; preserve the stored interval boundaries.
        $timestamp = $at->format('Y-m-d H:i:s.uP');

        return self::query()->where('starts_at', '<=', $timestamp)->where('ends_at', '>', $timestamp);
    }
}
