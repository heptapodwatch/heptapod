<?php

declare(strict_types=1);

namespace Heptapod\Checks;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckKey;
use Heptapod\Enums\FreshnessSuppression;

/**
 * Why a freshness gap is explained, and the time that explanation rests on. Most sources have a real start (an incident's
 * first failure, a maintenance or snooze start). An uptime Check error without an incident only knows its latest Errored
 * run, so that time is exposed as "latest error", never as a "since" that would move with every run.
 */
final readonly class FreshnessSuppressionState
{
    private function __construct(public FreshnessSuppression $reason, public CarbonImmutable $at, private bool $latestError) {}

    public static function since(FreshnessSuppression $reason, CarbonImmutable $since): self
    {
        return new self($reason, $since, false);
    }

    public static function latestUptimeError(CarbonImmutable $latestErrorAt): self
    {
        return new self(FreshnessSuppression::UptimeError, $latestErrorAt, true);
    }

    public function sinceAt(): ?CarbonImmutable
    {
        return $this->latestError ? null : $this->at;
    }

    public function latestErrorAt(): ?CarbonImmutable
    {
        return $this->latestError ? $this->at : null;
    }

    public function headlineKey(CheckKey $type): string
    {
        return $this->latestError
            ? 'freshness.suppressed.'.($type === CheckKey::Performance ? 'performance' : 'check').'.uptime_error_latest'
            : $this->reason->headlineKey($type);
    }
}
