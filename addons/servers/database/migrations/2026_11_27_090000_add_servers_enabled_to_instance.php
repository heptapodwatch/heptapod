<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

// Moved from database/settings with its name, so installs that ran it keep it; 2026_11_29_200000 moves the key to `servers`.
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('instance.servers_enabled', false);
    }
};
