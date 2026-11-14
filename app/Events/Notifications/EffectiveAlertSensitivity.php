<?php

declare(strict_types=1);

namespace Heptapod\Events\Notifications;

use Heptapod\Enums\AlertReminders;
use Heptapod\Enums\AlertSensitivity;
use Heptapod\Models\Site;
use Heptapod\Settings\InstanceSettings;

/** The four alert sensitivity settings that apply to a site: its override, or the team default it inherits. */
final readonly class EffectiveAlertSensitivity
{
    public ?AlertSensitivity $preset;

    public function __construct(
        public int $alertAfterMinutes,
        public int $recoveryChecks,
        public bool $confirmsOtherChecks,
        public AlertReminders $reminders,
        public bool $inherited,
    ) {
        $this->preset = AlertSensitivity::matching($alertAfterMinutes, $recoveryChecks, $confirmsOtherChecks, $reminders);
    }

    public static function for(Site $site, InstanceSettings $settings): self
    {
        // The sites check constraint keeps the four override columns all null or all set.
        return $site->alert_after_minutes === null || $site->alert_recovery_checks === null
            || $site->alert_confirms_other_checks === null || $site->alert_reminders === null
            ? self::team($settings, true)
            : new self($site->alert_after_minutes, $site->alert_recovery_checks, $site->alert_confirms_other_checks, $site->alert_reminders, false);
    }

    public static function team(InstanceSettings $settings, bool $inherited = false): self
    {
        return new self($settings->alert_after_minutes, $settings->alert_recovery_checks, $settings->alert_confirms_other_checks, $settings->alert_reminders, $inherited);
    }

    public static function preset(AlertSensitivity $preset): self
    {
        return new self($preset->alertAfterMinutes(), $preset->recoveryChecks(), $preset->confirmsOtherChecks(), $preset->reminders(), false);
    }
}
