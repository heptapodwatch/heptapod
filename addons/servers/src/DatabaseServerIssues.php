<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\ServerIssueMessage;
use Heptapod\Contracts\ServerIssues;
use Illuminate\Support\Facades\DB;

/**
 * Server issues as core delivers and shows them. Scoped to one request: an Activity page asks once per server whether it
 * still exists.
 */
final class DatabaseServerIssues implements ServerIssues
{
    /** @var array<int, true>|null the ids of the servers that exist, read once */
    private ?array $servers = null;

    public function message(array $facts, string $serverName, bool $recovered, string $locale, string $timezone): ServerIssueMessage
    {
        $kind = ServerIssueKind::from($this->text($facts, 'kind'));
        $scope = $this->text($facts, 'scope');
        $resolution = $recovered ? $this->text($facts, 'resolution') : null;
        $back = $resolution === 'recovered';
        $percent = static fn (string $key): string => self::number($facts, $key, $locale, \NumberFormatter::PERCENT);
        $params = match ($kind) {
            ServerIssueKind::DiskFull, ServerIssueKind::MemoryLow => ['mount' => $scope, 'value' => $percent('value'), 'threshold' => $percent('threshold'),
                'minutes' => self::number($facts, 'minutes', $locale), 'resolved_value' => $back ? $percent('resolved_value') : ''],
            ServerIssueKind::LoadHigh => ['value' => self::number($facts, 'value', $locale), 'threshold' => self::number($facts, 'threshold', $locale),
                'per_core' => self::number($facts, 'per_core', $locale), 'cores' => self::number($facts, 'cores', $locale),
                'minutes' => self::number($facts, 'minutes', $locale), 'resolved_value' => $back ? self::number($facts, 'resolved_value', $locale) : ''],
            ServerIssueKind::NoReport => ['time' => $this->time($facts, 'last_report_at', $timezone), 'minutes' => self::number($facts, 'minutes', $locale)],
        };
        $name = __('servers.issue.'.$kind->value, locale: $locale).($scope === '' ? '' : ' ('.$scope.')');
        $lead = match ($resolution) {
            null => __('servers.alert.'.$kind->value.'.lead', $params, $locale),
            'recovered' => __('servers.alert.'.$kind->value.'.recovered', $params, $locale),
            default => __('servers.alert.ended.'.$resolution, ['mount' => $scope], $locale),
        };
        $summary = match ($resolution) {
            null => __('servers.alert.'.$kind->value.'.summary', $params, $locale),
            'recovered' => __('servers.alert.resolved', ['name' => $name], $locale),
            default => __('servers.alert.ended', ['name' => $name, 'reason' => $lead], $locale),
        };
        $rows = [['label' => __('servers.alert.fact.server', locale: $locale), 'value' => $serverName]];
        if ($scope !== '') {
            $rows[] = ['label' => __('servers.alert.fact.mount', locale: $locale), 'value' => $scope];
        }
        $rows[] = match ($kind) {
            ServerIssueKind::NoReport => ['label' => __('servers.alert.fact.last_report', locale: $locale), 'value' => $params['time']],
            ServerIssueKind::LoadHigh => ['label' => __('servers.alert.fact.alert_at', locale: $locale),
                'value' => __('servers.alert.load_limit', $params, $locale)],
            default => ['label' => __('servers.alert.fact.alert_at', locale: $locale), 'value' => __('servers.alert.limit', $params, $locale)],
        };
        $rows[] = ['label' => __('servers.alert.fact.since', locale: $locale), 'value' => $this->time($facts, 'opened_at', $timezone)];
        if ($recovered) {
            $rows[] = ['label' => __('servers.alert.fact.resolved', locale: $locale), 'value' => $this->time($facts, 'resolved_at', $timezone)];
        }
        $serverId = $facts['server_id'] ?? null;
        if (! is_int($serverId)) {
            throw new \LogicException('A server issue event needs its server id.');
        }

        return new ServerIssueMessage(__('servers.alert.'.$kind->value.'.title', ['server' => $serverName], $locale), $summary, $lead, $rows,
            $this->exists($serverId) ? route('servers.show', $serverId) : null);
    }

    public function snoozedUntil(int $serverId): ?CarbonImmutable
    {
        $until = DB::table('servers')->where('id', $serverId)->value('snoozed_until');

        return is_string($until) && CarbonImmutable::parse($until)->isFuture() ? CarbonImmutable::parse($until) : null;
    }

    /** Bounded by the servers table, which holds one row per monitored server. */
    private function exists(int $serverId): bool
    {
        if ($this->servers === null) {
            /** @var list<int> $ids */
            $ids = DB::table('servers')->pluck('id')->all();
            $this->servers = array_fill_keys($ids, true);
        }

        return isset($this->servers[$serverId]);
    }

    /** @param array<string, mixed> $facts */
    private function text(array $facts, string $key): string
    {
        $value = $facts[$key] ?? null;

        return is_string($value) ? $value : throw new \LogicException('A server issue fact must be text: '.$key);
    }

    /** @param array<string, mixed> $facts */
    private static function number(array $facts, string $key, string $locale, int $style = \NumberFormatter::DECIMAL): string
    {
        $value = $facts[$key] ?? null;
        if (! is_int($value) && ! is_float($value)) {
            throw new \LogicException('A server issue fact must be a number: '.$key);
        }
        $formatter = new \NumberFormatter($locale, $style);
        $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $style === \NumberFormatter::PERCENT ? 0 : 2);
        $text = $formatter->format($style === \NumberFormatter::PERCENT ? $value / 100 : $value);

        return is_string($text) ? $text : throw new \RuntimeException('Cannot format a server issue number.');
    }

    /** @param array<string, mixed> $facts */
    private function time(array $facts, string $key, string $timezone): string
    {
        return CarbonImmutable::parse($this->text($facts, $key))->setTimezone($timezone)->format('Y-m-d H:i T');
    }
}
