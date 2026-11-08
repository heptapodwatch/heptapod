<?php

declare(strict_types=1);

namespace Heptapod\Queries;

use Carbon\CarbonImmutable;
use Heptapod\Data\Ui\MaintenanceSiteHeaderData;
use Heptapod\Data\Ui\MaintenanceWindowData;
use Heptapod\Maintenance\MaintenanceTargets;
use Heptapod\Models\Client;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Models\RecurringMaintenancePeriod;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Policies\SitePolicy;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Spatie\Tags\Tag;

final readonly class MaintenanceReader
{
    public function __construct(private SitePolicy $policy, private MaintenanceTargets $targets) {}

    /** @return array{windows: list<MaintenanceWindowData>, next_page: int|null, previous_page: int|null, total: int, site_name: string|null} */
    public function index(User $user, int $page = 1, ?int $siteId = null, ?int $clientId = null, string $state = 'all'): array
    {
        abort_unless($user->hasAnyRole(['Owner', 'Admin', 'Member']), 403);
        $query = $siteId === null ? MaintenancePeriod::query() : MaintenancePeriod::targetingSite($siteId);
        $siteName = null;
        $now = CarbonImmutable::now('UTC');
        if ($siteId !== null) {
            $site = Site::query()->findOrFail($siteId);
            abort_unless($this->policy->view($user, $site)->allowed(), 404);
            $siteName = $site->displayName();
            $query->getQuery()->leftJoin('maintenance_site_exclusions as site_exit', static fn (JoinClause $join): JoinClause => $join
                ->on('site_exit.maintenance_period_id', '=', 'maintenance_periods.id')->where('site_exit.site_id', $siteId))
                ->select('maintenance_periods.*', 'site_exit.left_at as site_ended_at');
        }
        $query->with('recurrence');
        $this->visibleTargets($query, $user, $clientId);
        $timestamp = $now->format('Y-m-d H:i:s.uP');
        $effectiveEnd = $siteId === null ? 'maintenance_periods.ends_at'
            : 'least(coalesce(site_exit.left_at, maintenance_periods.ends_at), maintenance_periods.ends_at)';
        if ($state === 'planned') {
            $query->getQuery()->where('maintenance_periods.starts_at', '>', $timestamp);
        } elseif ($state === 'active' || $state === 'done') {
            $query->getQuery()->where('maintenance_periods.starts_at', '<=', $timestamp)
                ->whereRaw($effectiveEnd.($state === 'active' ? ' > ?' : ' <= ?'), [$timestamp]);
        }
        $query->getQuery()->orderByDesc('maintenance_periods.starts_at')->orderByDesc('maintenance_periods.id');
        $windows = $query->paginate(50, ['*'], 'page', $page);
        if ($page > $windows->lastPage()) {
            $page = $windows->lastPage();
            $windows = $query->paginate(50, ['*'], 'page', $page);
        }
        $byType = $windows->getCollection()->groupBy('target_type');
        $sites = Site::query();
        $sites->getQuery()->whereIn('id', $byType->get('site', collect())->map(static function (mixed $window): int {
            if (! $window instanceof MaintenancePeriod) {
                throw new \LogicException('Maintenance page contains an invalid window.');
            }

            return $window->target_id ?? $window->site_id ?? throw new \LogicException('Site target is missing.');
        })->all());
        $siteLabels = $sites->get()->mapWithKeys(static fn (Site $site): array => [$site->id => $site->displayName()]);
        $clients = Client::query();
        $clients->getQuery()->whereIn('id', $byType->get('client', collect())->pluck('target_id')->all());
        $clients->getQuery()->select('clients.id')->selectRaw(Client::displayNameSql().' AS display_name', [__('No client')]);
        $clientLabels = $clients->pluck('display_name', 'id');
        $tags = Tag::query();
        $tags->getQuery()->whereIn('id', $byType->get('tag', collect())->pluck('target_id')->all());
        $tagLabels = $tags->get()->mapWithKeys(static fn (Tag $tag): array => [$tag->id => $tag->name]);
        $rows = [];
        foreach ($windows as $window) {
            $siteEndAt = $siteId === null ? null : $window->getAttribute('site_ended_at');
            if ($siteEndAt !== null && ! is_string($siteEndAt)) {
                throw new \LogicException('Site-specific maintenance end must be a timestamp.');
            }
            $siteEnd = $siteEndAt === null ? null : CarbonImmutable::parse($siteEndAt);
            $endsAt = $siteEnd instanceof CarbonImmutable && $siteEnd->lessThan($window->ends_at) ? $siteEnd : $window->ends_at;
            $state = $window->starts_at->greaterThan($now) ? 'planned' : ($endsAt->greaterThan($now) ? 'active' : 'done');
            $recurrence = $window->recurrence;
            $actions = $user->hasAnyRole(['Owner', 'Admin']) ? match ($state) {
                'active' => ['end_now', 'update', 'delete', ...($recurrence === null ? [] : ['cancel_series'])],
                'planned' => ['update', 'cancel_occurrence', ...($recurrence === null ? [] : ['cancel_series'])],
                default => [],
            } : [];
            $id = $window->target_id ?? $window->site_id ?? throw new \LogicException('Maintenance target is missing.');
            if ($id < 1) {
                throw new \LogicException('Maintenance target ID must be positive.');
            }
            $label = match ($window->target_type) {
                'site' => $siteLabels->get($id), 'client' => $clientLabels->get($id), 'tag' => $tagLabels->get($id),
                default => throw new \LogicException('Unknown maintenance target type.'),
            };
            if ($label !== null && ! is_string($label)) {
                throw new \LogicException('Maintenance target label must be text.');
            }
            $members = $this->targets->sites($window->target_type, $id);
            $members->getQuery()->whereNotIn('sites.id', DB::table('maintenance_site_exclusions')->select('site_id')
                ->where('maintenance_period_id', $window->id)->where('left_at', '<=', $now->format('Y-m-d H:i:s.uP')));
            $rows[] = new MaintenanceWindowData($window->id, $window->recurring_maintenance_period_id, $state,
                $window->target_type, $id, $label, $members->getQuery()->count(),
                $window->name, $window->reason, $window->starts_at->toIso8601String(), $endsAt->toIso8601String(),
                $window->timezone ?? ($recurrence === null ? 'UTC' : $recurrence->timezone), $recurrence?->recurrence_type,
                $recurrence === null ? 1 : $recurrence->interval, $recurrence?->nth_weekday, $recurrence?->day_of_week,
                $window->mute_alerts, $window->catch_up_at_end, $actions,
                $recurrence?->days_of_week, $recurrence?->day_of_month, $recurrence?->start_time, $recurrence?->end_time,
                $recurrence?->anchor_date?->format('Y-m-d'), $recurrence?->timezone);
        }

        return ['windows' => $rows, 'next_page' => $windows->hasMorePages() ? $page + 1 : null,
            'previous_page' => $page > 1 ? $page - 1 : null, 'total' => $windows->total(), 'site_name' => $siteName];
    }

    public function siteHeader(User $user, Site $site): ?MaintenanceSiteHeaderData
    {
        abort_unless($this->policy->view($user, $site)->allowed(), 404);
        $now = CarbonImmutable::now('UTC');
        $timestamp = $now->format('Y-m-d H:i:s.uP');
        if ($user->isRestrictedMember()) {
            $active = MaintenancePeriod::activeForSite($site->id, $now);
            $active->getQuery()->where('starts_at', '<=', $timestamp)->where('target_type', 'tag');
            if ($active->getQuery()->exists()) {
                $muted = MaintenancePeriod::activeForSite($site->id, $now);
                $muted->getQuery()->where('mute_alerts', true);

                return new MaintenanceSiteHeaderData(null, 'active', null, null, null, null, $muted->getQuery()->exists());
            }
        }
        $query = MaintenancePeriod::forSite($site->id);
        $this->limitToReach($query, $user);
        $query->getQuery()->where('ends_at', '>', $timestamp)->orderBy('starts_at')->orderBy('id');
        $window = $query->first();
        if ($window === null) {
            return null;
        }

        return new MaintenanceSiteHeaderData($window->id, $window->starts_at->lessThanOrEqualTo($now) ? 'active' : 'planned',
            $window->starts_at->toIso8601String(), $window->ends_at->toIso8601String(),
            $window->timezone ?? 'UTC', $window->reason, $window->mute_alerts);
    }

    /**
     * @template T of MaintenancePeriod|RecurringMaintenancePeriod
     *
     * @param  EloquentBuilder<T>  $query
     */
    public function visibleTargets(EloquentBuilder $query, User $user, ?int $clientId = null): void
    {
        $this->limitToReach($query, $user);
        if ($clientId === null) {
            return;
        }
        $table = $query->getModel() instanceof MaintenancePeriod ? 'maintenance_periods' : 'recurring_maintenance_periods';
        $this->targets->whereCoversClient($query->getQuery(), $table, $clientId);
    }

    /**
     * @template T of MaintenancePeriod|RecurringMaintenancePeriod
     *
     * @param  EloquentBuilder<T>  $query
     */
    private function limitToReach(EloquentBuilder $query, User $user): void
    {
        $constraint = $user->tokenClientConstraint();
        if (! $user->isRestrictedMember() && $constraint === null) {
            return;
        }
        // A client-constrained API token narrows reach exactly like a restricted membership.
        /** @var list<int> $allowed */
        $allowed = $user->isRestrictedMember() ? $user->clients()->toBase()->pluck('clients.id')->all() : [$constraint];
        if ($constraint !== null) {
            $allowed = in_array($constraint, $allowed, true) ? [$constraint] : [];
        }
        $table = $query->getModel() instanceof MaintenancePeriod ? 'maintenance_periods' : 'recurring_maintenance_periods';
        $this->targets->whereWithinClientReach($query->getQuery(), $table, $allowed);
    }
}
