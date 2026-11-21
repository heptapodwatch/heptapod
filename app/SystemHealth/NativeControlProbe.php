<?php

declare(strict_types=1);

namespace Heptapod\SystemHealth;

use Heptapod\Contracts\ControlProbe;
use Heptapod\Contracts\ControlProbeResult;
use Heptapod\Contracts\Http\FetchException;
use Heptapod\Contracts\Http\TargetResolutionException;
use Heptapod\Contracts\Http\TargetResolver;

/**
 * Checks whether this monitor can resolve two outside DNS names and open two direct-IP TCP connections.
 * Carries no HTTP request, TLS handshake, telemetry or instance identifiers.
 * Bounds the whole probe batch below RunControlProbe's timeout so unfinished batches return incomplete.
 */
final readonly class NativeControlProbe implements ControlProbe
{
    public const float MaxBatchSeconds = 60.0;

    private const float MinimumTargetTimeoutSeconds = 0.1;

    public function __construct(private TargetResolver $resolver) {}

    public function probe(): ControlProbeResult
    {
        /** @var list<string> $dnsHosts */
        $dnsHosts = config()->array('heptapod.control_probe.dns_hosts', []);
        /** @var list<string> $ipTargets */
        $ipTargets = config()->array('heptapod.control_probe.ip_targets', []);
        $perTargetTimeout = (float) config()->integer('heptapod.control_probe.timeout_seconds', 3);
        $batchDeadline = microtime(true) + self::MaxBatchSeconds;

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

        $complete = microtime(true) < $batchDeadline
            || (($dnsHosts === [] || $dnsPassed > 0) && ($ipTargets === [] || $ipPassed > 0));

        return new ControlProbeResult($dnsPassed, count($dnsHosts), $ipPassed, count($ipTargets), complete: $complete);
    }

    private function targetTimeout(float $perTargetTimeout, float $batchDeadline): ?float
    {
        $remaining = $batchDeadline - microtime(true);
        if ($remaining < self::MinimumTargetTimeoutSeconds) {
            return null;
        }

        return min($perTargetTimeout, $remaining);
    }
}
