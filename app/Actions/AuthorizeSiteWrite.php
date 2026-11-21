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
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Authorizes a write against the state it writes into. An authorization read before the locks of the write can go stale
 * while the write waits: a site moves to another client or is deleted, its client is archived, the actor or the token
 * loses access. So the write runs in one transaction that first takes the client keys of the subjects' sites
 * (NotificationLocks::siteClients: moving, archiving and deleting hold the client FOR UPDATE), then the actor's key (member
 * access changes and deactivation hold the user FOR UPDATE) and the API token's key (revoking deletes it), reads the sites
 * and the actor afresh, asks the policy and only then calls $write, which takes its own NotificationLocks levels after
 * these. A site that is gone or no longer visible refuses as the policy does (404), a revoked token answers 401. An
 * unlocked check a caller makes first (to refuse before validating input) is only an early answer; this one guards the
 * write. Callers run it as their outermost transaction.
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
        return $this->locked(false, $actor, $ability, $write, $subjects);
    }

    /**
     * As __invoke, with the clients FOR UPDATE: for a site deletion, authorized and run under that one fence
     * (DeleteSite::underClientLock), so the transaction never upgrades a key it holds.
     *
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $write
     * @return TReturn
     */
    public function exclusively(User $actor, string $ability, \Closure $write, Site ...$subjects): mixed
    {
        return $this->locked(true, $actor, $ability, $write, $subjects);
    }

    /**
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $write
     * @param  array<Site|Check|Incident>  $subjects
     * @return TReturn
     */
    private function locked(bool $exclusive, User $actor, string $ability, \Closure $write, array $subjects): mixed
    {
        $siteIds = array_values(array_unique(array_map(static fn (Site|Check|Incident $subject): int => $subject instanceof Site ? $subject->id : $subject->site_id, $subjects)));

        $authorize = static function (Collection $sites) use ($actor, $ability, $subjects): void {
            foreach ($subjects as $subject) {
                Gate::forUser($actor)->authorize($ability, $subject instanceof Check ? $subject : $sites->get($subject instanceof Site ? $subject->id : $subject->site_id));
            }
        };

        return NotificationLocks::siteClients($siteIds, $exclusive, function (Collection $sites) use ($actor, $write, $authorize): mixed {
            $this->lockActor($actor);
            $authorize($sites);

            return $write();
        }, $authorize);
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
     * under the actor's key.
     *
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $write
     * @return TReturn
     */
    public function asManager(User $actor, \Closure $write): mixed
    {
        return NotificationLocks::transaction(function () use ($actor, $write): mixed {
            $this->lockActor($actor);
            abort_unless($actor->disabled_at === null && $actor->hasAnyRole(['Owner', 'Admin']), 403);

            return $write();
        });
    }

    private function lockActor(User $actor): void
    {
        NotificationLocks::users([$actor->id]);
        // A web session has no token (null) and a first-party SPA request a transient one.
        $token = $actor->currentAccessToken();
        if ($token instanceof PersonalAccessToken && ! NotificationLocks::apiTokenKey($token->id)) { // @phpstan-ignore instanceof.alwaysTrue
            throw new AuthenticationException;
        }
        $actor->refresh();
    }
}
