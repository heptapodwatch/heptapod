<?php

declare(strict_types=1);

namespace Heptapod\Checks;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckKey;
use Heptapod\Enums\IncidentCause;
use Heptapod\Models\Check;
use Heptapod\Models\Incident;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;

final readonly class UptimeDependency
{
    /**
     * Two statements bounded by the given sites, for readers that need many sites.
     *
     * @param  list<int>  $siteIds
     * @param  CarbonImmutable|null  $resolvedAfter  Also keep incidents resolved after this moment (see openIncident()).
     */
    public function snapshot(array $siteIds, ?CarbonImmutable $resolvedAfter): UptimeSnapshot
    {
        if ($siteIds === []) {
            return new UptimeSnapshot([], new Collection, new Collection, $resolvedAfter);
        }
        $checks = Check::query();
        $checks->getQuery()->whereIn('site_id', $siteIds)->where('type', CheckKey::Uptime);
        $uptimeChecks = $checks->get()->keyBy('site_id');
        $query = Incident::query();
        $query->getQuery()->whereIn('site_id', $siteIds)->whereIn('check_id', $uptimeChecks->modelKeys());
        $query->getQuery()->where(fn (Builder $open): Builder => $resolvedAfter instanceof CarbonImmutable
            ? $open->whereNull('resolved_at')->orWhere('resolved_at', '>', $resolvedAfter->format('Y-m-d H:i:s.uP'))
            : $open->whereNull('resolved_at'))->orderBy('first_failure_at');

        return new UptimeSnapshot(array_fill_keys($siteIds, true), $uptimeChecks, $query->get(), $resolvedAfter);
    }

    public function check(int $siteId): ?Check
    {
        return Check::query()->where('site_id', $siteId)->where('type', CheckKey::Uptime)->first();
    }

    /**
     * With $openAt, an incident resolved after that moment still counts: it was open when a no-result attempt was recorded,
     * so recovery that lands before the next attempt cannot turn that attempt into a false gap.
     */
    public function openIncident(int $siteId, ?IncidentCause $cause = null, ?CarbonImmutable $openAt = null): ?Incident
    {
        $uptime = Check::query();
        $uptime->getQuery()->select('id')->where('site_id', $siteId)->where('type', CheckKey::Uptime);
        $incidents = Incident::query();
        $incidents->getQuery()->where('site_id', $siteId)
            ->where(fn (Builder $open): Builder => $openAt instanceof CarbonImmutable ? $open->whereNull('resolved_at')->orWhere('resolved_at', '>', $openAt->format('Y-m-d H:i:s.uP'))
                : $open->whereNull('resolved_at'))
            ->whereIn('check_id', $uptime->getQuery())->orderBy('first_failure_at');
        if ($cause instanceof IncidentCause) {
            $incidents->getQuery()->where('cause', $cause);
        }

        return $incidents->first();
    }
}
