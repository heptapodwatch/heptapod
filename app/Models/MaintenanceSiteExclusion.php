<?php

declare(strict_types=1);

namespace Heptapod\Models;

use Carbon\CarbonImmutable;
use Heptapod\Actions\HandleDeferredUptimeOpening;
use Heptapod\Concerns\AuditsConfiguration;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $maintenance_period_id
 * @property int $site_id
 * @property CarbonImmutable $left_at
 * @property CarbonImmutable|null $catch_up_processed_at
 */
#[DateFormat('Y-m-d H:i:s.uP')]
#[Guarded(['id'])]
final class MaintenanceSiteExclusion extends Model
{
    use AuditsConfiguration;

    protected static function booted(): void
    {
        self::saved(static function (self $exclusion): void {
            if ($exclusion->wasRecentlyCreated || $exclusion->wasChanged('left_at')) {
                HandleDeferredUptimeOpening::wakeWindow($exclusion->maintenance_period_id, $exclusion->left_at, $exclusion->site_id);
            }
        });
    }

    protected function auditedAttributes(): array
    {
        return ['maintenance_period_id', 'site_id', 'left_at'];
    }

    protected function casts(): array
    {
        return ['left_at' => 'immutable_datetime', 'catch_up_processed_at' => 'immutable_datetime'];
    }
}
