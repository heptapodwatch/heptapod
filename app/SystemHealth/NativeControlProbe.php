<?php

declare(strict_types=1);

namespace Heptapod\SystemHealth;

use Heptapod\Contracts\ControlProbe;
use Heptapod\Contracts\ControlProbeResult;
use Heptapod\Contracts\Http\FetchException;
use Heptapod\Contracts\Http\TargetResolutionException;
use Heptapod\Contracts\Http\TargetResolver;
use Heptapod\Contracts\Http\TimedTargetResolver;

/**
 * Checks whether this monitor can resolve outside DNS names and open direct-IP TCP connections.
 * Carries no HTTP request, TLS handshake, telemetry or instance identifiers.
 * Delegates DNS resolution to TimedTargetResolver so each lookup is bounded by its own process deadline.
 */
final readonly class NativeControlProbe implements ControlProbe
{
    public function __construct(private TargetResolver $resolver) {}

    public function probe(): ControlProbeResult
    {
        /** @var list<string> $dnsHosts */
        $dnsHosts = config()->array('heptapod.control_probe.dns_hosts', []);
        /** @var list<string> $ipTargets */
        $ipTargets = config()->array('heptapod.control_probe.ip_targets', []);
        $timeout = (float) config()->integer('heptapod.control_probe.timeout_seconds', 5);

        $dnsPassed = 0;
        foreach ($dnsHosts as $host) {
            $deadline = microtime(true) + $timeout;
            try {
                if ($this->resolver instanceof TimedTargetResolver) {
                    $this->resolver->resolveTimed($host, $deadline);
                } else {
                    $this->resolver->resolve($host, $deadline);
                }
                $dnsPassed++;
            } catch (TargetResolutionException|FetchException) {
                // Failed or timed-out control lookup counts as 0 passed.
            }
        }

        $ipPassed = 0;
        foreach ($ipTargets as $target) {
            $errno = 0;
            $errstr = '';
            $socket = @stream_socket_client('tcp://'.$target, $errno, $errstr, $timeout);
            if ($socket !== false) {
                fclose($socket);
                $ipPassed++;
            }
        }

        return new ControlProbeResult($dnsPassed, count($dnsHosts), $ipPassed, count($ipTargets), complete: true);
    }
}
