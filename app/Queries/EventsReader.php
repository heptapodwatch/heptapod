<?php

declare(strict_types=1);

namespace Heptapod\Queries;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckResult;
use Heptapod\Data\Ui\ActivityGroupData;
use Heptapod\Data\Ui\EventData;
use Heptapod\Events\EventPresenter;
use Heptapod\Models\CheckRun;
use Heptapod\Models\Client;
use Heptapod\Models\Event;
use Heptapod\Models\User;
use Heptapod\Policies\SitePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** @phpstan-type ActivityGroupRow \stdClass&object{id: int, latest_id: int, check_id: int|null, event_count: int, started_at: string, ended_at: string, first_state: string|null, second_state: string|null, current_state: string|null} */
final readonly class EventsReader
{
    public function __construct(private SitePolicy $policy, private EventPresenter $presenter) {}

    /** @return list<EventData> */
    public function read(User $user, ?int $siteId = null, ?int $checkId = null, ?int $incidentId = null, ?CarbonImmutable $since = null, int $limit = 20, int $offset = 0, ?string $search = null, ?int $clientId = null, ?string $type = null): array
    {
        $query = $this->filtered($user, $siteId, $checkId, $incidentId, $since, $search, $clientId, $type);
        $query->getQuery()->orderByDesc('occurred_at')->orderByDesc('id')->limit($limit)->offset($offset);

        return $this->present($query->get(), $incidentId !== null);
    }

    /** @param Collection<int, Event> $events
     * @return list<EventData>
     */
    public function present(Collection $events, bool $describeResultChange = false): array
    {
        $references = [];
        foreach ($events as $event) {
            if (is_int($event->payload['run_id'] ?? null) && is_string($event->payload['run_ended_at'] ?? null)) {
                $references[] = [$event->payload['run_id'], $event->payload['run_ended_at'], $event->check_id];
            }
        }
        $runs = CheckRun::query()->where(function (Builder $query) use ($references): void {
            $query->getQuery()->whereRaw('false');
            foreach ($references as [$id, $endedAt, $checkId]) {
                $query->orWhere(function (Builder $query) use ($id, $endedAt, $checkId): void {
                    $query->getQuery()->where('id', $id)->where('ended_at', CarbonImmutable::parse($endedAt)->format('Y-m-d H:i:s.uP'))->where('check_id', $checkId);
                });
            }
        })->get();
        $rows = [];
        foreach ($events as $event) {
            $run = $runs->first(fn (CheckRun $run): bool => $run->id === ($event->payload['run_id'] ?? null)
                && $run->check_id === $event->check_id && $run->ended_at->format('Y-m-d\TH:i:s.uP') === ($event->payload['run_ended_at'] ?? null));
            $rows[] = $this->presenter->present($event, $run, describeResultChange: $describeResultChange);
        }

        return $rows;
    }

    /** Persisted group identity avoids rebuilding the alternating sequence on each read. */
    public function activityGroups(User $user, CarbonImmutable $since, ?string $search, ?int $clientId, ?string $type, ?int $groupId = null, ?Event $cursor = null, bool $newer = false, ?int $limit = null): QueryBuilder
    {
        $base = $this->filtered($user, null, null, null, $since, $search, $clientId, $type)->getQuery()
            ->select(['events.id', 'events.site_id', 'events.check_id', 'events.flap_group_id', 'events.from_result', 'events.to_result', 'events.occurred_at']);
        $heads = DB::table('event_flap_groups as projection')
            ->joinSub(clone $base, 'latest', 'latest.id', '=', 'projection.latest_event_id')
            ->whereNotNull('latest.site_id')->whereNotNull('latest.check_id')->where('projection.last_at', '>', $since);
        if ($groupId !== null) {
            $heads->where('projection.id', $groupId);
        }
        $heads = $this->limitActivityHeads($heads, 'projection.last_at', 'projection.latest_event_id', $cursor, $newer, $groupId === null ? $limit : 1);
        $current = $this->activityHeadColumns($heads)
            ->orderByDesc('ended_at')->orderByDesc('latest_id');
        $deletedSites = (clone $base)->whereNull('events.site_id');
        $deletedChecks = (clone $base)->whereNotNull('events.site_id')->whereNull('events.check_id');
        $siteAudit = $this->activityAuditHeads($deletedSites, $groupId, $cursor, $newer, $limit);
        $checkAudit = $this->activityAuditHeads($deletedChecks, $groupId, $cursor, $newer, $limit);

        $selected = DB::query()->fromSub($current->unionAll($siteAudit)->unionAll($checkAudit), 'heads')
            ->orderBy('ended_at', $newer ? 'asc' : 'desc')->orderBy('latest_id', $newer ? 'asc' : 'desc');
        if ($groupId !== null || $limit !== null) {
            $selected->limit($groupId !== null ? 1 : $limit);
        }

        // Count only the globally selected heads, not 21 heads independently in every branch.
        return $this->activityHeadStats(DB::query()->fromSub($selected, 'latest'), $base);
    }

    private function activityAuditHeads(QueryBuilder $audited, ?int $groupId, ?Event $cursor, bool $newer, ?int $limit): QueryBuilder
    {
        if ($groupId !== null) {
            $audited->where(static function (QueryBuilder $members) use ($groupId): void {
                $members->where('events.flap_group_id', $groupId)->orWhere('events.id', $groupId);
            });
        }
        // Probe events directly by group and tuple, not a derived scan of all retained audit rows.
        $newerProbe = (clone $audited)->select('events.id')
            ->whereRaw('events.flap_group_id = coalesce(latest.flap_group_id, latest.id)')
            ->whereRaw('(events.occurred_at, events.id) > (latest.occurred_at, latest.id)')
            ->orderByDesc('events.occurred_at')->orderByDesc('events.id')->limit(1);
        $heads = DB::query()->fromSub($audited, 'latest')->whereNotExists($newerProbe);

        $heads = $this->limitActivityHeads($heads, 'latest.occurred_at', 'latest.id', $cursor, $newer, $groupId === null ? $limit : 1);

        return $this->activityHeadColumns($heads)
            ->orderByDesc('ended_at')->orderByDesc('latest_id');
    }

    /** @param literal-string $endedAt
     * @param  literal-string  $eventId
     */
    private function limitActivityHeads(QueryBuilder $heads, string $endedAt, string $eventId, ?Event $cursor, bool $newer, ?int $limit): QueryBuilder
    {
        $heads->select('latest.*')->selectRaw($endedAt.' AS head_at');
        if ($cursor instanceof Event) {
            $heads->whereRaw('('.$endedAt.', '.$eventId.') '.($newer ? '>' : '<').' (?, ?)',
                [$cursor->occurred_at->format('Y-m-d H:i:s.uP'), $cursor->id]);
        }
        $heads->orderBy($endedAt, $newer ? 'asc' : 'desc')->orderBy($eventId, $newer ? 'asc' : 'desc');
        if ($limit !== null) {
            $heads->limit($limit);
        }

        return DB::query()->fromSub($heads, 'latest');
    }

    private function activityHeadColumns(QueryBuilder $heads): QueryBuilder
    {
        return $heads->selectRaw('coalesce(latest.flap_group_id, latest.id) AS id, latest.id AS latest_id,
            latest.check_id, latest.head_at AS ended_at, latest.to_result AS current_state');
    }

    private function activityHeadStats(QueryBuilder $heads, QueryBuilder $base): QueryBuilder
    {
        $members = DB::query()->fromSub(clone $base, 'member')
            ->whereRaw('(member.flap_group_id = latest.id OR member.id = latest.id)')
            ->selectRaw('count(*)::int AS event_count, min(member.occurred_at) AS started_at,
                min(member.from_result) AS first_state, max(member.to_result) AS second_state');

        return $heads->joinLateral($members, 'stats')
            ->selectRaw('latest.id, latest.latest_id, latest.check_id, latest.ended_at, latest.current_state,
                stats.event_count, stats.started_at, stats.first_state, stats.second_state');
    }

    public function activityCursor(User $user, int $id, CarbonImmutable $since, ?string $search, ?int $clientId, ?string $type): Event
    {
        $query = $this->filtered($user, null, null, null, $since, $search, $clientId, $type);
        $query->getQuery()->where('events.id', $id);

        return $query->firstOrFail();
    }

    /** @param list<int> $eventIds
     * @return EloquentCollection<int, Event>
     */
    public function activityHeadEvents(array $eventIds): EloquentCollection
    {
        return Event::query()->with(['site.client', 'client', 'check', 'incident'])->whereKey($eventIds)->get()->keyBy('id');
    }

    /** @return array{events: list<ActivityGroupData>, older: int|null, newer: int|null} */
    public function readActivity(QueryBuilder $groups, ?Event $cursor = null, bool $newer = false): array
    {
        $query = DB::query()->fromSub($groups, 'groups');
        if ($cursor instanceof Event) {
            $query->whereRowValues(['ended_at', 'latest_id'], $newer ? '>' : '<', [$cursor->occurred_at->format('Y-m-d H:i:s.uP'), $cursor->id]);
        }
        /** @var Collection<int, ActivityGroupRow> $fetched */
        $fetched = $query->orderBy('ended_at', $newer ? 'asc' : 'desc')->orderBy('latest_id', $newer ? 'asc' : 'desc')->limit(21)->get();
        $more = $fetched->count() > 20;
        $rows = $fetched->take(20);
        if ($newer) {
            $rows = $rows->reverse()->values();
        }
        $events = $this->present($this->activityHeadEvents(array_values($rows->map(static fn (object $row): int => $row->latest_id)->all()))->values());
        $byId = [];
        foreach ($events as $event) {
            $byId[$event->id] = $event;
        }
        $result = [];
        foreach ($rows as $row) {
            $result[] = new ActivityGroupData($row->id, $byId[$row->latest_id], $row->event_count,
                CarbonImmutable::parse($row->started_at)->toIso8601String(), CarbonImmutable::parse($row->ended_at)->toIso8601String(),
                $row->first_state === null ? null : CheckResult::from($row->first_state),
                $row->second_state === null ? null : CheckResult::from($row->second_state),
                $row->current_state === null ? null : CheckResult::from($row->current_state));
        }

        return ['events' => $result,
            'older' => ($newer ? $cursor instanceof Event : $more) ? $rows->last()?->latest_id : null,
            'newer' => ($newer ? $more : $cursor instanceof Event) ? $rows->first()?->latest_id : null];
    }

    /** @return list<EventData> */
    public function activityDetails(User $user, int $id, int $page, CarbonImmutable $since, ?string $search, ?int $clientId, ?string $type): array
    {
        /** @var ActivityGroupRow|null $group */
        $group = $this->activityGroups($user, $since, $search, $clientId, $type, $id)
            ->orderByDesc('ended_at')->orderByDesc('latest_id')->limit(1)->first();
        abort_if($group === null, 404);
        $query = $this->filtered($user, null, $group->check_id, null, $since, $search, $clientId, $type);
        $query->getQuery()->where(static function (QueryBuilder $members) use ($id): void {
            $members->where('flap_group_id', $id)->orWhere('id', $id);
        })->orderByDesc('occurred_at')->orderByDesc('id')->limit(20)->offset(($page - 1) * 20);

        return $this->present($query->get());
    }

    public function count(User $user, ?CarbonImmutable $since = null, ?string $search = null, ?int $clientId = null, ?string $type = null): int
    {
        return $this->filtered($user, null, null, null, $since, $search, $clientId, $type)->getQuery()->count();
    }

    /** @return Builder<Event> */
    private function filtered(User $user, ?int $siteId, ?int $checkId, ?int $incidentId, ?CarbonImmutable $since, ?string $search = null, ?int $clientId = null, ?string $type = null): Builder
    {
        $sites = $this->policy->visibleSites($user, includeArchived: $siteId !== null)->getQuery()->select('sites.id');
        if ($siteId !== null) {
            $sites->where('sites.id', $siteId);
        }
        $query = Event::feedVisible()->with(['site.client', 'client', 'check', 'incident']);
        if ($checkId !== null) {
            $query->getQuery()->where('check_id', $checkId);
        }
        if ($incidentId !== null) {
            $query->getQuery()->where('incident_id', $incidentId);
        }
        $clients = $this->policy->visibleClients($user)->getQuery()->select('clients.id');
        $query->where(function (Builder $scope) use ($sites, $clients, $siteId): void {
            $scope->getQuery()->whereIn('site_id', $sites);
            if ($siteId === null) {
                $scope->orWhere(function (Builder $deleted) use ($clients): void {
                    $deleted->getQuery()->whereNull('site_id')->whereIn('client_id', $clients);
                });
            }
        });
        if ($since instanceof CarbonImmutable) {
            $query->getQuery()->where('occurred_at', '>', $since);
        }
        if ($clientId !== null) {
            $query->where(function (Builder $match) use ($clientId): void {
                $match->whereHas('site', static fn (Builder $site): Builder => $site->where('client_id', $clientId))
                    ->orWhere(function (Builder $deleted) use ($clientId): void {
                        $deleted->getQuery()->whereNull('site_id')->where('client_id', $clientId);
                    });
            });
        }
        if ($type !== null) {
            $query->where(function (Builder $match) use ($type): void {
                $match->whereHas('check', static fn (Builder $check): Builder => $check->where('type', $type))
                    ->orWhere(function (Builder $deleted) use ($type): void {
                        $deleted->getQuery()->whereNull('check_id')->where('check_type', $type);
                    });
            });
        }
        if ($search !== null && $search !== '') {
            $pattern = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';
            $query->where(function (Builder $match) use ($pattern): void {
                $match->whereHas('site', static fn (Builder $site): Builder => $site->where(function (Builder $identity) use ($pattern): void {
                    $identity->where('url', 'ilike', $pattern)->orWhere('friendly_name', 'ilike', $pattern)
                        ->orWhereHas('client', static function (Builder $client) use ($pattern): void {
                            $client->getQuery()->whereRaw(Client::displayNameSql().' ILIKE ?', [__('No client'), $pattern]);
                        });
                }))->orWhere(function (Builder $deleted) use ($pattern): void {
                    $deleted->getQuery()->whereNull('site_id');
                    $deleted->where(function (Builder $identity) use ($pattern): void {
                        $identity->where('site_name', 'ilike', $pattern)->orWhere('site_url', 'ilike', $pattern)->orWhere('client_name', 'ilike', $pattern)
                            ->orWhereHas('client', static function (Builder $client) use ($pattern): void {
                                $client->getQuery()->where('is_internal', true)->whereRaw('? ILIKE ?', [__('No client'), $pattern]);
                            });
                    });
                });
            });
        }

        return $query;
    }
}
