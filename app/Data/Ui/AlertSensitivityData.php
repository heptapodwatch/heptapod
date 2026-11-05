<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Enums\AlertSensitivity;
use Heptapod\Events\Notifications\AlertDelay;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class AlertSensitivityData extends Data
{
    public function __construct(
        public readonly int $alert_after_minutes,
        public readonly int $alert_recovery_checks,
        public readonly bool $inherited,
        public readonly ?AlertSensitivity $preset,
    ) {}

    public static function fromDelay(AlertDelay $delay): self
    {
        return new self($delay->alertAfterMinutes, $delay->recoveryChecks, $delay->inherited, $delay->preset);
    }

    /** @return list<self> */
    public static function presets(): array
    {
        return array_map(static fn (AlertSensitivity $preset): self => new self($preset->alertAfterMinutes(), $preset->recoveryChecks(), false, $preset), AlertSensitivity::cases());
    }
}
