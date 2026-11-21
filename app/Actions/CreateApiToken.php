<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use DateTimeInterface;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CreateApiToken
{
    /** The abilities of the write and manage presets. */
    public const array HistoricalAbilities = ['identity:read', 'monitors:read', 'monitors:write', 'checks:read', 'checks:write', 'checks:snooze', 'incidents:read', 'incidents:note', 'uptime:read', 'downtime:read', 'tags:read', 'tags:write', 'maintenance:read', 'maintenance:write'];

    public const array Abilities = [...self::HistoricalAbilities, 'activity:read', 'upcoming:read', 'checks:run'];

    public const array ReadPreset = ['identity:read', 'monitors:read', 'checks:read', 'incidents:read', 'uptime:read', 'downtime:read', 'tags:read', 'maintenance:read'];

    public const array AgentReadPreset = ['identity:read', 'monitors:read', 'checks:read', 'incidents:read', 'activity:read', 'upcoming:read'];

    /** Abilities only Owners and Admins can issue. */
    public const array AdminAbilities = ['monitors:write', 'checks:write', 'checks:run', 'maintenance:write'];

    /** @return array{read: list<string>, write: list<string>, manage: list<string>, agent-read: list<string>} */
    public function presets(User $user): array
    {
        $allowed = $this->allowedAbilities($user);
        $historical = array_values(array_intersect(self::HistoricalAbilities, $allowed));

        return ['read' => array_values(array_intersect(self::ReadPreset, $allowed)), 'write' => $historical, 'manage' => $historical,
            'agent-read' => array_values(array_intersect(self::AgentReadPreset, $allowed))];
    }

    /** @param list<string> $abilities */
    public function __invoke(User $user, string $name, array $abilities, ?int $clientId = null, ?DateTimeInterface $expiresAt = null): string
    {
        $authenticated = $user;

        return DB::transaction(function () use ($authenticated, $name, $abilities, $clientId, $expiresAt): string {
            // Removal, access changes and password resets hold the user FOR UPDATE while they delete its tokens: a token is
            // issued only under the access the account has once they committed, and only to a caller whose sign-in still
            // holds (a changed password means the caller authenticated before the reset).
            NotificationLocks::users([$authenticated->id]);
            $user = User::query()->findOrFail($authenticated->id);
            abort_if($user->disabled_at !== null || $user->getAuthPassword() !== $authenticated->getAuthPassword(), 403);
            $allowed = $user->hasRole('Owner') ? ['*', ...$this->allowedAbilities($user)] : $this->allowedAbilities($user);
            Validator::make(['name' => $name, 'abilities' => $abilities], ['name' => ['required', 'string', 'max:255'], 'abilities' => ['required', 'array', 'min:1'], 'abilities.*' => ['string', 'distinct', Rule::in($allowed)]])->validate();

            if ($clientId !== null && ! $user->canAccessClient($clientId)) {
                throw ValidationException::withMessages(['client_id' => __('validation.exists', ['attribute' => 'client_id'])]);
            }
            if ($expiresAt instanceof DateTimeInterface && $expiresAt <= now()) {
                throw ValidationException::withMessages(['expires_at' => __('validation.after', ['attribute' => 'expires_at', 'date' => 'now'])]);
            }
            $created = $user->createToken($name, $abilities, $expiresAt);
            $created->accessToken->forceFill(['client_id' => $clientId])->save();

            return $created->plainTextToken;
        });
    }

    /** @return list<string> */
    private function allowedAbilities(User $user): array
    {
        return $user->hasAnyRole(['Owner', 'Admin']) ? self::Abilities
            : array_values(array_diff(self::Abilities, self::AdminAbilities));
    }
}
