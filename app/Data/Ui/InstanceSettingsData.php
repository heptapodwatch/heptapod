<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Contracts\Mail\MailDesign;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class InstanceSettingsData extends Data
{
    /**
     * @param  array{host: string|null, port: int|null, scheme: string|null, username_configured: bool, password_configured: bool}  $smtp
     * @param  array{editable: bool, source: string}  $defaults
     * @param  array{keep_all_backups_for_days: int, keep_daily_backups_for_days: int, keep_weekly_backups_for_weeks: int, keep_monthly_backups_for_months: int, keep_yearly_backups_for_years: int, delete_oldest_backups_when_using_more_megabytes_than: int|null}  $retention
     */
    public function __construct(
        public readonly string $name,
        public readonly MailDesign $mail_design,
        public readonly string $url,
        public readonly array $smtp,
        public readonly string $mailer,
        public readonly bool $mail_log_only,
        public readonly string $from_name,
        public readonly string $from_address,
        public readonly bool $heartbeat_configured,
        public readonly ?string $last_external_ping,
        public readonly string $health_url,
        public readonly array $defaults,
        public readonly bool $retention_editable,
        public readonly array $retention,
    ) {}
}
