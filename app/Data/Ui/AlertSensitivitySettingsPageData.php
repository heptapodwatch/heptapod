<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class AlertSensitivitySettingsPageData extends Data
{
    /**
     * @param  list<AlertSensitivityData>  $presets
     * @param  array{minutes_min: int, minutes_max: int, stored_minutes_min: int, stored_minutes_max: int, recovery_min: int, recovery_max: int}  $bounds
     */
    public function __construct(
        public readonly AlertSensitivityData $saved,
        public readonly array $presets,
        public readonly array $bounds,
    ) {}
}
