<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        foreach (['sender_name', 'accent', 'footer', 'logo_path', 'logo_width', 'logo_height', 'logo_dark_path', 'logo_dark_width', 'logo_dark_height'] as $key) {
            $this->migrator->add('instance.mail_'.$key);
        }
    }
};
