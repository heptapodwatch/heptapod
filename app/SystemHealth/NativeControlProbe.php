<?php

declare(strict_types=1);

namespace Heptapod\SystemHealth;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\ControlProbe;
use Heptapod\Contracts\ControlProbeResult;
use Heptapod\Contracts\Http\FetchException;
use Heptapod\Contracts\Http\TargetResolutionException;
use Heptapod\Contracts\Http\TargetResolver;

/**
 * Checks whether this monitor can resolve two outside DNS names and open two direct-IP TCP connections.
 * Carries no HTTP request, TLS handshake, telemetry or instance identifiers.
 * When a check deadline is provided, bounds the whole probe batch so recording finishes inside the job budget.
 */
final readonly class NativeControlProbe implements ControlProbe
{
    /** Reserve time before the check deadline so result recording still completes inside the job budget. */
    private const float BudgetMarginSeconds = 2.0;

    private const float MinimumTargetTimeoutSeconds = 0.1;

    public function __construct(private TargetResolver $resolver) {}

    public function probe(?CarbonImmutable $deadline = null): ControlProbeResult
    {
        /** @var list<string> $dnsHosts */
        $dnsHosts = config()->array('heptapod.control_probe.dns_hosts', []);
        /** @var list<string> $ipTargets */
        $ipTargets = config()->array('heptapod.control_probe.ip_targets', []);
        $perTargetTimeout = (float) config()->integer('heptapod.control_probe.timeout_seconds', 3);

        $batchDeadline = null;
        if ($deadline instanceof CarbonImmutable) {
            $remainingBudget = (($deadline->getTimestampMs() - CarbonImmutable::now('UTC')->getTimestampMs()) / 1000.0) - self::BudgetMarginSeconds;
            if ($remainingBudget < self::MinimumTargetTimeoutSeconds) {
                return new ControlProbeResult(0, count($dnsHosts), 0, count($ipTargets), complete: false);
            }
            $batchDeadline = microtime(true) + $remainingBudget;
        }

        $dnsPassed = 0;
        foreach ($dnsHosts as $host) {
            $timeout = $this->targetTimeout($perTargetTimeout, $batchDeadline);
            if ($timeout === null) {
                return new ControlProbeResult($dnsPassed, count($dnsHosts), 0, count($ipTargets), complete: false);
            }
            try {
                $this->resolver->resolve($host, microtime(true) + $timeout);
                $dnsPassed++;
            } catch (TargetResolutionException|FetchException) {
                // Failed control lookup counts as 0 passed; never leaks into uptime check errors.
            }
        }

        $ipPassed = 0;
        foreach ($ipTargets as $target) {
            $timeout = $this->targetTimeout($perTargetTimeout, $batchDeadline);
            if ($timeout === null) {
                return new ControlProbeResult($dnsPassed, count($dnsHosts), $ipPassed, count($ipTargets), complete: false);
            }
            $errno = 0;
            $errstr = '';
            $socket = @stream_socket_client('tcp://'.$target, $errno, $errstr, $timeout);
            if ($socket !== false) {
                fclose($socket);
                $ipPassed++;
            }
        }

        $complete = $batchDeadline === null || microtime(true) < $batchDeadline
            || (($dnsHosts === [] || $dnsPassed > 0) && ($ipTargets === [] || $ipPassed > 0));

        return new ControlProbeResult($dnsPassed, count($dnsHosts), $ipPassed, count($ipTargets), complete: $complete);
    }

    private function targetTimeout(float $perTargetTimeout, ?float $batchDeadline): ?float
    {
        if ($batchDeadline === null) {
            return $perTargetTimeout;
        }
        $remaining = $batchDeadline - microtime(true);
        if ($remaining < self::MinimumTargetTimeoutSeconds) {
            return null;
        }

        return min($perTargetTimeout, $remaining);
    }
}
