<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Models\Check;
use Heptapod\Models\Incident;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Queries\IncidentsReader;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Authorizes a write against the state it writes into. An authorization read taken before the locks of the write can go stale
 * while the write waits: a site moves to another client or is deleted, its client is archived, the actor or the token
 * loses access. So the write runs in one transaction that first takes the access-structure lock shared (level 0 of
 * NotificationLocks; every such change holds it exclusive), then reads the sites, the actor and the API token afresh, asks
 * the policy and only then calls $write, which takes its own row locks after it. A site that is gone or no longer visible
 * refuses as the policy does (404), a revoked token answers 401, and a structural change that holds the lock too long
 * answers 409 (try again). An unlocked check a caller makes first (to refuse before validating input) is only an early
 * answer; this one guards the write. Callers run it as their outermost transaction.
 */
final readonly class AuthorizeSiteWrite
{
    public function __construct(private IncidentsReader $incidents) {}

    /**
     * $ability is asked of each Site and Check; an Incident is authorized through its site.
     *
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $write
     * @return TReturn
     */
    public function __invoke(User $actor, string $ability, \Closure $write, Site|Check|Incident ...$subjects): mixed
    {
        return NotificationLocks::transaction(function () use ($actor, $ability, $write, $subjects): mixed {
            NotificationLocks::accessShared();
            $this->authorize($actor, $ability, $subjects);

            return $write();
        });
    }

    /**
     * As __invoke, with the access-structure lock exclusive: a site deletion is itself a structural change
     * (DeleteSite), so the transaction takes the exclusive lock first and never upgrades a shared one.
     *
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $write
     * @return TReturn
     */
    public function exclusively(User $actor, string $ability, \Closure $write, Site ...$subjects): mixed
    {
        return NotificationLocks::transaction(function () use ($actor, $ability, $write, $subjects): mixed {
            NotificationLocks::accessExclusive();
            $this->authorize($actor, $ability, $subjects);

            return $write();
        });
    }

    /**
     * A write on an incident the actor found in the incident lists ($visible, read before any lock): the incident's site
     * authorized ('view') as above, then the incident read again through the same visibility for $write.
     *
     * @param  \Closure(Incident): Incident  $write
     */
    public function incident(User $actor, Incident $visible, \Closure $write): Incident
    {
        return $this($actor, 'view', fn (): Incident => $write($this->incidents->scoped($actor)->findOrFail($visible->id)), $visible);
    }

    /**
     * A write only Owners and Admins make whatever it targets (maintenance windows of a client or a tag): the role decided
     * under the access-structure lock.
     *
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $write
     * @return TReturn
     */
    public function asManager(User $actor, \Closure $write): mixed
    {
        return NotificationLocks::transaction(function () use ($actor, $write): mixed {
            NotificationLocks::accessShared();
            $this->refreshActor($actor);
            abort_unless($actor->disabled_at === null && $actor->hasAnyRole(['Owner', 'Admin']), 403);

            return $write();
        });
    }

    /** @param array<Site|Check|Incident> $subjects */
    private function authorize(User $actor, string $ability, array $subjects): void
    {
        $this->refreshActor($actor);
        $siteIds = array_values(array_unique(array_map(static fn (Site|Check|Incident $subject): int => $subject instanceof Site ? $subject->id : $subject->site_id, $subjects)));
        $sites = Site::query()->findOrFail($siteIds)->keyBy('id');
        foreach ($subjects as $subject) {
            Gate::forUser($actor)->authorize($ability, $subject instanceof Check ? $subject : $sites->get($subject instanceof Site ? $subject->id : $subject->site_id));
        }
    }

    private function refreshActor(User $actor): void
    {
        // A web session has no token (null) and a first-party SPA request a transient one. Revoking a token is a structural
        // change, so the token read here stays valid until commit.
        $token = $actor->currentAccessToken();
        if ($token instanceof PersonalAccessToken && ! PersonalAccessToken::query()->getQuery()->where('id', $token->id)->exists()) { // @phpstan-ignore instanceof.alwaysTrue
            throw new AuthenticationException;
        }
        $actor->refresh();
    }
}
