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
use Throwable;

/**
 * Coordinates the monitor outage guard from one short-lived cached probe verdict and atomic Redis burst windows.
 *
 * Uptime checks never run probes and never wait: during a burst they read the cached verdict, trigger a background
 * refresh-ahead once the verdict passes its soft age while continuing to use it until its hard TTL, or dispatch
 * RunControlProbe and record normally on a miss. Guard cache/queue failures are reported without altering the check result.
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

    /** Soft age after which a continuing burst dispatches a refresh-ahead probe while still using the cached verdict. */
    public const int VerdictSoftAgeSeconds = 60;

    /** Hard TTL after which an unrefreshed verdict expires and stops guarding or keeping an issue open. */
    public const int VerdictHardTtlSeconds = 180;

    /** Atomic burst update: trim expired members, record this check with TTL, and return the active burst count. */
    private const string RecordBurstScript = <<<'LUA'
        redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', tonumber(ARGV[2]) - 1)
        redis.call('ZADD', KEYS[1], ARGV[1], ARGV[3])
        redis.call('EXPIRE', KEYS[1], ARGV[4])
        if #KEYS > 1 then
            redis.call('ZREMRANGEBYSCORE', KEYS[2], '-inf', tonumber(ARGV[2]) - 1)
            redis.call('ZADD', KEYS[2], ARGV[1], ARGV[3])
            redis.call('EXPIRE', KEYS[2], ARGV[4])
        end
        return redis.call('ZCOUNT', KEYS[1], ARGV[2], '+inf')
        LUA;

    public function evaluateFailure(int $checkId, FetchFailureKind $kind, CarbonImmutable $now): ?string
    {
        if (! in_array($kind, [FetchFailureKind::Dns, FetchFailureKind::Connect, FetchFailureKind::Timeout], true)) {
            return null;
        }
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $burstCount = $this->recordBurstFailure($checkId, $kind, $now);
            if ($burstCount < self::BurstMinChecks) {
                return null;
            }

            $verdict = $this->readVerdict($now);
            if ($verdict === null) {
                $this->dispatchProbe();

                return null;
            }
            if ($verdict['ageSeconds'] >= self::VerdictSoftAgeSeconds) {
                $this->dispatchProbe();
            }

            $result = $verdict['result'];

            return $result->covers($kind) ? $result->mode() : null;
        } catch (Throwable $exception) {
            // A cache or queue failure inside the guard must never replace the site's real uptime outcome.
            report($exception);

            return null;
        }
    }

    /** Called only by RunControlProbe with a complete control probe batch. */
    public function recordVerdict(ControlProbeResult $result, CarbonImmutable $probedAt): void
    {
        if (! $result->complete) {
            return;
        }
        Cache::store('redis')->put(self::VerdictKey, [
            'dns_passed' => $result->dnsPassed,
            'dns_total' => $result->dnsTotal,
            'ip_passed' => $result->ipPassed,
            'ip_total' => $result->ipTotal,
            'probed_at' => $probedAt->utc()->toIso8601String(),
        ], self::VerdictHardTtlSeconds);
    }

    /**
     * Current monitor outage details for EvaluateSystemIssues, or null when healthy or expired.
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

        $verdict = $this->readVerdict($now);
        if ($verdict === null) {
            return null;
        }

        if ($openDetails !== null && $verdict['ageSeconds'] >= self::VerdictSoftAgeSeconds) {
            $this->dispatchProbe();
        }

        $result = $verdict['result'];
        $mode = $result->mode();
        if ($mode === null) {
            return null;
        }

        $affectedSites = $this->burstCount($mode === 'dns' ? self::DnsBurstKey : self::BurstKey, $now);
        if ($openDetails === null && $affectedSites === 0) {
            return null;
        }

        return [
            'mode' => $mode,
            'affected_sites' => $affectedSites,
            'dns_probes' => $result->dnsPassed.' / '.$result->dnsTotal,
            'ip_probes' => $result->ipPassed.' / '.$result->ipTotal,
        ];
    }

    public function clear(): void
    {
        Cache::store('redis')->forget(self::VerdictKey);
        Redis::connection('cache')->command('del', [self::BurstKey, self::DnsBurstKey]);
    }

    private function dispatchProbe(): void
    {
        try {
            RunControlProbe::dispatch();
        } catch (Throwable $exception) {
            report($exception);
        }
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
        $ttl = self::WindowSeconds * 2;
        $connection = Redis::connection('cache');

        // PhpRedisConnection applies OPT_PREFIX to Lua KEYS automatically, matching zcount and del.
        $keys = $kind === FetchFailureKind::Dns
            ? [self::BurstKey, self::DnsBurstKey]
            : [self::BurstKey];
        $count = $connection->eval(self::RecordBurstScript, count($keys), ...[...$keys, $ts, $cutoff, (string) $checkId, $ttl]);

        return is_numeric($count) ? (int) $count : 0;
    }

    private function burstCount(string $key, CarbonImmutable $now): int
    {
        $cutoff = $now->getTimestamp() - self::WindowSeconds;
        $count = Redis::connection('cache')->command('zcount', [$key, (string) $cutoff, '+inf']);

        return is_numeric($count) ? (int) $count : 0;
    }

    /** @return array{result: ControlProbeResult, ageSeconds: int}|null */
    private function readVerdict(CarbonImmutable $now): ?array
    {
        $value = Cache::store('redis')->get(self::VerdictKey);
        if (! is_array($value) || ! is_string($value['probed_at'] ?? null)) {
            return null;
        }
        if (! is_int($value['dns_passed'] ?? null) || ! is_int($value['dns_total'] ?? null)
            || ! is_int($value['ip_passed'] ?? null) || ! is_int($value['ip_total'] ?? null)) {
            return null;
        }

        // Positive age measures elapsed time since publication; a verdict published slightly ahead of $now stays fresh (age 0).
        $age = max(0, (int) CarbonImmutable::parse($value['probed_at'])->diffInSeconds($now, false));
        if ($age >= self::VerdictHardTtlSeconds) {
            return null;
        }

        return [
            'result' => new ControlProbeResult($value['dns_passed'], $value['dns_total'], $value['ip_passed'], $value['ip_total']),
            'ageSeconds' => $age,
        ];
    }
}
