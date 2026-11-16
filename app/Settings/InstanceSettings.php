<?php

declare(strict_types=1);

namespace Heptapod\Settings;

use Heptapod\Contracts\Mail\MailDesign;
use Heptapod\Enums\AlertReminders;
use Spatie\LaravelSettings\Settings;

final class InstanceSettings extends Settings
{
    public string $name;

    public string $timezone;

    public string $installed_at;

    public bool $updates_enabled;

    public int $alert_after_minutes;

    public int $alert_recovery_checks;

    public bool $alert_confirms_other_checks;

    public AlertReminders $alert_reminders;

    /** When this instance started classifying ReclassifiedCheckError outcomes as Check errors. */
    public string $check_error_reclassified_at;

    // The layout of alert, system and client mail; account mail is always Branded, client messages choose per send.
    // A docblock here would replace the typed-property cast discovery of spatie/laravel-settings.
    public MailDesign $mail_design;

    // Mail branding (Settings → Email); null keeps the Heptapod default. Logos are files on the local disk (PNG or JPEG by
    // extension) with the size they are rendered at; a dark-mode logo exists only with a logo.
    public ?string $mail_sender_name = null;

    public ?string $mail_accent = null;

    public ?string $mail_footer = null;

    public ?string $mail_logo_path = null;

    public ?int $mail_logo_width = null;

    public ?int $mail_logo_height = null;

    public ?string $mail_logo_dark_path = null;

    public ?int $mail_logo_dark_width = null;

    public ?int $mail_logo_dark_height = null;

    public static function group(): string
    {
        return 'instance';
    }
}
