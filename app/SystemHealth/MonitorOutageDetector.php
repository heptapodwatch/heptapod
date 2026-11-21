<?php

declare(strict_types=1);

namespace Heptapod\SystemHealth;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\ControlProbe;
use Heptapod\Contracts\ControlProbeResult;
use Heptapod\Contracts\Http\FetchFailureKind;
use Heptapod\Contracts\MonitorOutageGuard;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Detects a monitor-side network or DNS outage during an uptime failure burst.
 *
 * A burst of at least three distinct uptime checks failing with network-level errors within two minutes
 * triggers one in-flight outside control probe batch (cached for 60 seconds). Only when both independent
 * probes for that layer complete and fail does the guard reclassify matching runs as Check error and record
 * the active outage for EvaluateSystemIssues.
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

    /** Maximum lease for one in-flight control probe batch across workers. */
    private const int ProbeLockSeconds = 30;

    /** Leave room before the check deadline for recording the run. */
    private const float WaitBudgetMarginSeconds = 2.0;

    public function __construct(private ControlProbe $probe) {}

    public function evaluateFailure(int $checkId, FetchFailureKind $kind, CarbonImmutable $now, ?CarbonImmutable $deadline = null): ?string
    {
        if (! in_array($kind, [FetchFailureKind::Dns, FetchFailureKind::Connect, FetchFailureKind::Timeout], true)) {
            return null;
        }
        if (! $this->isConfigured()) {
            return null;
        }

        $needsProbe = false;
        $verdict = $this->withStateLock(function (array $state) use ($checkId, $kind, $now, &$needsProbe): array {
            $state['failures'] = $this->pruneFailures($state['failures'], $now);
            $state['failures'][$checkId] = ['at' => $now->utc()->format('Y-m-d\TH:i:s.uP'), 'kind' => $kind->value];

            $burst = count($state['failures']) >= self::BurstMinChecks || $state['active'] !== null;
            if (! $burst) {
                return [$state, null];
            }

            $cached = $this->freshProbe($state['probe'], $now);
            if (! $cached instanceof ControlProbeResult) {
                $needsProbe = true;

                return [$state, null];
            }

            return $this->applyProbe($state, $cached, $checkId, $kind);
        });

        if (! $needsProbe) {
            return $verdict;
        }

        $waitSeconds = $this->probeWaitSeconds($now, $deadline);
        $probeLock = $this->locks()->lock(self::Key.':probing', self::ProbeLockSeconds);
        try {
            $acquired = $waitSeconds > 0 ? $probeLock->block($waitSeconds) : $probeLock->get();
        } catch (LockTimeoutException) {
            return null;
        }
        if ($acquired !== true) {
            return null;
        }

        try {
            // Re-check cache once we hold the single-flight probe lock: another worker may have just probed.
            $cached = $this->freshProbe($this->stored()['probe'], $now);
            if ($cached instanceof ControlProbeResult) {
                return $this->withStateLock(fn (array $state): array => $this->applyProbe($state, $cached, $checkId, $kind));
            }

            $probeStartedAt = $now->utc();
            $result = $this->probe->probe($deadline);
            if (! $result->complete) {
                return null;
            }

            return $this->withStateLock(function (array $state) use ($result, $checkId, $kind, $now, $probeStartedAt): array {
                $state['failures'] = $this->pruneFailures($state['failures'], $now);
                $state['failures'][$checkId] = ['at' => $now->utc()->format('Y-m-d\TH:i:s.uP'), 'kind' => $kind->value];

                // Never let an older probe result overwrite a newer verdict (e.g. a healthy recovery probe).
                if ($state['probe'] !== null && $this->hasNewerProbe($state['probe'], $probeStartedAt)) {
                    $existing = $this->probeFromArray($state['probe']);

                    return $this->applyProbe($state, $existing, $checkId, $kind);
                }

                $state['probe'] = $this->serializeProbe($result, $probeStartedAt);

                return $this->applyProbe($state, $result, $checkId, $kind);
            });
        } finally {
            $probeLock->release();
        }
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
                $this->clearActive($now);
            }

            return null;
        }

        $active = $this->stored()['active'];
        if (! $isOpen) {
            return $active === null ? null : $this->issueDetails($active);
        }

        $probeStartedAt = $now->utc();
        $result = $this->probe->probe();
        if (! $result->complete) {
            return $active === null ? null : $this->issueDetails($active);
        }

        $mode = $result->mode();
        if ($mode === null) {
            $this->withStateLock(function (array $state) use ($result, $probeStartedAt): array {
                if (! $this->hasNewerProbe($state['probe'], $probeStartedAt)) {
                    $state['probe'] = $this->serializeProbe($result, $probeStartedAt);
                    $state['active'] = null;
                }

                return [$state, null];
            });

            return null;
        }

        $updated = $this->withStateLock(function (array $state) use ($result, $mode, $probeStartedAt): array {
            if ($this->hasNewerProbe($state['probe'], $probeStartedAt)) {
                return [$state, $state['active']];
            }
            $state['probe'] = $this->serializeProbe($result, $probeStartedAt);
            $affected = $state['active']['affected_checks'] ?? [];
            $state['active'] = [
                'mode' => $mode,
                'affected_checks' => $affected,
                'dns_passed' => $result->dnsPassed,
                'dns_total' => $result->dnsTotal,
                'ip_passed' => $result->ipPassed,
                'ip_total' => $result->ipTotal,
            ];

            return [$state, $state['active']];
        });

        return $updated === null ? null : $this->issueDetails($updated);
    }

    public function clearActive(?CarbonImmutable $at = null): void
    {
        $this->withStateLock(static function (array $state) use ($at): array {
            $state['active'] = null;
            if ($at instanceof CarbonImmutable) {
                $state['probe'] = null;
            }

            return [$state, null];
        });
    }

    private function isConfigured(): bool
    {
        return config()->array('heptapod.control_probe.dns_hosts', []) !== []
            || config()->array('heptapod.control_probe.ip_targets', []) !== [];
    }

    private function probeWaitSeconds(CarbonImmutable $now, ?CarbonImmutable $deadline): int
    {
        if (! $deadline instanceof CarbonImmutable) {
            return 5;
        }
        $remaining = (($deadline->getTimestampMs() - $now->getTimestampMs()) / 1000.0) - self::WaitBudgetMarginSeconds;

        return max(0, min(5, (int) floor($remaining)));
    }

    /**
     * @param  array{failures: array<int, array{at: string, kind: string}>, probe: array{probed_at: string, dns_passed: int, dns_total: int, ip_passed: int, ip_total: int}|null, active: array{mode: 'dns'|'network', affected_checks: list<int>, dns_passed: int, dns_total: int, ip_passed: int, ip_total: int}|null}  $state
     * @return array{0: array{failures: array<int, array{at: string, kind: string}>, probe: array{probed_at: string, dns_passed: int, dns_total: int, ip_passed: int, ip_total: int}|null, active: array{mode: 'dns'|'network', affected_checks: list<int>, dns_passed: int, dns_total: int, ip_passed: int, ip_total: int}|null}, 1: 'dns'|'network'|null}
     */
    private function applyProbe(array $state, ControlProbeResult $result, int $checkId, FetchFailureKind $kind): array
    {
        $mode = $result->mode();
        if ($mode === null) {
            $state['active'] = null;

            return [$state, null];
        }

        $affected = $state['active']['affected_checks'] ?? [];
        if ($result->covers($kind)) {
            $matching = [$checkId];
            foreach ($state['failures'] as $id => $entry) {
                $entryKind = FetchFailureKind::tryFrom($entry['kind']);
                if ($entryKind instanceof FetchFailureKind && $result->covers($entryKind)) {
                    $matching[] = $id;
                }
            }
            $affected = array_values(array_unique([...$affected, ...$matching]));
            sort($affected);
        }

        if ($result->covers($kind) || $state['active'] !== null) {
            $state['active'] = [
                'mode' => $mode,
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
     * @param  array<int, array{at: string, kind: string}>  $failures
     * @return array<int, array{at: string, kind: string}>
     */
    private function pruneFailures(array $failures, CarbonImmutable $now): array
    {
        $cutoff = $now->subSeconds(self::WindowSeconds);
        $kept = [];
        foreach ($failures as $id => $entry) {
            if (CarbonImmutable::parse($entry['at'])->greaterThanOrEqualTo($cutoff)) {
                $kept[$id] = $entry;
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

        return $this->probeFromArray($probe);
    }

    /**
     * @param  array{probed_at: string, dns_passed: int, dns_total: int, ip_passed: int, ip_total: int}|null  $probe
     */
    private function hasNewerProbe(?array $probe, CarbonImmutable $candidateAt): bool
    {
        return $probe !== null && CarbonImmutable::parse($probe['probed_at'])->greaterThan($candidateAt);
    }

    /**
     * @param  array{probed_at: string, dns_passed: int, dns_total: int, ip_passed: int, ip_total: int}  $probe
     */
    private function probeFromArray(array $probe): ControlProbeResult
    {
        return new ControlProbeResult($probe['dns_passed'], $probe['dns_total'], $probe['ip_passed'], $probe['ip_total']);
    }

    /** @return array{probed_at: string, dns_passed: int, dns_total: int, ip_passed: int, ip_total: int} */
    private function serializeProbe(ControlProbeResult $result, CarbonImmutable $at): array
    {
        return [
            'probed_at' => $at->utc()->format('Y-m-d\TH:i:s.uP'),
            'dns_passed' => $result->dnsPassed,
            'dns_total' => $result->dnsTotal,
            'ip_passed' => $result->ipPassed,
            'ip_total' => $result->ipTotal,
        ];
    }

    /**
     * @param  array{mode: 'dns'|'network', affected_checks: list<int>, dns_passed: int, dns_total: int, ip_passed: int, ip_total: int}  $active
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

    private function locks(): LockProvider
    {
        $locks = Cache::store('redis')->getStore();
        if (! $locks instanceof LockProvider) {
            throw new \LogicException('The Redis cache store must provide locks.');
        }

        return $locks;
    }

    /**
     * @template T
     *
     * @param  callable(array{failures: array<int, array{at: string, kind: string}>, probe: array{probed_at: string, dns_passed: int, dns_total: int, ip_passed: int, ip_total: int}|null, active: array{mode: 'dns'|'network', affected_checks: list<int>, dns_passed: int, dns_total: int, ip_passed: int, ip_total: int}|null}): array{0: array{failures: array<int, array{at: string, kind: string}>, probe: array{probed_at: string, dns_passed: int, dns_total: int, ip_passed: int, ip_total: int}|null, active: array{mode: 'dns'|'network', affected_checks: list<int>, dns_passed: int, dns_total: int, ip_passed: int, ip_total: int}|null}, 1: T}  $change
     * @return T
     */
    private function withStateLock(callable $change): mixed
    {
        return $this->locks()->lock(self::Key.':lock', 10)->block(5, function () use ($change): mixed {
            [$next, $result] = $change($this->stored());
            Cache::store('redis')->forever(self::Key, $next);

            return $result;
        });
    }

    /** @return array{failures: array<int, array{at: string, kind: string}>, probe: array{probed_at: string, dns_passed: int, dns_total: int, ip_passed: int, ip_total: int}|null, active: array{mode: 'dns'|'network', affected_checks: list<int>, dns_passed: int, dns_total: int, ip_passed: int, ip_total: int}|null} */
    private function stored(): array
    {
        $value = Cache::store('redis')->get(self::Key);
        if (! is_array($value)) {
            return ['failures' => [], 'probe' => null, 'active' => null];
        }

        $failures = [];
        if (is_array($value['failures'] ?? null)) {
            foreach ($value['failures'] as $id => $entry) {
                if (is_int($id) && is_array($entry) && is_string($entry['at'] ?? null) && is_string($entry['kind'] ?? null)) {
                    $failures[$id] = ['at' => $entry['at'], 'kind' => $entry['kind']];
                }
            }
        }

        $result = [
            'failures' => $failures,
            'probe' => is_array($value['probe'] ?? null) ? $value['probe'] : null,
            'active' => is_array($value['active'] ?? null) ? $value['active'] : null,
        ];

        /** @var array{failures: array<int, array{at: string, kind: string}>, probe: array{probed_at: string, dns_passed: int, dns_total: int, ip_passed: int, ip_total: int}|null, active: array{mode: 'dns'|'network', affected_checks: list<int>, dns_passed: int, dns_total: int, ip_passed: int, ip_total: int}|null} $result */
        return $result;
    }
}
