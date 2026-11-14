<?php

declare(strict_types=1);

namespace Heptapod\SystemHealth;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Measured database size per day and its retention-aware projection from the latest sample. */
#[TypeScript]
final class DatabaseGrowthData extends Data
{
    /**
     * @param  list<array{date: string, bytes: int}>  $history  one point per recorded day
     * @param  list<array{date: string, bytes: int}>  $projection  piecewise linear from the latest sample to one year ahead
     * @param  list<DatabaseGrowthClassData>  $classes
     */
    public function __construct(
        public readonly string $sampledAt,
        public readonly int $sampleCount,
        /** False until enough daily samples exist: the projection then rests on the current intake only. */
        public readonly bool $measured,
        public readonly array $history,
        public readonly array $projection,
        public readonly int $in30DaysBytes,
        public readonly int $in90DaysBytes,
        public readonly int $in365DaysBytes,
        /** Set only when every growing class is bounded by an enforced retention window. */
        public readonly ?int $plateauBytes,
        public readonly ?string $plateauOn,
        /** Growth per 30 days once the bounded classes have leveled off; null when the database levels off. */
        public readonly ?int $monthlyGrowthBytes,
        public readonly array $classes,
        public readonly DatabaseDiskData $disk,
    ) {}
}
