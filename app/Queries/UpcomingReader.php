<?php

declare(strict_types=1);

namespace Heptapod\Queries;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\UpcomingEventSource;
use Heptapod\Contracts\UpcomingOccurrence;
use Heptapod\Contracts\UpcomingPosition;
use Heptapod\Data\Ui\UpcomingItemData;
use Heptapod\Enums\SiteIconBacking;
use Heptapod\Maintenance\MaintenanceOccurrences;
use Heptapod\Maintenance\MaintenanceTargets;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Models\RecurringMaintenancePeriod;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** @phpstan-type SiteRow \stdClass&object{id: int, client_id: int, url: string, friendly_name: string|null, client_name: string, icon_hash: string|null, icon_backing: value-of<SiteIconBacking>} */
final readonly class UpcomingReader
{
    public function __construct(private SitesReader $sites, private MaintenanceOccurrences $occurrences, private MaintenanceReader $maintenance, private MaintenanceTargets $targets) {}

    public function count(User $user): int
    {
        $now = CarbonImmutable::now('UTC');
        $through = $now->addDays(14);
        $siteIds = $this->sites->activeVisibleSiteIds($user);
        $count = $this->standaloneMaintenance($user, $now, $through)->getQuery()->count();
        $recurring = RecurringMaintenancePeriod::query();
        $this->maintenance->visibleTargets($recurring, $user);
        foreach ($recurring->cursor() as $period) {
            foreach ($this->recurringDates($period, $now, $through) as $occurrence) {
                $count++;
            }
        }
        /** @var list<int> $checkIds */
        $checkIds = DB::table('checks')->whereIntegerInRaw('site_id', $siteIds)->where('enabled', true)->pluck('id')->all();
        foreach (app()->tagged(UpcomingEventSource::class) as $source) {
            if (! $source instanceof UpcomingEventSource) {
                throw new \LogicException('Upcoming source must implement its contract.');
            }
            $count += $source->count($checkIds, $now, $through);
        }

        return $count;
    }

    /** @return EloquentBuilder<MaintenancePeriod> */
    private function standaloneMaintenance(User $user, CarbonImmutable $now, CarbonImmutable $through, ?int $clientId = null): EloquentBuilder
    {
        $query = MaintenancePeriod::query();
        $this->maintenance->visibleTargets($query, $user, $clientId);
        $query->getQuery()->whereNull('recurring_maintenance_period_id')
            ->where('starts_at', '>', $now->format('Y-m-d H:i:s.uP'))->where('starts_at', '<=', $through);

        return $query;
    }

    /** @return iterable<array{CarbonImmutable, CarbonImmutable}> [start, end] of each occurrence that starts in the range */
    private function recurringDates(RecurringMaintenancePeriod $period, CarbonImmutable $now, CarbonImmutable $through): iterable
    {
        foreach (($this->occurrences)($period, $now->subDay()->format('Y-m-d'), $through->addDay()->format('Y-m-d')) as [$start, $end]) {
            if ($start->betweenIncluded($now, $through)) {
                yield [$start, $end];
            }
        }
    }

    /** @return list<UpcomingItemData> */
    public function read(User $user, int $days = 90, ?string $onlyType = null, ?int $clientId = null): array
    {
        $now = CarbonImmutable::now('UTC');
        $through = $now->addDays($days);
        $visibleIds = $this->sites->visibleSiteIds($user);
        /** @var Collection<int|string, SiteRow> $sites */
        $sites = DB::table('sites')->join('clients', 'clients.id', '=', 'sites.client_id')
            ->whereIntegerInRaw('sites.id', $visibleIds)->whereNull('sites.paused_at')->whereNull('sites.marked_for_deletion_at')
            ->when($clientId !== null, static fn (Builder $query): Builder => $query->where('sites.client_id', $clientId))
            ->get(['sites.id', 'sites.client_id', 'sites.url', 'sites.friendly_name', 'clients.name as client_name', 'sites.icon_hash', 'sites.icon_backing'])->keyBy('id');
        $items = [];
        $iconSites = Site::query();
        $iconSites->getQuery()->whereIntegerInRaw('id', $visibleIds)->whereNotNull('icon_hash');
        $icons = $iconSites->get(['id', 'icon_hash', 'icon_backing'])->mapWithKeys(static fn (Site $site): array => [$site->id => ['url' => $site->iconUrl(), 'backing' => $site->icon_backing]])->all();
        if ($onlyType === null || $onlyType === 'maintenance') {
            $maintenanceQuery = $this->standaloneMaintenance($user, $now, $through, $clientId);
            $this->targets->withSiteCount($maintenanceQuery, $now);
            $maintenanceQuery->getQuery()->orderBy('starts_at')->orderBy('id')->limit(100);
            $windows = $maintenanceQuery->get();
            $recurringQuery = RecurringMaintenancePeriod::query();
            $this->maintenance->visibleTargets($recurringQuery, $user, $clientId);
            $this->targets->withSiteCount($recurringQuery, $now);
            $series = $recurringQuery->get();
            $labels = $this->targets->labels(collect([...$windows->all(), ...$series->all()]));
            foreach ($windows as $period) {
                $items[] = $this->maintenanceItem($period, $period->starts_at, 'maintenance:'.$period->id, $labels, $icons);
            }
            foreach ($series as $period) {
                foreach ($this->recurringDates($period, $now, $through) as [$start]) {
                    $items[] = $this->maintenanceItem($period, $start, 'recurring:'.$period->id.':'.$start->timestamp, $labels, $icons);
                }
                if (count($items) > 200) {
                    usort($items, static fn (UpcomingItemData $a, UpcomingItemData $b): int => [$a->date, $a->key] <=> [$b->date, $b->key]);
                    $items = array_slice($items, 0, 100);
                }
            }
        }
        if ($onlyType !== 'maintenance') {
            /** @var Collection<int, \stdClass&object{id: int, site_id: int}> $checks */
            $checks = DB::table('checks')->whereIn('site_id', $sites->keys())->where('enabled', true)->get(['id', 'site_id']);
            /** @var list<int> $checkIds */
            $checkIds = $checks->pluck('id')->all();
            $siteByCheck = $checks->pluck('site_id', 'id');
            foreach (app()->tagged(UpcomingEventSource::class) as $source) {
                if (! $source instanceof UpcomingEventSource) {
                    throw new \LogicException('Upcoming source must implement its contract.');
                }
                foreach ($source->read($checkIds, $now, $through) as $occurrence) {
                    if ($onlyType !== null && $onlyType !== $occurrence->type) {
                        continue;
                    }
                    $siteId = $siteByCheck[$occurrence->checkId] ?? throw new \LogicException('Upcoming occurrence must belong to a visible check.');
                    $site = $sites[$siteId] ?? throw new \LogicException('Upcoming occurrence site must be visible.');
                    $items[] = new UpcomingItemData($occurrence->key, $occurrence->at->utc()->toIso8601String(), $occurrence->type, $this->name($site), $site->client_name,
                        $occurrence->detail, route('sites.checks.show', ['site' => $site->id, 'check' => $occurrence->checkId], false), icon_url: app(GenericSiteIcons::class)->url($site->id, $site->icon_hash), icon_backing: SiteIconBacking::from($site->icon_backing), client_id: $site->client_id);
                }
            }
        }
        usort($items, static fn (UpcomingItemData $a, UpcomingItemData $b): int => [$a->date, $a->key] <=> [$b->date, $b->key]);

        return array_slice($items, 0, 100);
    }

    /**
     * Cursor page merged from bounded source pages: each source returns its first limit+1 items after the cursor,
     * so the first `limit` items of the merge are exact and no source is truncated before sorting.
     *
     * @return array{items: list<array{at: CarbonImmutable, ends_at: CarbonImmutable|null, key: string, type: string, check_id: int|null, site_id: int|null, client_id: int|null, target_type: string|null, target_id: int|null, detail: string}>, has_more: bool}
     */
    public function page(User $user, CarbonImmutable $from, CarbonImmutable $until, ?int $clientId, ?string $onlyType, ?UpcomingPosition $after, int $limit): array
    {
        $candidates = [];
        $hasMore = false;
        if ($onlyType === null || $onlyType === 'maintenance') {
            $standalone = MaintenancePeriod::query();
            $this->maintenance->visibleTargets($standalone, $user, $clientId);
            $standalone->getQuery()->whereNull('recurring_maintenance_period_id')
                ->where('starts_at', '>=', $from->format('Y-m-d H:i:s.uP'))->where('starts_at', '<=', $until->format('Y-m-d H:i:s.uP'))
                ->when($after instanceof UpcomingPosition, static fn (Builder $query): Builder => $query->whereRaw(
                    '(starts_at, (\'maintenance:\' || id::text) COLLATE "C") > (?::timestamptz, ? COLLATE "C")', [$after?->at->format('Y-m-d H:i:s.uP'), $after?->key]))
                ->orderBy('starts_at')->orderByRaw('(\'maintenance:\' || id::text) COLLATE "C"')->limit($limit + 1);
            foreach ($standalone->get() as $period) {
                $candidates[] = $this->maintenanceOccurrence($period, $period->starts_at, $period->ends_at, 'maintenance:'.$period->id);
            }
            $recurring = RecurringMaintenancePeriod::query();
            $this->maintenance->visibleTargets($recurring, $user, $clientId);
            foreach ($recurring->lazyById(100) as $period) {
                // Expansion is bounded by the requested range; only the first limit+1 per series can reach the page.
                $taken = 0;
                foreach ($this->recurringDates($period, $from, $until) as [$start, $end]) {
                    $item = $this->maintenanceOccurrence($period, $start, $end, 'recurring:'.$period->id.':'.$start->timestamp);
                    if ($after instanceof UpcomingPosition && ! $after->before(new UpcomingOccurrence(0, $item['key'], $item['at'], 'maintenance', ''))) {
                        continue;
                    }
                    $candidates[] = $item;
                    if (++$taken > $limit) {
                        break;
                    }
                }
            }
        }
        if ($onlyType !== 'maintenance') {
            $sites = DB::table('sites')->whereIntegerInRaw('id', $this->sites->visibleSiteIds($user))->whereNull('paused_at')->whereNull('marked_for_deletion_at')
                ->when($clientId !== null, static fn (Builder $query): Builder => $query->where('client_id', $clientId))->pluck('client_id', 'id');
            $checks = DB::table('checks')->whereIntegerInRaw('site_id', $sites->keys()->all())->where('enabled', true)->pluck('site_id', 'id');
            /** @var list<int> $checkIds */
            $checkIds = $checks->keys()->all();
            foreach (app()->tagged(UpcomingEventSource::class) as $source) {
                if (! $source instanceof UpcomingEventSource) {
                    throw new \LogicException('Upcoming source must implement its contract.');
                }
                $page = $source->page($checkIds, $from, $until, $after, $limit + 1);
                $participates = false;
                foreach ($page->occurrences as $occurrence) {
                    if ($onlyType !== null && $onlyType !== $occurrence->type) {
                        continue;
                    }
                    $participates = true;
                    $siteId = $checks[$occurrence->checkId] ?? throw new \LogicException('Upcoming occurrence must belong to a visible check.');
                    $candidates[] = ['at' => $occurrence->at->utc(), 'ends_at' => null, 'key' => $occurrence->key, 'type' => $occurrence->type, 'check_id' => $occurrence->checkId,
                        'site_id' => $this->integer($siteId), 'client_id' => $this->integer($sites[$siteId]), 'target_type' => null, 'target_id' => null, 'detail' => $occurrence->detail];
                }
                // Only a source that contributed to this type filter can continue it.
                $hasMore = $hasMore || ($page->hasMore && $participates);
            }
        }
        usort($candidates, static fn (array $a, array $b): int => [$a['at']->format('Y-m-d H:i:s.u'), $a['key']] <=> [$b['at']->format('Y-m-d H:i:s.u'), $b['key']]);

        return ['items' => array_slice($candidates, 0, $limit), 'has_more' => $hasMore || count($candidates) > $limit];
    }

    private function integer(mixed $value): int
    {
        return is_int($value) ? $value : throw new \LogicException('Expected an integer identifier.');
    }

    /** @return array{at: CarbonImmutable, ends_at: CarbonImmutable, key: string, type: string, check_id: null, site_id: int|null, client_id: int|null, target_type: string, target_id: int, detail: string} */
    private function maintenanceOccurrence(MaintenancePeriod|RecurringMaintenancePeriod $period, CarbonImmutable $start, CarbonImmutable $end, string $key): array
    {
        $targetId = $period->target_id ?? $period->site_id ?? throw new \LogicException('Maintenance target missing.');

        return ['at' => $start->utc(), 'ends_at' => $end->utc(), 'key' => $key, 'type' => 'maintenance', 'check_id' => null, 'site_id' => $period->target_type === 'site' ? $targetId : null,
            'client_id' => $period->target_type === 'client' ? $targetId : null, 'target_type' => $period->target_type, 'target_id' => $targetId, 'detail' => $period->name ?? $period->reason ?? ''];
    }

    /** @param array<string, array{label: string, client_name: string|null}> $labels
     * @param  array<int, array{url: string|null, backing: SiteIconBacking}>  $icons
     */
    private function maintenanceItem(MaintenancePeriod|RecurringMaintenancePeriod $period, CarbonImmutable $start, string $key, array $labels, array $icons): UpcomingItemData
    {
        $id = $period->target_id ?? $period->site_id ?? throw new \LogicException('Maintenance target missing.');
        $label = $labels[$period->target_type.':'.$id] ?? null;
        $count = $period->getAttribute('target_site_count');
        if (! is_int($count)) {
            throw new \LogicException('Maintenance site count must be an integer.');
        }

        return new UpcomingItemData($key, $start->utc()->toIso8601String(), 'maintenance',
            $period->target_type === 'site' ? $label['label'] ?? null : null, $label['client_name'] ?? null,
            $period->name ?? $period->reason ?? '', $period->target_type === 'site' ? route('sites.show', $id, false) : route('maintenance.index', [], false),
            $period->target_type, $id, $label['label'] ?? null, $count, $period->target_type === 'site' ? ($icons[$id]['url'] ?? null) : null, $period->target_type === 'site' ? ($icons[$id]['backing'] ?? SiteIconBacking::None) : SiteIconBacking::None);
    }

    /** @param SiteRow $site */
    private function name(object $site): string
    {
        if ($site->friendly_name !== null && $site->friendly_name !== '') {
            return $site->friendly_name;
        }
        $host = parse_url($site->url, PHP_URL_HOST);

        return is_string($host) ? $host : $site->url;
    }
}
