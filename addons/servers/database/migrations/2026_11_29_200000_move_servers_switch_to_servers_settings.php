<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

// The Owner's switch moves out of the instance settings into the addon's own group; rename keeps its value. The rename
// commits before Laravel records the migration, so a rerun after a crash in between finds it already done.
return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('servers.enabled')) {
            $this->migrator->rename('instance.servers_enabled', 'servers.enabled');
        }
    }

    public function down(): void
    {
        if (! $this->migrator->exists('instance.servers_enabled')) {
            $this->migrator->rename('servers.enabled', 'instance.servers_enabled');
        }
    }
};
