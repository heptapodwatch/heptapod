<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Models\Check;
use Heptapod\Models\Incident;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Queries\IncidentsReader;
use Illuminate\Support\Facades\Gate;

/**
 * Authorizes a write against the state it writes into. An authorization read before the locks of the write can go stale
 * while the write waits: a site moves to another client, its client is archived, the actor loses access. So the write runs
 * in one transaction that first takes the client key of every subject's site (MoveSiteToClient and ArchiveClient hold the
 * client FOR UPDATE), then the actor's key (member access changes and deactivation hold the user FOR UPDATE), reads the
 * sites and the actor afresh, asks the policy and only then calls $write, which takes its own NotificationLocks levels
 * after these.
 * A site that is gone or no longer visible refuses as the policy does (404). An unlocked check a caller makes first (to
 * refuse before validating input, or a replay) is only an early answer; this one guards the write.
 */
final readonly class AuthorizeSiteWrite
{
    public function __construct(private IncidentsReader $incidents) {}

    /**
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $write
     * @return TReturn
     */
    public function __invoke(User $actor, string $ability, \Closure $write, Site|Check ...$subjects): mixed
    {
        return NotificationLocks::transaction(function () use ($actor, $ability, $write, $subjects): mixed {
            $siteIds = array_values(array_unique(array_map(static fn (Site|Check $subject): int => $subject instanceof Site ? $subject->id : $subject->site_id, $subjects)));
            $lockedClientIds = [];
            do {
                // A site that moved before its client key was taken shows its new client on the next read.
                $sites = Site::query()->findOrFail($siteIds)->keyBy('id');
                $unlocked = $sites->pluck('client_id')->unique()->diff($lockedClientIds)->values()->all();
                NotificationLocks::clientKeys($unlocked);
                $lockedClientIds = [...$lockedClientIds, ...$unlocked];
            } while ($unlocked !== []);
            NotificationLocks::users([$actor->id]);
            $actor->refresh();
            foreach ($subjects as $subject) {
                Gate::forUser($actor)->authorize($ability, $subject instanceof Site ? $sites->get($subject->id) : $subject);
            }

            return $write();
        });
    }

    /**
     * A write on an incident the actor found in the incident lists ($visible, read before any lock): the incident's site
     * authorized as above, then the incident read again through the same visibility for $write.
     *
     * @param  \Closure(Incident): Incident  $write
     */
    public function incident(User $actor, Incident $visible, \Closure $write): Incident
    {
        $site = $visible->site ?? throw new \LogicException('An incident belongs to a site.');

        return $this($actor, 'view', fn (): Incident => $write($this->incidents->scoped($actor)->findOrFail($visible->id)), $site);
    }
}
