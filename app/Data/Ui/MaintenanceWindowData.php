<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class MaintenanceWindowData extends Data
{
    /**
     * @param  list<string>  $actions
     * @param  list<int>|null  $daysOfWeek
     */
    public function __construct(
        public readonly int $id,
        public readonly ?int $seriesId,
        public readonly string $state,
        public readonly string $targetType,
        public readonly int $targetId,
        public readonly ?string $targetLabel,
        public readonly int $siteCount,
        public readonly ?string $name,
        public readonly ?string $reason,
        public readonly string $startsAt,
        public readonly string $endsAt,
        public readonly string $timezone,
        public readonly ?string $recurrenceType,
        public readonly int $interval,
        public readonly ?int $nthWeekday,
        public readonly ?int $dayOfWeek,
        public readonly bool $muteAlerts,
        public readonly bool $catchUpAtEnd,
        public readonly array $actions,
        public readonly ?array $daysOfWeek = null,
        public readonly ?int $dayOfMonth = null,
        public readonly ?string $startTime = null,
        public readonly ?string $endTime = null,
        public readonly ?string $anchorDate = null,
        public readonly ?string $seriesTimezone = null,
    ) {}
}
