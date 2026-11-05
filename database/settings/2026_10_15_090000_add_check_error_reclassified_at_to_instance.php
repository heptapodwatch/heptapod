<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('instance.check_error_reclassified_at', now()->toIso8601ZuluString('microsecond'));
    }
};
