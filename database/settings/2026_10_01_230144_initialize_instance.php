<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('instance.name', 'Heptapod');
        $this->migrator->add('instance.timezone', 'UTC');
        $this->migrator->add('instance.installed_at', now()->toIso8601String());
    }
};
