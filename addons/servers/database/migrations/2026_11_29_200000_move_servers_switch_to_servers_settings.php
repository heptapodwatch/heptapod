<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

// The Owner's switch moves out of the instance settings into the addon's own group; rename keeps its value.
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->rename('instance.servers_enabled', 'servers.enabled');
    }

    public function down(): void
    {
        $this->migrator->rename('servers.enabled', 'instance.servers_enabled');
    }
};
