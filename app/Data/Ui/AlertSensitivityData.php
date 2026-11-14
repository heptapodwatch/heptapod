<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Enums\AlertReminders;
use Heptapod\Enums\AlertSensitivity;
use Heptapod\Events\Notifications\EffectiveAlertSensitivity;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class AlertSensitivityData extends Data
{
    public function __construct(
        public readonly int $alert_after_minutes,
        public readonly int $alert_recovery_checks,
        public readonly bool $alert_confirms_other_checks,
        public readonly AlertReminders $alert_reminders,
        public readonly bool $inherited,
        public readonly ?AlertSensitivity $preset,
    ) {}

    public static function fromEffective(EffectiveAlertSensitivity $sensitivity): self
    {
        return new self($sensitivity->alertAfterMinutes, $sensitivity->recoveryChecks, $sensitivity->confirmsOtherChecks,
            $sensitivity->reminders, $sensitivity->inherited, $sensitivity->preset);
    }

    /** @return list<self> */
    public static function presets(): array
    {
        return array_map(static fn (AlertSensitivity $preset): self => self::fromEffective(EffectiveAlertSensitivity::preset($preset)), AlertSensitivity::cases());
    }
}
