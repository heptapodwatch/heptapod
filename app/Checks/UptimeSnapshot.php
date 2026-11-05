<?php

declare(strict_types=1);

namespace Heptapod\Checks;

use Carbon\CarbonImmutable;
use Heptapod\Enums\IncidentCause;
use Heptapod\Models\Check;
use Heptapod\Models\Incident;
use Illuminate\Database\Eloquent\Collection;
use LogicException;

/** The uptime checks and relevant uptime incidents of a fixed set of sites, read once by UptimeDependency::snapshot(). */
final readonly class UptimeSnapshot
{
    /**
     * @param  array<int, true>  $siteIds
     * @param  Collection<int, Check>  $checks  Uptime checks keyed by site ID.
     * @param  Collection<int, Incident>  $incidents  Open incidents and those resolved after $resolvedAfter, by first failure.
     */
    public function __construct(private array $siteIds, private Collection $checks, private Collection $incidents, private ?CarbonImmutable $resolvedAfter) {}

    public function check(int $siteId): ?Check
    {
        $this->assertCovers($siteId);

        return $this->checks->get($siteId);
    }

    /** Same contract as UptimeDependency::openIncident(); $openAt must not precede the loaded resolution window. */
    public function openIncident(int $siteId, ?IncidentCause $cause = null, ?CarbonImmutable $openAt = null): ?Incident
    {
        $this->assertCovers($siteId);
        if ($openAt instanceof CarbonImmutable && (! $this->resolvedAfter instanceof CarbonImmutable || ! $openAt->greaterThanOrEqualTo($this->resolvedAfter))) {
            throw new LogicException('The uptime snapshot does not hold incidents resolved before '.$openAt->toIso8601String().'.');
        }

        return $this->incidents->first(static fn (Incident $incident): bool => $incident->site_id === $siteId
            && (! $cause instanceof IncidentCause || $incident->cause === $cause)
            && ($incident->resolved_at === null || ($openAt instanceof CarbonImmutable && $incident->resolved_at->greaterThan($openAt))));
    }

    private function assertCovers(int $siteId): void
    {
        if (! isset($this->siteIds[$siteId])) {
            throw new LogicException('Site '.$siteId.' is outside this uptime snapshot.');
        }
    }
}
