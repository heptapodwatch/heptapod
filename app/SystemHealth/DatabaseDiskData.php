<?php

declare(strict_types=1);

namespace Heptapod\SystemHealth;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Free space on the PostgreSQL data path against the projected growth. Without a readable path every figure is null. */
#[TypeScript]
final class DatabaseDiskData extends Data
{
    public function __construct(
        /** Null when the data path is neither configured nor visible to the app. */
        public readonly ?string $path,
        public readonly ?DiskReadFailure $failure,
        public readonly ?int $freeBytes,
        /** The first day on which the growth projected from today exceeds today's free space, within one year from today. */
        public readonly ?string $fillsOn,
        /** Free space left after one more year of projected growth, when the disk does not fill. */
        public readonly ?int $spareBytes,
    ) {}
}
