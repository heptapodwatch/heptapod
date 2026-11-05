<?php

declare(strict_types=1);

namespace Heptapod\Events\Notifications;

use Carbon\CarbonImmutable;
use Heptapod\Enums\NotificationCategory;
use Heptapod\Enums\NotificationMode;
use Heptapod\Enums\RecipientKind;
use Heptapod\Models\Event;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\NotificationSchedule;
use Heptapod\Policies\SitePolicy;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Step 1 of routing (design §2.1): which channels hear about a site and category, and why the others do not.
 * Planning and the explanation share it. Bounded by the channel count; index-backed by (recipient_kind, client_id)
 * and the primary keys of the grid and the site mutes.
 */
final readonly class NotificationRecipients
{
    public function __construct(private NotificationTiming $timing, private SitePolicy $sites) {}

    /** @return list<array{channel: NotificationDestination, mode: NotificationMode, excluded: string|null}> */
    public function candidates(RoutingContext $context): array
    {
        $site = $context->site;
        $rows = DB::table('notification_destinations as d')
            ->leftJoin('notification_preferences as p', fn (JoinClause $join): JoinClause => $join->on('p.destination_id', '=', 'd.id')->where('p.category', $context->category->value))
            ->leftJoin('notification_site_mutes as m', fn (JoinClause $join): JoinClause => $join->on('m.destination_id', '=', 'd.id')->where('m.site_id', $site->id))
            ->whereNull('d.removed_at')
            ->where(fn (Builder $kinds): Builder => $kinds
                ->where(fn (Builder $team): Builder => $team->where('d.recipient_kind', RecipientKind::Team->value)
                    ->where(fn (Builder $scope): Builder => $scope->whereNull('d.scope_client_id')->orWhere('d.scope_client_id', $site->client_id)))
                ->orWhere(fn (Builder $client): Builder => $client->where('d.recipient_kind', RecipientKind::Client->value)->where('d.client_id', $site->client_id))
                ->orWhere('d.recipient_kind', RecipientKind::User->value))
            ->orderBy('d.id')->get(['d.id', 'p.mode', DB::raw('(m.site_id IS NOT NULL) AS muted')]);
        $channels = NotificationDestination::query()->with(['user', 'client'])->whereKey($rows->pluck('id')->all())->get()->keyBy('id');
        // One access query for every user channel instead of a policy call per user (SitePolicy::view in SQL).
        $userIds = $channels->where('recipient_kind', RecipientKind::User)->pluck('user_id')->filter()->values()->all();
        $viewers = [];
        if ($userIds !== []) {
            foreach ($this->sites->viewableUsers(DB::table('users as u')->select('u.id')->whereIn('u.id', $userIds), 'u', $site->client_id)->get() as $viewer) {
                $viewers[$this->int($viewer, 'id')] = true;
            }
        }
        $candidates = [];
        foreach ($rows as $row) {
            $channelId = is_int($row->id) ? $row->id : throw new \LogicException('Channel ids are integers.');
            if (! is_string($row->mode)) {
                throw new \LogicException('Notification grid row missing for channel '.$channelId.' and '.$context->category->value.'.');
            }
            $channel = $channels[$channelId] ?? throw new \LogicException('A routed channel disappeared while it was read.');
            $mode = NotificationMode::from($row->mode);
            $candidates[] = ['channel' => $channel, 'mode' => $mode, 'excluded' => match (true) {
                $mode === NotificationMode::Off => 'category_off',
                (bool) $row->muted => 'site_muted',
                $channel->needs_attention => 'needs_attention',
                ! $channel->enabled => 'paused',
                $channel->recipient_kind === RecipientKind::User && ($channel->user_id === null || ! isset($viewers[$channel->user_id])) => 'no_site_access',
                ! $channel->addressVerified() => 'unverified',
                default => null,
            }];
        }

        return $candidates;
    }

    /**
     * The shell's coverage flag: some active channel hears outages or failures for at least one of these sites.
     * User channels count for sites their user may see (SitePolicy::viewableUsers) when the address is verified.
     * Team and client channels are decided from three aggregate reads, each bounded by channels, clients or the mutes on these sites;
     * user channels by one EXISTS over their users and the visible clients, which hydrates no user x client pairs.
     *
     * @param  list<int>  $siteIds
     */
    public function coversAny(array $siteIds): bool
    {
        if ($siteIds === []) {
            return false;
        }
        $channels = [];
        foreach (DB::table('notification_destinations as d')
            ->join('notification_preferences as p', 'p.destination_id', '=', 'd.id')
            ->whereIn('p.category', [NotificationCategory::Outage->value, NotificationCategory::Failure->value])
            ->where('p.mode', '<>', NotificationMode::Off->value)
            ->whereNull('d.removed_at')->where('d.enabled', true)->where('d.needs_attention', false)
            ->distinct()->get(['d.id', 'd.recipient_kind', 'd.user_id', 'd.client_id', 'd.scope_client_id']) as $row) {
            $channels[$this->int($row, 'id')] = ['kind' => RecipientKind::from(is_string($row->recipient_kind) ? $row->recipient_kind : ''),
                'user' => $this->nullableInt($row, 'user_id'), 'client' => $this->nullableInt($row, 'client_id'),
                'scope' => $this->nullableInt($row, 'scope_client_id')];
        }
        if ($channels === []) {
            return false;
        }
        $visible = [];
        foreach (DB::table('sites')->whereIntegerInRaw('id', $siteIds)->groupBy('client_id')->selectRaw('client_id, count(*) AS sites')->get() as $client) {
            $visible[$this->int($client, 'client_id')] = $this->int($client, 'sites');
        }
        $muted = [];
        foreach (DB::table('notification_site_mutes as m')->join('sites as s', 's.id', '=', 'm.site_id')
            ->whereIntegerInRaw('m.site_id', $siteIds)->whereIntegerInRaw('m.destination_id', array_keys($channels))
            ->groupBy('m.destination_id', 's.client_id')->selectRaw('m.destination_id, s.client_id, count(*) AS sites')->get() as $mutes) {
            $muted[$this->int($mutes, 'destination_id')][$this->int($mutes, 'client_id')] = $this->int($mutes, 'sites');
        }
        foreach ($channels as $id => $channel) {
            $clients = match ($channel['kind']) {
                RecipientKind::Team => $channel['scope'] === null ? array_keys($visible) : [$channel['scope']],
                RecipientKind::Client => $channel['client'] === null ? [] : [$channel['client']],
                RecipientKind::User => [],
            };
            foreach ($clients as $clientId) {
                if (($visible[$clientId] ?? 0) > ($muted[$id][$clientId] ?? 0)) {
                    return true;
                }
            }
        }
        $userIds = array_values(array_filter(array_column($channels, 'user'), is_int(...)));
        if ($userIds === []) {
            return false;
        }
        // A user channel covers a visible client its user may see (the policy's own SQL) if one of its sites here is not muted.
        $reachable = DB::table('notification_destinations as d')->join('users as u', 'u.id', '=', 'd.user_id')->crossJoin('clients as c')
            ->whereIntegerInRaw('d.id', array_keys($channels))->whereIntegerInRaw('u.id', $userIds)->whereIntegerInRaw('c.id', array_keys($visible))
            ->where(fn (Builder $address): Builder => $address->where('d.account_mail', false)->orWhereNotNull('u.email_verified_at'))
            ->whereExists(fn (Builder $sites): Builder => $sites->selectRaw('1')->from('sites as s')->whereColumn('s.client_id', 'c.id')
                ->whereIntegerInRaw('s.id', $siteIds)->whereNotExists(fn (Builder $mute): Builder => $mute->selectRaw('1')
                ->from('notification_site_mutes as m')->whereColumn('m.destination_id', 'd.id')->whereColumn('m.site_id', 's.id')));

        return $this->sites->viewableUsers($reachable, 'u', DB::raw('c.id'))->exists();
    }

    private function int(object $row, string $column): int
    {
        $value = ((array) $row)[$column] ?? null;

        return is_int($value) ? $value : throw new \LogicException('Notification column '.$column.' must be an integer.');
    }

    private function nullableInt(object $row, string $column): ?int
    {
        return ((array) $row)[$column] === null ? null : $this->int($row, $column);
    }

    /**
     * Steps 1 and 2 for the eligible channels of a real event, keyed by channel id.
     *
     * @return array<int, array{channel: NotificationDestination, due: CarbonImmutable, digest: bool, reason: string}>
     */
    public function routes(Event $event, CarbonImmutable $at): array
    {
        $context = RoutingContext::fromEvent($event);
        if (! $context instanceof RoutingContext) {
            return [];
        }
        $eligible = array_values(array_filter($this->candidates($context), static fn (array $candidate): bool => $candidate['excluded'] === null));
        $schedules = NotificationSchedule::forChannels(array_column($eligible, 'channel'));
        $routes = [];
        foreach ($eligible as $candidate) {
            $channel = $candidate['channel'];
            $schedule = $this->timing->schedule($context, $schedules[$channel->id], $at, $candidate['mode'] === NotificationMode::Digest);
            $routes[$channel->id] = ['channel' => $channel, ...$schedule];
        }

        return $routes;
    }
}
