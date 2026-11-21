<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\Http\FetchFailureKind;

/** Guards uptime runs against opening site Down incidents when a burst coincides with failing monitor control probes. */
interface MonitorOutageGuard
{
    /**
     * Evaluates a network-level uptime failure. Returns 'dns' or 'network' when the run must be recorded as a
     * monitor-side Check error without opening a site incident, or null to keep normal Down handling.
     *
     * @return 'dns'|'network'|null
     */
    public function evaluateFailure(int $checkId, FetchFailureKind $kind, CarbonImmutable $now, ?CarbonImmutable $deadline = null): ?string;
}
