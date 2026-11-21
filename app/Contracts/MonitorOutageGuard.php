<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\Http\FetchFailureKind;

/** Guards uptime runs against opening site Down incidents when a burst coincides with a failing monitor control verdict. */
interface MonitorOutageGuard
{
    /**
     * Evaluates a network-level uptime failure against the cached control verdict.
     * Never runs probes or waits; dispatches a probe job when a burst has no fresh verdict.
     * Returns 'dns' or 'network' to record as a monitor-side Check error, or null for normal Down handling.
     *
     * @return 'dns'|'network'|null
     */
    public function evaluateFailure(int $checkId, FetchFailureKind $kind, CarbonImmutable $now): ?string;
}
