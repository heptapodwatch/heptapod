<?php

declare(strict_types=1);

namespace Heptapod\Http\Resources\OhDear;

use Carbon\CarbonImmutable;
use Heptapod\Models\MaintenancePeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MaintenancePeriod */
final class MaintenancePeriodResource extends JsonResource
{
    #[\Override]
    public static $wrap;

    public function __construct(mixed $resource, private readonly ?int $monitorId = null,
        private readonly ?CarbonImmutable $siteEndAt = null)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var MaintenancePeriod $period */
        $period = $this->resource;

        $end = $this->siteEndAt instanceof CarbonImmutable && $this->siteEndAt->lessThan($period->ends_at) ? $this->siteEndAt : $period->ends_at;

        return ['id' => $period->id, 'monitor_id' => $this->monitorId ?? $period->site_id,
            'name' => $period->name, 'starts_at' => $period->starts_at->utc()->format('Y-m-d H:i:s'),
            'ends_at' => $end->utc()->format('Y-m-d H:i:s'),
            'scope' => $period->target_type, 'shared' => $period->target_type !== 'site'];
    }
}
