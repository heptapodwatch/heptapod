<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // The Relaxed timing of this release, fixed so that 2026_11_14_090000 maps it to the current Relaxed preset.
        foreach (['alert_after_minutes' => 10, 'alert_recovery_checks' => 3] as $key => $value) {
            if (! $this->migrator->exists('instance.'.$key)) {
                $this->migrator->add('instance.'.$key, $value);
            }
        }
    }

    public function down(): void {}
};
