<?php

declare(strict_types=1);

namespace Heptapod\Testing;

use Carbon\CarbonImmutable;
use Closure;
use Heptapod\Contracts\ControlProbe;
use Heptapod\Contracts\ControlProbeResult;

final class FakeControlProbe implements ControlProbe
{
    public int $calls = 0;

    public ?CarbonImmutable $lastDeadline = null;

    /** @var (Closure(?CarbonImmutable): ControlProbeResult)|null */
    public ?Closure $handler = null;

    public function __construct(public ControlProbeResult $result) {}

    public static function healthy(): self
    {
        return new self(new ControlProbeResult(dnsPassed: 2, dnsTotal: 2, ipPassed: 2, ipTotal: 2));
    }

    public static function dnsFailing(): self
    {
        return new self(new ControlProbeResult(dnsPassed: 0, dnsTotal: 2, ipPassed: 2, ipTotal: 2));
    }

    public static function networkFailing(): self
    {
        return new self(new ControlProbeResult(dnsPassed: 0, dnsTotal: 2, ipPassed: 0, ipTotal: 2));
    }

    public static function ipFailing(): self
    {
        return new self(new ControlProbeResult(dnsPassed: 2, dnsTotal: 2, ipPassed: 0, ipTotal: 2));
    }

    public static function disabled(): self
    {
        return new self(new ControlProbeResult(dnsPassed: 0, dnsTotal: 0, ipPassed: 0, ipTotal: 0));
    }

    public static function incomplete(): self
    {
        return new self(new ControlProbeResult(dnsPassed: 0, dnsTotal: 2, ipPassed: 0, ipTotal: 2, complete: false));
    }

    public function probe(?CarbonImmutable $deadline = null): ControlProbeResult
    {
        $this->calls++;
        $this->lastDeadline = $deadline;

        return $this->handler instanceof Closure ? ($this->handler)($deadline) : $this->result;
    }
}
