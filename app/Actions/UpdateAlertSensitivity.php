<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Events\Notifications\EffectiveAlertSensitivity;
use Heptapod\Settings\InstanceSettings;

final readonly class UpdateAlertSensitivity
{
    public function __construct(private InstanceSettings $settings) {}

    public function __invoke(EffectiveAlertSensitivity $sensitivity): void
    {
        $this->settings->alert_after_minutes = $sensitivity->alertAfterMinutes;
        $this->settings->alert_recovery_checks = $sensitivity->recoveryChecks;
        $this->settings->alert_confirms_other_checks = $sensitivity->confirmsOtherChecks;
        $this->settings->alert_reminders = $sensitivity->reminders;
        $this->settings->save();
    }
}
