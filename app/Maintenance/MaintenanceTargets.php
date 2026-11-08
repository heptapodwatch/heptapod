<?php

declare(strict_types=1);

namespace Heptapod\Maintenance;

use Carbon\CarbonImmutable;
use Closure;
use Heptapod\Models\Client;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Models\RecurringMaintenancePeriod;
use Heptapod\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Tags\Tag;

/**
 * A window covers a site through its site target (including legacy site_id), its client, or a shared tag.
 * The raw renderer and the site-row, site-ID and client-filter builder shapes preserve their existing SQL.
 * Client reach deliberately omits tags so shared tags cannot disclose cross-client windows.
 */
final class MaintenanceTargets
{
    /**
     * SQL identifiers come only from the calling query; the tag morph class remains a bound parameter.
     *
     * @param  literal-string  $maintenance
     * @param  literal-string  $site
     * @param  literal-string  $tag
     * @return array{literal-string, list<string>}
     */
    public static function coversSiteSql(string $maintenance, string $site, string $tag = 'mt'): array
    {
        return ["(({$maintenance}.target_type = 'site' AND coalesce({$maintenance}.target_id, {$maintenance}.site_id) = {$site}.id) "
            ."OR ({$maintenance}.target_type = 'client' AND {$maintenance}.target_id = {$site}.client_id) "
            ."OR ({$maintenance}.target_type = 'tag' AND EXISTS (SELECT 1 FROM taggables {$tag} WHERE {$tag}.tag_id = {$maintenance}.target_id "
            ."AND {$tag}.taggable_id = {$site}.id AND {$tag}.taggable_type = ?)))", [(new Site)->getMorphClass()]];
    }

    /** @param literal-string $table */
    public static function whereCoversSiteId(QueryBuilder $query, int $siteId, string $table = 'maintenance_periods'): void
    {
        self::whereTargetMatches($query, $table,
            site: fn (QueryBuilder $site) => $site->where(fn (QueryBuilder $siteIds) => $siteIds
                ->where($table.'.target_id', $siteId)->orWhere(fn (QueryBuilder $legacy) => $legacy
                ->whereNull($table.'.target_id')->where($table.'.site_id', $siteId))),
            client: fn (QueryBuilder $client) => $client->whereIn($table.'.target_id', DB::table('sites')->select('client_id')->where('id', $siteId)),
            tag: fn (QueryBuilder $tag) => $tag->whereIn($table.'.target_id', DB::table('taggables')->select('tag_id')
                ->where('taggable_type', (new Site)->getMorphClass())->where('taggable_id', $siteId)));
    }

    /** @param literal-string $table */
    public function whereCoversClient(QueryBuilder $query, string $table, int $clientId): void
    {
        $clientSites = $this->sites('client', $clientId)->getQuery()->select('sites.id');
        self::whereTargetMatches($query, $table,
            site: fn (QueryBuilder $sites) => $sites->whereIn(DB::raw('coalesce('.$table.'.target_id, '.$table.'.site_id)'), $clientSites),
            client: fn (QueryBuilder $clients) => $clients->where($table.'.target_id', $clientId),
            tag: fn (QueryBuilder $tags) => $tags->whereIn($table.'.target_id', DB::table('taggables')->select('tag_id')
                ->where('taggable_type', (new Site)->getMorphClass())->whereIn('taggable_id', $clientSites)));
    }

    /**
     * @param  literal-string  $table
     * @param  list<int>  $clientIds
     */
    public function whereWithinClientReach(QueryBuilder $query, string $table, array $clientIds): void
    {
        self::whereTargetMatches($query, $table,
            site: fn (QueryBuilder $sites) => $sites->whereIn(DB::raw('coalesce('.$table.'.target_id, '.$table.'.site_id)'),
                DB::table('sites')->select('id')->whereIn('client_id', $clientIds)),
            client: fn (QueryBuilder $clients) => $clients->whereIn($table.'.target_id', $clientIds),
            tag: null);
    }

    /** @param literal-string $table */
    private function whereCoversSiteRow(QueryBuilder $query, string $table): void
    {
        self::whereTargetMatches($query, $table,
            site: fn (QueryBuilder $site) => $site->whereRaw('sites.id = coalesce('.$table.'.target_id, '.$table.'.site_id)'),
            client: fn (QueryBuilder $client) => $client->whereColumn('sites.client_id', $table.'.target_id'),
            tag: fn (QueryBuilder $tag) => $tag->whereIn('sites.id', DB::table('taggables')->select('taggable_id')
                ->where('taggable_type', (new Site)->getMorphClass())->whereColumn('tag_id', $table.'.target_id')));
    }

