<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Enums\IncidentResolutionReason;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Models\Check;
use Heptapod\Models\Client;
use Heptapod\Models\Delivery;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Policies\SitePolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final readonly class MoveSiteToClient
{
    public function __construct(private SitePolicy $policy, private ResolveInactiveCheckIncidents $resolve) {}

    public function __invoke(User $user, Site $site, int $clientId): Site
    {
        return NotificationLocks::transaction(function () use ($user, $site, $clientId): Site {
            Gate::forUser($user)->authorize('move', $site);
            $this->policy->visibleClients($user, includeArchived: true)->findOrFail($clientId);
            // NotificationLocks: both clients (ArchiveClient takes the client before checks and site), the checks, the site,
            // then the open incidents and in one ascending pass the deliveries ended or rewritten below (lockWork).
            NotificationLocks::clients([$site->client_id, $clientId]);
            $lockedClients = Client::query()->whereKey([$site->client_id, $clientId])->get()->keyBy('id');
            /** @var list<int> $checkIds */
            $checkIds = DB::table('checks')->where('site_id', $site->id)->orderBy('id')->pluck('id')->all();
            NotificationLocks::checks([], exclusive: $checkIds);
            NotificationLocks::sites([$site->id], exclusive: true);
            $locked = Site::query()->findOrFail($site->id);
            if ($locked->client_id !== $site->client_id) {
                throw ValidationException::withMessages(['client_id' => __('sites.client_changed')]);
            }
            Gate::forUser($user)->authorize('move', $locked);
            $target = $lockedClients->get($clientId);
            abort_unless($target instanceof Client, 404);
            if ($target->archived_at !== null) {
                throw ValidationException::withMessages(['client_id' => __('sites.client_archived')]);
            }
            Gate::forUser($user)->authorize('create', [Site::class, $clientId]);
            if ($locked->client_id === $clientId) {
                return $locked;
            }
            if (self::blockers([$locked->id]) !== []) {
                throw ValidationException::withMessages(['client_id' => __('sites.client_alerts_pending')]);
            }
            $events = DB::table('events')->where('site_id', $locked->id);
            $previous = $lockedClients->get($locked->client_id) ?? throw new \LogicException('The source client must be locked.');
            if ($previous->avatar_site_id === $locked->id) {
                $previous->update(['avatar_site_id' => null]);
            }
            ResolveInactiveCheckIncidents::lockWork($checkIds, DB::table('deliveries')->select('id')->whereIn('event_id', (clone $events)->select('id')));
            // Open incidents end without a recovery; a check that still fails opens a new incident under the target client.
            $failing = Check::query();
            $failing->getQuery()->whereIn('id', DB::table('incidents')->whereIn('check_id', $checkIds)->whereNull('resolved_at')->select('check_id'))->orderBy('id');
            foreach ($failing->get() as $check) {
                ($this->resolve)($check, IncidentResolutionReason::MovedToClient, $user);
            }
            // The planner locks these same rows and rechecks recurrence after waiting for a move.
            Delivery::recurringDigest()->where('state', DeliveryState::Sent->value)->getQuery()->whereIn('event_id', (clone $events)->select('id'))
                ->update(['digest_recurrence_ended_at' => now()]);
            // Retained history must not revert to source-client access when the site is later deleted.
            DB::table('deliveries')->whereIn('event_id', (clone $events)->select('id'))->update(['client_id' => $clientId]);
            $events->update(['client_id' => $clientId]);
            $locked->update(['client_id' => $clientId, 'paused_by_archive_at' => null]);

            return $locked;
        });
    }

    /**
     * The sites that cannot change client right now: their alerts are not yet routed or still being sent.
     *
     * @param  list<int>  $siteIds
     * @return list<int>
     */
    public static function blockers(array $siteIds): array
    {
        /** @var list<int> $alertsPending */
        $alertsPending = [
            ...DB::table('events')->whereIn('site_id', $siteIds)->whereNull('notification_routed_at')->distinct()->pluck('site_id')->all(),
            ...DB::table('events')->whereIn('site_id', $siteIds)->whereIn('id', Delivery::awaitingDelivery()->getQuery()->select('event_id'))->distinct()->pluck('site_id')->all(),
        ];

        return array_values(array_unique($alertsPending));
    }
}
