<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Heptapod\Contracts\Http\FetchFailureKind;

/** Outcome of the monitor's own outside control probes (DNS resolution and direct-IP TCP connects). */
final readonly class ControlProbeResult
{
    public function __construct(
        public int $dnsPassed,
        public int $dnsTotal,
        public int $ipPassed,
        public int $ipTotal,
    ) {
        if ($dnsTotal < 0 || $dnsPassed < 0 || $dnsPassed > $dnsTotal
            || $ipTotal < 0 || $ipPassed < 0 || $ipPassed > $ipTotal) {
            throw new \InvalidArgumentException('Control probe counts must be non-negative and passed must not exceed total.');
        }
    }

    public function dnsFailed(): bool
    {
        return $this->dnsTotal > 0 && $this->dnsPassed === 0;
    }

    public function ipFailed(): bool
    {
        return $this->ipTotal > 0 && $this->ipPassed === 0;
    }

    /**
     * 'network' when all direct-IP probes failed, 'dns' when all DNS probes failed, or null when healthy/off.
     *
     * @return 'dns'|'network'|null
     */
    public function mode(): ?string
    {
        return match (true) {
            $this->ipFailed() => 'network',
            $this->dnsFailed() => 'dns',
            default => null,
        };
    }

    /** Direct-IP failure covers connect, timeout and DNS; DNS-only failure covers DNS resolution only. */
    public function covers(FetchFailureKind $kind): bool
    {
        if ($this->ipFailed()) {
            return in_array($kind, [FetchFailureKind::Connect, FetchFailureKind::Timeout, FetchFailureKind::Dns], true);
        }
        if ($this->dnsFailed()) {
            return $kind === FetchFailureKind::Dns;
        }

        return false;
    }
}
