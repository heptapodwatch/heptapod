<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use DateTimeInterface;
use Heptapod\Contracts\ServersAccess;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final readonly class CreateApiToken
{
    public function __construct(private ServersAccess $servers) {}

    /** The abilities of the write and manage presets. */
    public const array HistoricalAbilities = ['identity:read', 'monitors:read', 'monitors:write', 'checks:read', 'checks:write', 'checks:snooze', 'incidents:read', 'incidents:note', 'uptime:read', 'downtime:read', 'tags:read', 'tags:write', 'maintenance:read', 'maintenance:write'];

    public const array Abilities = [...self::HistoricalAbilities, 'activity:read', 'upcoming:read', 'checks:run', 'clients:read', 'clients:write', ...self::ServerAbilities];

    /** The experimental servers addon's: Owners and Admins only, and offered only while servers are on (ServersAccess). */
    public const array ServerAbilities = ['servers:read', 'servers:snooze'];

    public const array ReadPreset = ['identity:read', 'monitors:read', 'checks:read', 'incidents:read', 'uptime:read', 'downtime:read', 'tags:read', 'clients:read', 'maintenance:read'];

    public const array AgentReadPreset = ['identity:read', 'monitors:read', 'checks:read', 'incidents:read', 'activity:read', 'upcoming:read'];

    /** Abilities only Owners and Admins can issue. */
    public const array AdminAbilities = ['monitors:write', 'checks:write', 'checks:run', 'maintenance:write', 'clients:write'];

    /** The UI starting point for CI deploys: open a window, request a run, and the reads to look up IDs and poll the receipt. */
    public const array DeployAutomation = ['monitors:read', 'checks:read', 'checks:run', 'maintenance:read', 'maintenance:write'];

    /**
     * Frozen API preset aliases of the settings endpoint; never append abilities here.
     *
     * @return array{read: list<string>, write: list<string>, manage: list<string>, agent-read: list<string>}
     */
    public function presets(User $user): array
    {
        $allowed = $this->allowedAbilities($user);
        $historical = array_values(array_intersect(self::HistoricalAbilities, $allowed));

        return ['read' => array_values(array_intersect(self::ReadPreset, $allowed)), 'write' => $historical, 'manage' => $historical,
            'agent-read' => array_values(array_intersect(self::AgentReadPreset, $allowed))];
    }

    /**
     * The token form's starting points, in checklist order; they only fill the checklist, the checked abilities are the grant.
     *
     * @return array{read-everything: list<string>, deploy-automation: list<string>|null, full-access: list<string>}
     */
    public function startingPoints(User $user): array
    {
        $allowed = $this->allowedAbilities($user);

        return ['read-everything' => array_values(array_filter($allowed, static fn (string $ability): bool => str_ends_with($ability, ':read'))),
            'deploy-automation' => array_diff(self::DeployAutomation, $allowed) === [] ? array_values(array_intersect($allowed, self::DeployAutomation)) : null,
            // Every ability the role may issue today, listed: never `*`, so a later ability is not granted silently.
            'full-access' => $allowed];
    }

    /**
     * @param  list<string>  $abilities
     * @param  int|null  $expiresInDays  a lifetime counted from server time at issuance, instead of $expiresAt
     */
    public function __invoke(User $user, string $name, array $abilities, ?int $clientId = null, ?DateTimeInterface $expiresAt = null, ?int $expiresInDays = null): string
    {
        $authenticated = $user;

        return DB::transaction(function () use ($authenticated, $name, $abilities, $clientId, $expiresAt, $expiresInDays): string {
            // NotificationLocks level 0: a change of who may write what holds the access-structure lock exclusive, first.
            NotificationLocks::accessExclusive();
            // Removal, access changes and password resets hold the user FOR UPDATE while they delete its tokens: a token is
            // issued only under the access the account has once they committed, and only to a caller whose sign-in still
            // holds (a changed password means the caller authenticated before the reset).
            NotificationLocks::users([$authenticated->id]);
            $user = User::query()->findOrFail($authenticated->id);
            abort_if($user->disabled_at !== null || $user->getAuthPassword() !== $authenticated->getAuthPassword(), 403);
            // No new `*`: existing wildcard tokens keep working, new grants name their abilities.
            Validator::make(['name' => $name, 'abilities' => $abilities], ['name' => ['required', 'string', 'max:255'], 'abilities' => ['required', 'array', 'min:1'], 'abilities.*' => ['string', 'distinct', Rule::in($this->allowedAbilities($user))]])->validate();

            if ($clientId !== null && ! $user->canAccessClient($clientId)) {
                throw ValidationException::withMessages(['client_id' => __('validation.exists', ['attribute' => 'client_id'])]);
            }
            if ($expiresAt instanceof DateTimeInterface && $expiresAt <= now()) {
                throw ValidationException::withMessages(['expires_at' => __('validation.after', ['attribute' => 'expires_at', 'date' => 'now'])]);
            }
            $created = $user->createToken($name, $abilities, $expiresInDays === null ? $expiresAt : now()->addDays($expiresInDays));
            $created->accessToken->forceFill(['client_id' => $clientId])->save();

            return $created->plainTextToken;
        });
    }

    /** @return list<string> */
    public function allowedAbilities(User $user): array
    {
        $abilities = $this->servers->enabled() ? self::Abilities : array_values(array_diff(self::Abilities, self::ServerAbilities));

        return $user->hasAnyRole(['Owner', 'Admin']) ? $abilities
            : array_values(array_diff($abilities, [...self::AdminAbilities, ...self::ServerAbilities]));
    }
}
