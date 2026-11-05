<?php

declare(strict_types=1);

use Heptapod\Enums\AlertSensitivity;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        foreach (['alert_after_minutes' => AlertSensitivity::DEFAULT->alertAfterMinutes(), 'alert_recovery_checks' => AlertSensitivity::DEFAULT->recoveryChecks()] as $key => $value) {
            if (! $this->migrator->exists('instance.'.$key)) {
                $this->migrator->add('instance.'.$key, $value);
            }
        }
    }

    public function down(): void {}
};