    /**
     * Keep each caller's indexed comparison shape while sharing target discrimination and grouping.
     *
     * @param  Closure(QueryBuilder): mixed  $site
     * @param  Closure(QueryBuilder): mixed  $client
     * @param  (Closure(QueryBuilder): mixed)|null  $tag
     */
    private static function whereTargetMatches(QueryBuilder $query, string $table, Closure $site, Closure $client, ?Closure $tag): void
    {
        $query->where(static function (QueryBuilder $targets) use ($table, $site, $client, $tag): void {
            foreach (['site' => $site, 'client' => $client, 'tag' => $tag] as $type => $match) {
                if (! $match instanceof Closure) {
                    continue;
                }
                $targets->orWhere(static function (QueryBuilder $branch) use ($table, $type, $match): void {
                    $branch->where($table.'.target_type', $type);
                    $match($branch);
                });
            }
        });
    }

    /**
     * @template T of MaintenancePeriod|RecurringMaintenancePeriod
     *
     * @param  Builder<T>  $query
     */
    public function withSiteCount(Builder $query, CarbonImmutable $now): void
    {
        $table = $query->getModel() instanceof MaintenancePeriod ? 'maintenance_periods' : 'recurring_maintenance_periods';
        $members = DB::table('sites')->selectRaw('count(*)');
        $this->whereCoversSiteRow($members, $table);
        if ($table === 'maintenance_periods') {
            $members->whereNotExists(static fn (QueryBuilder $excluded): QueryBuilder => $excluded->selectRaw('1')->from('maintenance_site_exclusions')
                ->whereColumn('maintenance_period_id', 'maintenance_periods.id')->whereColumn('site_id', 'sites.id')
                ->where('left_at', '<=', $now->format('Y-m-d H:i:s.uP')));
        }
        $query->getQuery()->addSelect($table.'.*')->selectSub($members, 'target_site_count');
    }

    /**
     * @param  Collection<int, MaintenancePeriod|RecurringMaintenancePeriod>  $windows
     * @return array<string, array{label: string, client_name: string|null}>
     */
    public function labels(Collection $windows): array
    {
        $byType = $windows->groupBy('target_type');
        $siteIds = $byType->get('site', collect())->map(static function (mixed $window): int {
            if (! $window instanceof MaintenancePeriod && ! $window instanceof RecurringMaintenancePeriod) {
                throw new \LogicException('Maintenance target requires a window or series.');
            }

            return $window->target_id ?? $window->site_id ?? throw new \LogicException('Site target missing.');
        });
        $sites = Site::query();
        $sites->getQuery()->whereIn('sites.id', $siteIds->all())->join('clients', 'clients.id', '=', 'sites.client_id')
            ->select('sites.*')->selectRaw(Client::displayNameSql().' AS target_client_name', [__('No client')]);
        $labels = [];
        foreach ($sites->get() as $site) {
            $clientName = $site->getAttribute('target_client_name');
            if (! is_string($clientName)) {
                throw new \LogicException('Site client label must be text.');
            }
            $labels['site:'.$site->id] = ['label' => $site->displayName(), 'client_name' => $clientName];
        }
        $clients = Client::query();
        $clients->getQuery()->whereIn('id', $byType->get('client', collect())->pluck('target_id')->all());
        foreach ($clients->get() as $client) {
            $labels['client:'.$client->id] = ['label' => $client->displayName(), 'client_name' => $client->displayName()];
        }
        $tags = Tag::query();
        $tags->getQuery()->whereIn('id', $byType->get('tag', collect())->pluck('target_id')->all());
        foreach ($tags->get() as $tag) {
            $labels['tag:'.$tag->id] = ['label' => $tag->name, 'client_name' => null];
        }

        return $labels;
    }

    /**
     * @return list<int>
     */
    public function clientIdsForWindow(MaintenancePeriod|RecurringMaintenancePeriod $window): array
    {
        return $this->clientIds($window->target_type, $window->target_id ?? $window->site_id ?? throw new \LogicException('Maintenance target missing.'));
    }

    /**
     * Clients whose windows or site lists a target change affects, for live signals. A client target is its own scope even without sites.
     *
     * @return list<int>
     */
    public function clientIds(string $type, int $id): array
    {
        if ($type === 'client') {
            return [$id];
        }

        return array_values($this->sites($type, $id)->get(['sites.client_id'])
            ->map(static fn (Site $site): int => $site->client_id)
            ->unique()
            ->all());
    }

    /** @return Builder<Site> */
    public function sites(string $type, int $id): Builder
    {
        $sites = Site::query();

        match ($type) {
            'site' => $sites->whereKey($id),
            'client' => $sites->getQuery()->where('client_id', $id),
            'tag' => $sites->getQuery()->whereIn('sites.id', DB::table('taggables')->select('taggable_id')
                ->where('taggable_type', (new Site)->getMorphClass())->where('tag_id', $id)),
            default => throw new \InvalidArgumentException('Invalid maintenance target.'),
        };

        return $sites;
    }
}
