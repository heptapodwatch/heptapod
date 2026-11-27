<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Contracts\Mail\MailDesign;
use Heptapod\Settings\InstanceSettings;
use Heptapod\SystemHealth\ReadSystemHealth;
use Heptapod\SystemHealth\SystemIssueMailer;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class InstanceSettingsData extends Data
{
    /**
     * @param  array{host: string|null, port: int|null, scheme: string|null, username_configured: bool, password_configured: bool}  $smtp
     * @param  array{editable: bool, source: string}  $defaults
     * @param  list<string>  $timezones
     * @param  array{keep_all_backups_for_days: int, keep_daily_backups_for_days: int, keep_weekly_backups_for_weeks: int, keep_monthly_backups_for_months: int, keep_yearly_backups_for_years: int, delete_oldest_backups_when_using_more_megabytes_than: int|null}  $retention
     */
    public function __construct(
        public readonly string $name,
        public readonly MailDesign $mail_design,
        public readonly string $timezone,
        public readonly array $timezones,
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
        public readonly bool $servers_enabled,
        public readonly bool $servers_enabled_by_server,
    ) {}

    /** General and Email both show these facts; mail credentials only as configured or not. */
    public static function current(InstanceSettings $settings): self
    {
        /** @var array{host?: string|null, port?: int|null, scheme?: string|null, username?: string|null, password?: string|null} $smtp */
        $smtp = config()->array('mail.mailers.smtp');
        /** @var array{keep_all_backups_for_days: int, keep_daily_backups_for_days: int, keep_weekly_backups_for_weeks: int, keep_monthly_backups_for_months: int, keep_yearly_backups_for_years: int, delete_oldest_backups_when_using_more_megabytes_than: int|null} $retention */
        $retention = config()->array('backup.cleanup.default_strategy');

        return new self($settings->name, $settings->mail_design, $settings->timezone, \DateTimeZone::listIdentifiers(), config()->string('app.url'),
            ['host' => $smtp['host'] ?? null, 'port' => $smtp['port'] ?? null,
                'scheme' => $smtp['scheme'] ?? null, 'username_configured' => filled($smtp['username'] ?? null),
                'password_configured' => filled($smtp['password'] ?? null)],
            config()->string('mail.default'), ! SystemIssueMailer::canDeliver(), config()->string('mail.from.name'), config()->string('mail.from.address'),
            filled(config('instance.heartbeat_url')), ReadSystemHealth::lastExternalPing(), url('/up'),
            ['editable' => false, 'source' => 'check definitions'], false, $retention,
            $settings->servers_enabled, config()->boolean('heptapod.servers.enabled_by_server'));
    }
}
