<?php

declare(strict_types=1);

namespace Heptapod\SystemHealth;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\ControlProbeResult;
use Heptapod\Contracts\Http\FetchFailureKind;
use Heptapod\Contracts\MonitorOutageGuard;
use Heptapod\Jobs\RunControlProbe;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

/**
 * Coordinates the monitor outage guard from one short-lived cached probe verdict and lock-free Redis burst windows.
 *
 * Uptime checks never run probes and never wait: during a burst they read the cached verdict, reclassify matching
 * failures when a fresh failing verdict exists, or dispatch RunControlProbe and record normally when none exists.
 * EvaluateSystemIssues opens and resolves monitor_outage from the same cached verdict.
 */
final readonly class MonitorOutageDetector implements MonitorOutageGuard
{
    public const string VerdictKey = 'heptapod:monitor-outage:verdict';

    public const string BurstKey = 'heptapod:monitor-outage:burst';

    public const string DnsBurstKey = 'heptapod:monitor-outage:burst:dns';

    /** Minimum distinct active uptime checks with network-level failures in WindowSeconds before acting. */
    public const int BurstMinChecks = 3;

    /** Covers two standard 60-second uptime intervals so concurrent failures in one wave overlap. */
    public const int WindowSeconds = 120;

    /** Short TTL for the single cached verdict published by RunControlProbe. */
    public const int VerdictTtlSeconds = 120;

    public function evaluateFailure(int $checkId, FetchFailureKind $kind, CarbonImmutable $now): ?string
    {
        if (! in_array($kind, [FetchFailureKind::Dns, FetchFailureKind::Connect, FetchFailureKind::Timeout], true)) {
            return null;
        }
        if (! $this->isConfigured()) {
            return null;
        }

        $burstCount = $this->recordBurstFailure($checkId, $kind, $now);
        if ($burstCount < self::BurstMinChecks) {
            return null;
        }

        $verdict = $this->freshVerdict($now);
        if ($verdict === null) {
            RunControlProbe::dispatch();

            return null;
        }
        if ($verdict['status'] !== 'failing') {
            return null;
        }

        $result = new ControlProbeResult($verdict['dns_passed'], $verdict['dns_total'], $verdict['ip_passed'], $verdict['ip_total']);

        return $result->covers($kind) ? $verdict['mode'] : null;
    }

    /** Called only by RunControlProbe with a complete control probe batch. */
    public function recordVerdict(ControlProbeResult $result, CarbonImmutable $probedAt): void
    {
        if (! $result->complete) {
            return;
        }
        $mode = $result->mode();
        Cache::store('redis')->put(self::VerdictKey, [
            'status' => $mode === null ? 'healthy' : 'failing',
            'mode' => $mode,
            'dns_passed' => $result->dnsPassed,
            'dns_total' => $result->dnsTotal,
            'ip_passed' => $result->ipPassed,
            'ip_total' => $result->ipTotal,
            'probed_at' => $probedAt->utc()->toIso8601String(),
        ], self::VerdictTtlSeconds);
    }

    /**
     * Current monitor outage details for EvaluateSystemIssues, or null when healthy.
     * Reads only the cached verdict published by RunControlProbe (no separate probing path).
     *
     * @param  array<string, int|string|null>|null  $openDetails
     * @return array<string, int|string|null>|null
     */
    public function evaluateSystemIssue(CarbonImmutable $now, ?array $openDetails = null): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $verdict = $this->freshVerdict($now);
        if ($verdict === null) {
            if ($openDetails !== null && $this->burstCount(self::BurstKey, $now) >= self::BurstMinChecks) {
                // Keep open across a brief TTL refresh while the burst is still active; resolve once healthy or quiet.
                RunControlProbe::dispatch();

                return $openDetails;
            }

            return null;
        }

        if ($verdict['status'] !== 'failing' || $verdict['mode'] === null) {
            return null;
        }

        $affectedSites = $this->burstCount($verdict['mode'] === 'dns' ? self::DnsBurstKey : self::BurstKey, $now);
        if ($openDetails === null && $affectedSites === 0) {
            return null;
        }

        return [
            'mode' => $verdict['mode'],
            'affected_sites' => $affectedSites,
            'dns_probes' => $verdict['dns_passed'].' / '.$verdict['dns_total'],
            'ip_probes' => $verdict['ip_passed'].' / '.$verdict['ip_total'],
        ];
    }

    public function clear(): void
    {
        Cache::store('redis')->forget(self::VerdictKey);
        Redis::connection('cache')->command('del', [self::BurstKey, self::DnsBurstKey]);
    }

    private function isConfigured(): bool
    {
        return config()->array('heptapod.control_probe.dns_hosts', []) !== []
            || config()->array('heptapod.control_probe.ip_targets', []) !== [];
    }

    private function recordBurstFailure(int $checkId, FetchFailureKind $kind, CarbonImmutable $now): int
    {
        $ts = $now->getTimestamp();
        $cutoff = $ts - self::WindowSeconds;
        $redis = Redis::connection('cache');
        $redis->command('zremrangebyscore', [self::BurstKey, '-inf', (string) ($cutoff - 1)]);
        $redis->command('zadd', [self::BurstKey, $ts, (string) $checkId]);
        $redis->command('expire', [self::BurstKey, self::WindowSeconds * 2]);

        if ($kind === FetchFailureKind::Dns) {
            $redis->command('zremrangebyscore', [self::DnsBurstKey, '-inf', (string) ($cutoff - 1)]);
            $redis->command('zadd', [self::DnsBurstKey, $ts, (string) $checkId]);
            $redis->command('expire', [self::DnsBurstKey, self::WindowSeconds * 2]);
        }

        $count = $redis->command('zcount', [self::BurstKey, (string) $cutoff, '+inf']);

        return is_numeric($count) ? (int) $count : 0;
    }

    private function burstCount(string $key, CarbonImmutable $now): int
    {
        $cutoff = $now->getTimestamp() - self::WindowSeconds;
        $count = Redis::connection('cache')->command('zcount', [$key, (string) $cutoff, '+inf']);

        return is_numeric($count) ? (int) $count : 0;
    }

    /** @return array{status: 'failing'|'healthy', mode: 'dns'|'network'|null, dns_passed: int, dns_total: int, ip_passed: int, ip_total: int, probed_at: string}|null */
    private function freshVerdict(CarbonImmutable $now): ?array
    {
        $value = Cache::store('redis')->get(self::VerdictKey);
        if (! is_array($value) || ! is_string($value['status'] ?? null) || ! is_string($value['probed_at'] ?? null)) {
            return null;
        }
        if (! in_array($value['status'], ['failing', 'healthy'], true)) {
            return null;
        }
        $probedAt = CarbonImmutable::parse($value['probed_at']);
        if ($probedAt->greaterThan($now) || $probedAt->diffInSeconds($now) >= self::VerdictTtlSeconds) {
            return null;
        }
        if (! is_int($value['dns_passed'] ?? null) || ! is_int($value['dns_total'] ?? null)
            || ! is_int($value['ip_passed'] ?? null) || ! is_int($value['ip_total'] ?? null)) {
            return null;
        }
        $mode = $value['mode'] ?? null;
        if ($mode !== null && ! in_array($mode, ['dns', 'network'], true)) {
            return null;
        }

        /** @var array{status: 'failing'|'healthy', mode: 'dns'|'network'|null, dns_passed: int, dns_total: int, ip_passed: int, ip_total: int, probed_at: string} $verdict */
        $verdict = [
            'status' => $value['status'],
            'mode' => $mode,
            'dns_passed' => $value['dns_passed'],
            'dns_total' => $value['dns_total'],
            'ip_passed' => $value['ip_passed'],
            'ip_total' => $value['ip_total'],
            'probed_at' => $value['probed_at'],
        ];

        return $verdict;
    }
}
