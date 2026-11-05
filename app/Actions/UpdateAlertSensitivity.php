<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Settings\InstanceSettings;

final readonly class UpdateAlertSensitivity
{
    public function __construct(private InstanceSettings $settings) {}

    public function __invoke(int $afterMinutes, int $recoveryChecks): void
    {
        $this->settings->alert_after_minutes = $afterMinutes;
        $this->settings->alert_recovery_checks = $recoveryChecks;
        $this->settings->save();
    }
}
