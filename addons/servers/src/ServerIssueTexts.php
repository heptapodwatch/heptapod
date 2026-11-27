<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\Http\FetchException;
use Heptapod\Contracts\Http\TargetResolutionException;
use Heptapod\Contracts\Http\TargetResolver;
use Heptapod\Contracts\ServerIssueMessage;
use Heptapod\Contracts\ServerIssues;
use Heptapod\Contracts\ServersAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Server issue alerts as core delivers and shows them. Scoped to one request: an Activity page asks once per server
 * whether it still exists.
 */
final class ServerIssueTexts implements ServerIssues
{
    /** @var array<int, true>|null the ids of the servers that exist, read once */
    private ?array $servers = null;

    public function __construct(private readonly ServersAccess $access, private readonly TargetResolver $resolver) {}

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

    /**
     * Rule: a server is the instance's own host when the public addresses its agent reports include one the APP_URL host
     * resolves to. Only reporting servers count, so a host whose agent stopped leaves the disk alert to the system issue.
     * A failed lookup (or a CDN in front of the instance) finds no host, so both alerts are sent rather than none.
     */
    public function monitorsInstanceHost(): bool
    {
        $host = parse_url(config()->string('app.url'), PHP_URL_HOST);
        if (! is_string($host) || ! $this->access->enabled()) {
            return false;
        }
        $reported = [];
        $servers = DB::table('servers')->whereNotNull('ips')
            ->whereRaw("last_report_at >= ?::timestamptz - make_interval(mins => coalesce((settings->>'no_report_minutes')::int, ?))",
                [CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.uP'), config()->integer('servers.thresholds.no_report_minutes')])
            ->pluck('ips');
        foreach ($servers as $ips) {
            /** @var list<string> $list validated public addresses (ServerReportController) */
            $list = is_string($ips) ? json_decode($ips, true, flags: JSON_THROW_ON_ERROR) : [];
            foreach ($list as $ip) {
                $reported[] = inet_pton($ip);
            }
        }
        if ($reported === []) {
            return false;
        }
        try {
            $addresses = $this->resolver->resolve(trim($host, '[]'), microtime(true) + 3);
        } catch (FetchException|TargetResolutionException $exception) {
            Log::warning('The instance host could not be resolved to match it with a server.', ['host' => $host, 'error' => $exception->getMessage()]);

            return false;
        }

        return array_intersect(array_map(inet_pton(...), $addresses), $reported) !== [];
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
