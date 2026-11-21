<?php

declare(strict_types=1);

namespace Heptapod\SystemHealth;

use Heptapod\Contracts\ControlProbe;
use Heptapod\Contracts\ControlProbeResult;
use Heptapod\Contracts\Http\FetchException;
use Heptapod\Contracts\Http\TargetResolutionException;
use Heptapod\Contracts\Http\TargetResolver;
use Heptapod\Http\Outbound\NativeTargetResolver;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Checks whether this monitor can resolve outside DNS names and open direct-IP TCP connections concurrently.
 * Carries no HTTP request, TLS handshake, telemetry or instance identifiers.
 * All targets run in parallel inside one per-target timeout window, well below RunControlProbe's job timeout.
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
        $ipPassed = 0;
        /** @var array<int, resource> $pendingSockets */
        $pendingSockets = [];
        /** @var array<int, Process> $pendingProcesses */
        $pendingProcesses = [];

        try {
            // Start all non-blocking direct-IP connects simultaneously.
            foreach ($ipTargets as $index => $target) {
                $errno = 0;
                $errstr = '';
                $socket = @stream_socket_client('tcp://'.$target, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
                if ($socket !== false) {
                    stream_set_blocking($socket, false);
                    $pendingSockets[$index] = $socket;
                }
            }

            // Start all DNS lookups concurrently alongside the IP connects.
            if ($this->resolver instanceof NativeTargetResolver) {
                foreach ($dnsHosts as $index => $host) {
                    $process = $this->resolver->lookupProcess($host, $timeout);
                    $process->start();
                    $pendingProcesses[$index] = $process;
                }
            } else {
                $deadline = microtime(true) + $timeout;
                foreach ($dnsHosts as $host) {
                    try {
                        $this->resolver->resolve($host, $deadline);
                        $dnsPassed++;
                    } catch (TargetResolutionException|FetchException) {
                        // Failed control lookup counts as 0 passed.
                    }
                }
            }

            $batchDeadline = microtime(true) + $timeout + 0.5;
            while ($pendingSockets !== [] || $pendingProcesses !== []) {
                $remaining = $batchDeadline - microtime(true);
                if ($remaining <= 0) {
                    break;
                }

                if ($pendingSockets !== []) {
                    $read = null;
                    $write = array_values($pendingSockets);
                    $except = null;
                    $slice = $pendingProcesses === [] ? $remaining : min($remaining, 0.05);
                    $sec = (int) floor($slice);
                    $usec = (int) (($slice - $sec) * 1_000_000);
                    $ready = @stream_select($read, $write, $except, $sec, $usec);
                    if ($ready !== false && $ready > 0) {
                        foreach ($write as $socket) {
                            $key = array_search($socket, $pendingSockets, true);
                            if ($key === false) {
                                continue;
                            }
                            if ($this->socketConnected($socket)) {
                                $ipPassed++;
                            }
                            @fclose($socket);
                            unset($pendingSockets[$key]);
                        }
                    }
                } elseif ($pendingProcesses !== []) {
                    usleep(min(25_000, max(1_000, (int) ($remaining * 1_000_000))));
                }

                foreach ($pendingProcesses as $index => $process) {
                    try {
                        $process->checkTimeout();
                    } catch (ProcessTimedOutException) {
                        unset($pendingProcesses[$index]);

                        continue;
                    }
                    if (! $process->isRunning()) {
                        if ($process->isSuccessful()) {
                            $dnsPassed++;
                        }
                        unset($pendingProcesses[$index]);
                    }
                }
            }
        } finally {
            foreach ($pendingSockets as $socket) {
                @fclose($socket);
            }
            foreach ($pendingProcesses as $process) {
                if ($process->isRunning()) {
                    $process->stop(0);
                }
            }
        }

        return new ControlProbeResult($dnsPassed, count($dnsHosts), $ipPassed, count($ipTargets), complete: true);
    }

    /** @param resource $socket */
    private function socketConnected(mixed $socket): bool
    {
        if (@stream_socket_get_name($socket, true) === false) {
            return false;
        }
        $native = @socket_import_stream($socket);

        return $native !== false && socket_get_option($native, SOL_SOCKET, SO_ERROR) === 0;
    }
}
