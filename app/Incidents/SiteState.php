<?php

declare(strict_types=1);

namespace Heptapod\Incidents;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Enums\SiteStatus;
use Heptapod\Maintenance\MaintenanceTargets;
use Heptapod\Models\Site;
use Illuminate\Database\Eloquent\Builder;

final readonly class SiteState
{
    public function __construct(private CheckRegistry $registry) {}

    public function derive(Site $site): SiteStatus
    {
        $row = $this->annotate(Site::query())->whereKey($site->id)->firstOrFail();
        $state = $row->getAttribute('state');
        if (! is_string($state)) {
            throw new \LogicException('Site state projection must return a string.');
        }

        return SiteStatus::from($state);
    }

    /**
     * One projection for individual reads, page filters and status sorting.
     * Maintenance intervals are half-open, matching MaintenancePeriod::activeAt.
     * A check with a pending_reason (CheckPending) counts as pending, not by its latest result, matching CheckStripData.
     * The down-state scalar check lookup prevents an open-incident join from scanning every incident per site when statistics are stale.
     *
     * @param  Builder<Site>  $query
     * @return Builder<Site>
     */
    public function annotate(Builder $query): Builder
    {
        $now = CarbonImmutable::now()->format('Y-m-d H:i:s.uP');
        $unavailable = array_map(static fn (CheckKey $key): string => $key->value, $this->registry->unavailableKeys());
        $available = $unavailable === [] ? 'TRUE' : 'type NOT IN ('.implode(',', array_fill(0, count($unavailable), '?')).')';
        $held = UptimeDependencies::heldAfterRecoverySql('held_incident');
        [$coversSite, $coversSiteBindings] = MaintenanceTargets::coversSiteSql('m', 'sites');
        $query->getQuery()->select('sites.*')->selectRaw(<<<SQL
            (WITH available_checks AS NOT MATERIALIZED (SELECT * FROM checks WHERE {$available}),
                attention_checks AS NOT MATERIALIZED (SELECT * FROM available_checks c WHERE NOT EXISTS (
                    SELECT 1 FROM incidents held_incident WHERE held_incident.check_id = c.id AND ({$held})))
            SELECT CASE
                WHEN sites.paused_at IS NOT NULL OR sites.marked_for_deletion_at IS NOT NULL THEN 'paused'
                WHEN EXISTS (SELECT 1 FROM maintenance_periods m WHERE m.starts_at <= ? AND m.ends_at > ?
                    AND {$coversSite}
                    AND NOT EXISTS (SELECT 1 FROM maintenance_site_exclusions ex WHERE ex.maintenance_period_id = m.id
                        AND ex.site_id = sites.id AND ex.left_at <= ?)) THEN 'maintenance'
                WHEN NOT EXISTS (SELECT 1 FROM checks c WHERE c.site_id = sites.id AND c.enabled) THEN 'disabled'
                WHEN NOT EXISTS (SELECT 1 FROM available_checks c WHERE c.site_id = sites.id AND c.enabled) THEN 'unavailable'
                WHEN EXISTS (SELECT 1 FROM incidents i WHERE i.check_id = (SELECT c.id FROM available_checks c
                    WHERE c.site_id = sites.id AND c.enabled AND c.type = 'uptime')
                    AND i.site_id = sites.id AND i.resolved_at IS NULL AND i.cause = 'failed') THEN 'down'
                WHEN EXISTS (SELECT 1 FROM attention_checks c WHERE c.site_id = sites.id AND c.enabled AND c.latest_run_result = 'failed' AND c.pending_reason IS NULL) THEN 'failed'
                WHEN EXISTS (SELECT 1 FROM attention_checks c WHERE c.site_id = sites.id AND c.enabled AND c.latest_run_result = 'errored-or-timed-out' AND c.pending_reason IS NULL) THEN 'check-error'
                WHEN EXISTS (SELECT 1 FROM attention_checks c WHERE c.site_id = sites.id AND c.enabled AND c.latest_run_result = 'warning' AND c.pending_reason IS NULL) THEN 'warning'
                WHEN EXISTS (SELECT 1 FROM attention_checks c WHERE c.site_id = sites.id AND c.enabled AND (c.latest_run_result = 'pending' OR c.pending_reason IS NOT NULL)) THEN 'pending'
                ELSE 'ok'
            END) AS state
            SQL, [...$unavailable, $now, $now, ...$coversSiteBindings, $now]);

        return $query;
    }
}
