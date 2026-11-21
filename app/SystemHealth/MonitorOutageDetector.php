<?php

declare(strict_types=1);

namespace Heptapod\SystemHealth;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\ControlProbe;
use Heptapod\Contracts\ControlProbeResult;
use Heptapod\Contracts\Http\FetchFailureKind;
use Heptapod\Contracts\MonitorOutageGuard;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;

/**
 * Detects a monitor-side network or DNS outage during an uptime failure burst.
 *
 * A burst of at least three distinct uptime checks failing with network-level errors within two minutes
 * triggers the cached outside control probes. Only when both independent probes for that layer fail does
 * the guard reclassify affected runs as Check error and record the active outage for EvaluateSystemIssues.
 */
final readonly class MonitorOutageDetector implements MonitorOutageGuard
{
    public const string Key = 'heptapod:monitor-outage:state';

    /** Minimum distinct active uptime checks with network-level failures in WindowSeconds before probing. */
    public const int BurstMinChecks = 3;

    /** Covers two standard 60-second uptime intervals so concurrent failures in one wave overlap. */
    public const int WindowSeconds = 120;

    /** Bounded probe cache TTL so concurrent workers in a burst share one probe pair instead of hammering targets. */
    public const int ProbeCacheSeconds = 60;

    public function __construct(private ControlProbe $probe) {}

    public function evaluateFailure(int $checkId, FetchFailureKind $kind, CarbonImmutable $now): ?string
    {
        if (! in_array($kind, [FetchFailureKind::Dns, FetchFailureKind::Connect, FetchFailureKind::Timeout], true)) {
            return null;
        }
        if (! $this->isConfigured()) {
            return null;
        }

        $needsProbe = false;
        $verdict = $this->withLock(function (array $state) use ($checkId, $kind, $now, &$needsProbe): array {
            $state['failures'] = $this->pruneFailures($state['failures'], $now);
            $state['failures'][$checkId] = $now->utc()->toIso8601String();

            $burst = count($state['failures']) >= self::BurstMinChecks || $state['active'] !== null;
            if (! $burst) {
                return [$state, null];
            }

            $cached = $this->freshProbe($state['probe'], $now);
            if (! $cached instanceof ControlProbeResult) {
                $needsProbe = true;

                return [$state, null];
            }

            return $this->applyProbe($state, $cached, $checkId, $kind, $now);
        });

        if (! $needsProbe) {
            return $verdict;
        }

        // Run outside control probes without holding the Redis state lock.
        $result = $this->probe->probe();

        return $this->withLock(function (array $state) use ($result, $checkId, $kind, $now): array {
            $state['failures'] = $this->pruneFailures($state['failures'], $now);
            $state['failures'][$checkId] = $now->utc()->toIso8601String();
            $state['probe'] = [
                'probed_at' => $now->utc()->toIso8601String(),
                'dns_passed' => $result->dnsPassed,
                'dns_total' => $result->dnsTotal,
                'ip_passed' => $result->ipPassed,
                'ip_total' => $result->ipTotal,
            ];

            return $this->applyProbe($state, $result, $checkId, $kind, $now);
        });
    }

    /**
     * Current monitor outage details for EvaluateSystemIssues, or null when healthy.
     * When the system issue is already open, a fresh probe runs to confirm whether the outage continues or resolved.
     *
     * @return array<string, int|string|null>|null
     */
    public function evaluateSystemIssue(CarbonImmutable $now, bool $isOpen): ?array
    {
        if (! $this->isConfigured()) {
            if ($isOpen) {
                $this->clearActive();
            }

            return null;
        }

        $active = $this->stored()['active'];
        if (! $isOpen) {
            return $active === null ? null : $this->issueDetails($active);
        }

        $result = $this->probe->probe();
        $mode = $result->mode();
        if ($mode === null) {
            $this->clearActive();

            return null;
        }

        $updated = $this->withLock(function (array $state) use ($result, $mode, $now): array {
            $state['probe'] = [
                'probed_at' => $now->utc()->toIso8601String(),
                'dns_passed' => $result->dnsPassed,
                'dns_total' => $result->dnsTotal,
                'ip_passed' => $result->ipPassed,
                'ip_total' => $result->ipTotal,
            ];
            $affected = $state['active']['affected_checks'] ?? [];
            $state['active'] = [
                'mode' => $mode,
                'detected_at' => $state['active']['detected_at'] ?? $now->utc()->toIso8601String(),
                'affected_checks' => $affected,
                'dns_passed' => $result->dnsPassed,
                'dns_total' => $result->dnsTotal,
                'ip_passed' => $result->ipPassed,
                'ip_total' => $result->ipTotal,
            ];

            return [$state, $state['active']];
        });

        return $this->issueDetails($updated);
    }

    public function clearActive(): void
    {
        $this->withLock(static function (array $state): array {
            $state['active'] = null;
            $state['probe'] = null;

            return [$state, null];
        });
    }

    private function isConfigured(): bool
    {
        return config()->array('heptapod.control_probe.dns_hosts', []) !== []
            || config()->array('heptapod.control_probe.ip_targets', []) !== [];
    }

    /**
     * @param  array{failures: array<int, string>, probe: array{probed_at: string, dns_passed: int, dns_total: int, ip_passed: int, ip_total: int}|null, active: array{mode: 'dns'|'network', detected_at: string, affected_checks: list<int>, dns_passed: int, dns_total: int, ip_passed: int, ip_total: int}|null}  $state
     * @return array{0: array{failures: array<int, string>, probe: array{probed_at: string, dns_passed: int, dns_total: int, ip_passed: int, ip_total: int}|null, active: array{mode: 'dns'|'network', detected_at: string, affected_checks: list<int>, dns_passed: int, dns_total: int, ip_passed: int, ip_total: int}|null}, 1: 'dns'|'network'|null}
     */
    private function applyProbe(array $state, ControlProbeResult $result, int $checkId, FetchFailureKind $kind, CarbonImmutable $now): array
    {
        $mode = $result->mode();
        if ($mode === null) {
            $state['active'] = null;

            return [$state, null];
        }

        $affected = $state['active']['affected_checks'] ?? [];
        if ($result->covers($kind)) {
            $affected = array_values(array_unique([...$affected, ...array_keys($state['failures']), $checkId]));
            sort($affected);
        }

        if ($result->covers($kind) || $state['active'] !== null) {
            $state['active'] = [
                'mode' => $mode,
                'detected_at' => $state['active']['detected_at'] ?? $now->utc()->toIso8601String(),
                'affected_checks' => $affected,
                'dns_passed' => $result->dnsPassed,
                'dns_total' => $result->dnsTotal,
                'ip_passed' => $result->ipPassed,
                'ip_total' => $result->ipTotal,
            ];
        }

        return [$state, $result->covers($kind) ? $mode : null];
    }

    /**
     * @param  array<int, string>  $failures
     * @return array<int, string>
     */
    private function pruneFailures(array $failures, CarbonImmutable $now): array
    {
        $cutoff = $now->subSeconds(self::WindowSeconds);
        $kept = [];
        foreach ($failures as $id => $at) {
            if (CarbonImmutable::parse($at)->greaterThanOrEqualTo($cutoff)) {
                $kept[$id] = $at;
            }
        }

        return $kept;
    }

    /**
     * @param  array{probed_at: string, dns_passed: int, dns_total: int, ip_passed: int, ip_total: int}|null  $probe
     */
    private function freshProbe(?array $probe, CarbonImmutable $now): ?ControlProbeResult
    {
        if ($probe === null) {
            return null;
        }
        $probedAt = CarbonImmutable::parse($probe['probed_at']);
        if ($probedAt->greaterThan($now) || $probedAt->diffInSeconds($now) >= self::ProbeCacheSeconds) {
            return null;
        }

        return new ControlProbeResult($probe['dns_passed'], $probe['dns_total'], $probe['ip_passed'], $probe['ip_total']);
    }

    /**
     * @param  array{mode: 'dns'|'network', detected_at: string, affected_checks: list<int>, dns_passed: int, dns_total: int, ip_passed: int, ip_total: int}  $active
     * @return array<string, int|string|null>
     */
    private function issueDetails(array $active): array
    {
        return [
            'mode' => $active['mode'],
            'affected_sites' => count($active['affected_checks']),
            'dns_probes' => $active['dns_passed'].' / '.$active['dns_total'],
            'ip_probes' => $active['ip_passed'].' / '.$active['ip_total'],
        ];
    }

    /**
     * @template T
     *
     * @param  callable(array{failures: array<int, string>, probe: array{probed_at: string, dns_passed: int, dns_total: int, ip_passed: int, ip_total: int}|null, active: array{mode: 'dns'|'network', detected_at: string, affected_checks: list<int>, dns_passed: int, dns_total: int, ip_passed: int, ip_total: int}|null}): array{0: array{failures: array<int, string>, probe: array{probed_at: string, dns_passed: int, dns_total: int, ip_passed: int, ip_total: int}|null, active: array{mode: 'dns'|'network', detected_at: string, affected_checks: list<int>, dns_passed: int, dns_total: int, ip_passed: int, ip_total: int}|null}, 1: T}  $change
     * @return T
     */
    private function withLock(callable $change): mixed
    {
        $locks = Cache::store('redis')->getStore();
        if (! $locks instanceof LockProvider) {
            throw new \LogicException('The Redis cache store must provide locks.');
        }

        return $locks->lock(self::Key.':lock', 10)->block(5, function () use ($change): mixed {
            [$next, $result] = $change($this->stored());
            Cache::store('redis')->forever(self::Key, $next);

            return $result;
        });
    }

    /** @return array{failures: array<int, string>, probe: array{probed_at: string, dns_passed: int, dns_total: int, ip_passed: int, ip_total: int}|null, active: array{mode: 'dns'|'network', detected_at: string, affected_checks: list<int>, dns_passed: int, dns_total: int, ip_passed: int, ip_total: int}|null} */
    private function stored(): array
    {
        $value = Cache::store('redis')->get(self::Key);
        if (! is_array($value)) {
            return ['failures' => [], 'probe' => null, 'active' => null];
        }

        $result = [
            'failures' => is_array($value['failures'] ?? null) ? $value['failures'] : [],
            'probe' => is_array($value['probe'] ?? null) ? $value['probe'] : null,
            'active' => is_array($value['active'] ?? null) ? $value['active'] : null,
        ];

        /** @var array{failures: array<int, string>, probe: array{probed_at: string, dns_passed: int, dns_total: int, ip_passed: int, ip_total: int}|null, active: array{mode: 'dns'|'network', detected_at: string, affected_checks: list<int>, dns_passed: int, dns_total: int, ip_passed: int, ip_total: int}|null} $result */
        return $result;
    }
}
