<?php

declare(strict_types=1);

namespace Heptapod\Jobs;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\ControlProbe;
use Heptapod\SystemHealth\MonitorOutageDetector;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;

/**
 * Runs the monitor's outside control probes off the check path and publishes one short-lived verdict.
 * Incomplete batches are discarded rather than published as failing verdicts.
 */
#[Timeout(self::TimeoutSeconds)]
#[Tries(1)]
#[UniqueFor(self::UniqueForSeconds)]
final class RunControlProbe implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const int TimeoutSeconds = 75;

    public const int UniqueForSeconds = 90;

    public function __construct()
    {
        $this->onQueue('checks-fast');
    }

    public function handle(ControlProbe $probe, MonitorOutageDetector $detector): void
    {
        $result = $probe->probe();
        if (! $result->complete) {
            return;
        }

        $detector->recordVerdict($result, CarbonImmutable::now('UTC'));
    }
}
