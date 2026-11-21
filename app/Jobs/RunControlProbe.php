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
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Runs the monitor's outside control probes off the check path and publishes one short-lived verdict.
 * ShouldBeUnique prevents duplicate queued dispatches; WithoutOverlapping ensures at most one execution runs at a time.
 * Incomplete batches are discarded rather than published as failing verdicts.
 */
#[Timeout(self::TimeoutSeconds)]
#[Tries(1)]
#[UniqueFor(self::UniqueForSeconds)]
final class RunControlProbe implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const int TimeoutSeconds = 30;

    public const int PublicationMarginSeconds = 5;

    public const int MaxTargetsPerKind = 3;

    public const int UniqueForSeconds = 60;

    public function __construct()
    {
        $this->onQueue('checks-fast');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new WithoutOverlapping('heptapod:monitor-outage:control-probe')->dontRelease()->expireAfter(self::TimeoutSeconds)];
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
