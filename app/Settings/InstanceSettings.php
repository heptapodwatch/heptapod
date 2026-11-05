<?php

declare(strict_types=1);

namespace Heptapod\Settings;

use Heptapod\Contracts\Mail\MailDesign;
use Spatie\LaravelSettings\Settings;

final class InstanceSettings extends Settings
{
    public string $name;

    public string $timezone;

    public string $installed_at;

    public bool $updates_enabled;

    public int $alert_after_minutes;

    public int $alert_recovery_checks;

    /** When this instance started classifying ReclassifiedCheckError outcomes as Check errors. */
    public string $check_error_reclassified_at;

    // The layout of alert, system and client mail; account mail is always Branded, client messages choose per send.
    // A docblock here would replace the typed-property cast discovery of spatie/laravel-settings.
    public MailDesign $mail_design;

    public static function group(): string
    {
        return 'instance';
    }
}
