<?php

declare(strict_types=1);

namespace Heptapod\Support;

use Heptapod\Exceptions\UndeclaredImportNotice;

/**
 * Declared mapping from import engine reason codes to user-facing lang keys (lang/{en,de}/import.json).
 * scripts/check-import-notices.php proves that every code the pinned Oh Dear spec can produce is declared
 * and translated. An undeclared code fails loud outside production; production reports it and shows a generic label.
 */
final class OhDearImportNotices
{
    public const array Keys = [
        'site:exists' => 'import.notice.site_exists',
        'plan:missing' => 'import.notice.plan_missing',
        'plan:changed' => 'import.notice.plan_changed',
        'monitor:unsupported' => 'import.notice.monitor_unsupported',
        'report' => 'import.notice.report',
        'domain:unsupported' => 'import.notice.domain_unsupported',
        'cron:disabled_without_definitions' => 'import.notice.cron_disabled_without_definitions',
        'setup:cron' => 'import.notice.setup.cron',
        'setup:application_health' => 'import.notice.setup.application_health',
        'setting:uptime.location' => 'import.notice.uptime_location',
        'setting:uptime.acceptable_average_response_time_in_ms' => 'import.notice.uptime_ping',
        'setting:uptime.acceptable_packet_loss_percentage' => 'import.notice.uptime_ping',
        'setting:uptime.count' => 'import.notice.uptime_ping',
        'setting:uptime.interval_in_seconds' => 'import.notice.uptime_ping',
        'setting:uptime.packet_size_in_bytes' => 'import.notice.uptime_ping',
        'setting:uptime.ttl' => 'import.notice.uptime_ping',
        'setting:uptime.look_for_string_in_send_string_response' => 'import.notice.uptime_tcp',
        'setting:uptime.look_for_string_in_welcome_message' => 'import.notice.uptime_tcp',
        'setting:uptime.tcp_send_string' => 'import.notice.uptime_tcp',
        'setting:uptime.timeout_in_ms' => 'import.notice.uptime_timeout',
        'setting:uptime.timeout_in_seconds' => 'import.notice.uptime_timeout',
        'setting:broken_links.crawler_speed' => 'import.notice.broken_links_crawler_speed',
        'setting:broken_links.whitelisted_urls' => 'import.notice.broken_links_whitelist',
        'check:uptime' => 'import.notice.unavailable.uptime',
        'check:performance' => 'import.notice.unavailable.performance',
        'check:certificate_health' => 'import.notice.unavailable.certificate_health',
        'check:broken_links' => 'import.notice.unavailable.broken_links',
        'check:mixed_content' => 'import.notice.unavailable.mixed_content',
        'check:lighthouse' => 'import.notice.unavailable.lighthouse',
        'check:cron' => 'import.notice.unavailable.cron',
        'check:application_health' => 'import.notice.unavailable.application_health',
        'check:sitemap' => 'import.notice.unavailable.sitemap',
        'check:dns' => 'import.notice.unavailable.dns',
        'check:domain' => 'import.notice.unavailable.domain',
        'check:ai' => 'import.notice.unavailable.ai',
        'check:ports' => 'import.notice.unavailable.ports',
        'check:dns_blocklist' => 'import.notice.unavailable.dns_blocklist',
        'invalid:uptime' => 'import.notice.invalid.uptime',
        'invalid:performance' => 'import.notice.invalid.performance',
        'invalid:certificate_health' => 'import.notice.invalid.certificate_health',
        'invalid:broken_links' => 'import.notice.invalid.broken_links',
        'invalid:lighthouse' => 'import.notice.invalid.lighthouse',
        'invalid:cron' => 'import.notice.invalid.cron',
        'invalid:application_health' => 'import.notice.invalid.application_health',
        'invalid:sitemap' => 'import.notice.invalid.sitemap',
        'invalid:dns' => 'import.notice.invalid.dns',
        'invalid:domain' => 'import.notice.invalid.domain',
        'short_secret:application_health' => 'import.notice.short_secret.application_health',
        'short_secret:uptime' => 'import.notice.short_secret.uptime',
        'short_secret:lighthouse' => 'import.notice.short_secret.lighthouse',
    ];

    /** Source fields that Oh Dear derives itself; they are never user settings and never listed. */
    public const array Derived = ['domain_check_settings.not_supported_reason'];

    public const string Undeclared = 'import.notice.other';

    /**
     * Translates engine reasons into ordered, de-duplicated lang keys.
     *
     * @param  list<string>  $skipped  Engine skip paths: a check type, `<type>_check_settings`, `<type>_check_settings.<field>[.<index>]` or `include_check_types_in_report`.
     * @param  list<string>  $setupRequired  Check types missing required source settings.
     * @param  list<string>  $invalidTypes  Check types rejected for invalid settings.
     * @param  list<string>  $shortCredentials  Engine paths of secrets below the minimum length.
     * @return list<string>
     */
    public static function keys(array $skipped, array $setupRequired, bool $cronDisabledWithoutDefinitions, array $invalidTypes, array $shortCredentials, bool $domainUnsupported): array
    {
        $codes = [];
        foreach ($skipped as $path) {
            if (in_array($path, self::Derived, true)) {
                continue;
            }
            $codes[] = match (true) {
                $path === 'include_check_types_in_report' => 'report',
                str_ends_with($path, '_check_settings') => 'check:'.substr($path, 0, -strlen('_check_settings')),
                str_contains($path, '_check_settings.') => 'setting:'.implode('.', array_slice(explode('.', str_replace('_check_settings.', '.', $path)), 0, 2)),
                default => 'check:'.$path,
            };
        }
        foreach ($setupRequired as $type) {
            $codes[] = $type === 'cron' && $cronDisabledWithoutDefinitions ? 'cron:disabled_without_definitions' : 'setup:'.$type;
        }
        foreach ($invalidTypes as $type) {
            $codes[] = 'invalid:'.$type;
        }
        foreach ($shortCredentials as $path) {
            $codes[] = 'short_secret:'.explode('_check_settings.', $path)[0];
        }
        if ($domainUnsupported) {
            $codes[] = 'domain:unsupported';
        }

        return array_values(array_unique(array_map(self::key(...), $codes)));
    }

    public static function key(string $code): string
    {
        if (isset(self::Keys[$code])) {
            return self::Keys[$code];
        }
        $exception = new UndeclaredImportNotice('Undeclared Oh Dear import notice: '.$code);
        if (! app()->isProduction()) {
            throw $exception;
        }
        report($exception);

        return self::Undeclared;
    }
}
