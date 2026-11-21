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
        $timeout = config()->integer('heptapod.control_probe.timeout_seconds', 3);

        $dnsPassed = 0;
        foreach ($dnsHosts as $host) {
            try {
                $this->resolver->resolve($host, microtime(true) + $timeout);
                $dnsPassed++;
            } catch (TargetResolutionException|FetchException) {
                // Failed control lookup counts as 0 passed; never leaks into uptime check errors.
            }
        }

        $ipPassed = 0;
        foreach ($ipTargets as $target) {
            $errno = 0;
            $errstr = '';
            $socket = @stream_socket_client('tcp://'.$target, $errno, $errstr, (float) $timeout);
            if ($socket !== false) {
                fclose($socket);
                $ipPassed++;
            }
        }

        return new ControlProbeResult($dnsPassed, count($dnsHosts), $ipPassed, count($ipTargets));
    }
}
