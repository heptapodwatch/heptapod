<?php

declare(strict_types=1);

namespace Heptapod\Support;

use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\Notifications\ChannelRegistry;
use Heptapod\Contracts\Schema\ValidatedConfig;
use Heptapod\Data\Import\ImportChannelRowData;
use Heptapod\Enums\ImportOperation;
use Heptapod\Enums\NotificationCategory as Category;
use Heptapod\Exceptions\InvalidOhDearResponse;
use Illuminate\Validation\ValidationException;

final readonly class OhDearDestinationMapper
{
    private const array Kinds = ['mail' => 'mail', 'slackApi' => 'slack', 'microsoftTeamsWorkflow' => 'microsoftTeams', 'pushover' => 'pushover',
        'ntfy' => 'ntfy', 'slack' => 'slackWebhook', 'discord' => 'discordWebhook', 'googleChat' => 'googleChat', 'webhook' => 'webhook'];

    private const array Unsupported = ['telegram', 'microsoftTeams', 'zoom', 'pagerDuty', 'opsgenie', 'sms'];

    private const array Types = [
        'HttpUptimeCheckFailed' => [CheckKey::Uptime, Category::Outage], 'HttpUptimeCheckRecovered' => [CheckKey::Uptime, Category::Recovery],
        'TcpUptimeCheckFailed' => [CheckKey::Uptime, Category::Outage], 'TcpUptimeCheckRecovered' => [CheckKey::Uptime, Category::Recovery],
        'PingUptimeCheckFailed' => [CheckKey::Uptime, Category::Outage], 'PingUptimeCheckRecovered' => [CheckKey::Uptime, Category::Recovery],
        'CertificateUnhealthy' => [CheckKey::CertificateHealth, Category::Failure], 'CertificateFixed' => [CheckKey::CertificateHealth, Category::Recovery],
        'CertificateExpiresSoon' => [CheckKey::CertificateHealth, Category::Expiring], 'CertificateHasChanged' => [CheckKey::CertificateHealth, Category::Changes],
        'BrokenLinksFound' => [CheckKey::BrokenLinks, Category::Failure], 'BrokenLinksFixed' => [CheckKey::BrokenLinks, Category::Recovery],
        'MixedContentFound' => [CheckKey::MixedContent, Category::Failure], 'MixedContentFixed' => [CheckKey::MixedContent, Category::Recovery],
        'PerformanceThresholdExceeded' => [CheckKey::Performance, Category::Failure], 'PerformanceThresholdRecovered' => [CheckKey::Performance, Category::Recovery],
        'PerformanceDeltaExceeded' => [CheckKey::Performance, Category::Failure],
        'LighthouseIssuesDetected' => [CheckKey::Lighthouse, Category::Failure], 'LighthouseIssuesFixed' => [CheckKey::Lighthouse, Category::Recovery],
        'CronNotExecutedOnTime' => [CheckKey::Cron, Category::Failure], 'CronFailed' => [CheckKey::Cron, Category::Failure],
        'SitemapIssuesFound' => [CheckKey::Sitemap, Category::Failure], 'SitemapIssuesFixed' => [CheckKey::Sitemap, Category::Recovery],
        'DnsIssuesFound' => [CheckKey::Dns, Category::Failure], 'DnsIssuesFixed' => [CheckKey::Dns, Category::Recovery], 'DnsRecordsChanged' => [CheckKey::Dns, Category::Changes],
        'ApplicationHealthClientError' => [CheckKey::ApplicationHealth, Category::Failure], 'ApplicationHealthResultsTooOld' => [CheckKey::ApplicationHealth, Category::Failure],
        'ApplicationHealthProblemDetected' => [CheckKey::ApplicationHealth, Category::Failure], 'ApplicationHealthProblemFixed' => [CheckKey::ApplicationHealth, Category::Recovery],
        'DomainIssuesFound' => [CheckKey::Domain, Category::Failure], 'DomainIssuesFixed' => [CheckKey::Domain, Category::Recovery],
    ];

    public function __construct(private ChannelRegistry $channels) {}

    /**
     * @param  array<string, mixed>  $source
     * @param  list<string>  $tags
     * @return array{ImportChannelRowData, string|null, ValidatedConfig|null}
     */
    public function map(#[\SensitiveParameter] array $source, string $level, array $tags): array
    {
        $id = $source['id'] ?? null;
        $kind = $source['channel'] ?? null;
        $enabled = $source['enabled'] ?? null;
        if (! is_int($id) || $id < 1 || ! is_string($kind) || ! is_bool($enabled)) {
            throw new InvalidOhDearResponse('import.channels.invalid_response');
        }
        $key = self::Kinds[$kind] ?? null;
        $channel = $key === null ? null : ($this->channels->all()[$key] ?? null);
        $label = $key !== null ? 'notifications.channel.'.$key
            : (in_array($kind, self::Unsupported, true) ? 'import.channels.kind.'.$kind : 'import.channels.unknown_kind');
        $skip = static fn (string $notice): array => [new ImportChannelRowData($id, $label, $level, $tags, ImportOperation::Skipped, $enabled,
            notices: [OhDearImportNotices::key('channel:'.$notice)]), null, null];
        if ($level === 'monitor') {
            return $skip('monitor');
        }
        if ($channel === null) {
            return $skip($key === null ? 'unsupported' : 'unavailable');
        }
        $destination = $source['destination'] ?? null;
        $types = $source['notification_types'] ?? null;
        if (! is_array($destination) || ! is_array($types) || ! array_is_list($types)) {
            return $skip('invalid');
        }
        $pairs = [];
        $checks = [];
        $categories = [];
        $unmapped = false;
        foreach ($types as $type) {
            $pair = is_string($type) && str_ends_with($type, 'Notification') ? (self::Types[substr($type, 0, -strlen('Notification'))] ?? null) : null;
            if ($pair === null) {
                $unmapped = true;

                continue;
            }
            [$check, $category] = $pair;
            $pairs[$check->value][$category->value] = true;
            $checks[$check->value] = $check;
            $categories[$category->value] = $category;
        }
        if ($pairs === []) {
            return $skip('no_types');
        }
        try {
            $config = $channel->configSchema()->validateConfig($this->config($key, $destination));
        } catch (ValidationException) {
            // Provider validation can echo credentials, including values in nested header paths.
            return $skip('invalid');
        }
        $notices = [];
        if ($key === 'webhook') {
            $notices[] = OhDearImportNotices::key('channel:webhook');
        }
        if ($unmapped) {
            $notices[] = OhDearImportNotices::key('channel:some_types');
        }
        foreach ($pairs as $check => $selected) {
            $additional = array_diff(array_keys($categories), array_keys($selected));
            $impossible = $check === CheckKey::Uptime->value ? ['failure', 'changes', 'expiring'] : ['outage'];
            if (! in_array($check, [CheckKey::Domain->value, CheckKey::CertificateHealth->value], true)) {
                $impossible[] = 'expiring';
            }
            if (array_diff($additional, $impossible) !== []) {
                $notices[] = OhDearImportNotices::key('channel:broader');
                break;
            }
        }

        return [new ImportChannelRowData($id, $label, $level, $tags, ImportOperation::Create, $enabled,
            array_values($categories), array_values($checks), $notices), $key, $config];
    }

    /** @param array<mixed> $source
     * @return array<string, mixed>
     */
    private function config(string $kind, #[\SensitiveParameter] array $source): array
    {
        return match ($kind) {
            'mail' => ['email' => $source['mail'] ?? null],
            'slack' => ['channel' => $source['channel'] ?? null, 'token' => $source['slack_api_token'] ?? null],
            'pushover' => ['user_key' => $source['userKey'] ?? null, 'token' => $source['apiToken'] ?? null],
            'ntfy' => ['server' => $source['serverUrl'] ?? null, 'topic' => $source['topic'] ?? null, 'token' => $source['accessToken'] ?? null],
            'webhook' => ['url' => $source['url'] ?? null, 'headers' => $source['headers'] ?? [], 'secret' => 'replaced-before-saving'],
            default => ['url' => $source['url'] ?? null],
        };
    }
}
