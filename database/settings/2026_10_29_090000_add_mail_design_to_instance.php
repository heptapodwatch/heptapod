<?php

declare(strict_types=1);

use Heptapod\Contracts\Mail\MailDesign;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Dated before 2026_10_30_000001_unify_notification_recipients, which loads InstanceSettings: schema and settings migrations
 * run in one sorted sequence, and a settings class with a property its store does not hold yet cannot load.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('instance.mail_design', MailDesign::Branded->value);
    }
};
