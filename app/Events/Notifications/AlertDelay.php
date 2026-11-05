<?php

declare(strict_types=1);

namespace Heptapod\Events\Notifications;

use Heptapod\Enums\AlertSensitivity;
use Heptapod\Models\Site;
use Heptapod\Settings\InstanceSettings;

final readonly class AlertDelay
{
    public ?AlertSensitivity $preset;

    public function __construct(
        public int $alertAfterMinutes,
        public int $recoveryChecks,
        public bool $inherited,
    ) {
        $this->preset = AlertSensitivity::fromTiming($alertAfterMinutes, $recoveryChecks);
    }

    public static function for(Site $site, InstanceSettings $settings): self
    {
        return new self(
            $site->alert_after_minutes ?? $settings->alert_after_minutes,
            $site->alert_recovery_checks ?? $settings->alert_recovery_checks,
            $site->alert_after_minutes === null,
        );
    }

    public static function team(InstanceSettings $settings): self
    {
        return new self($settings->alert_after_minutes, $settings->alert_recovery_checks, false);
    }
}
