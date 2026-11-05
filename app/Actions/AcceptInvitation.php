<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Concerns\PasswordValidationRules;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Models\Invitation;
use Heptapod\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final readonly class AcceptInvitation
{
    use PasswordValidationRules;

    public function __construct(private EnsureAccountChannel $accountChannel) {}

    /** @param array<string, mixed> $attributes */
    public function __invoke(string $token, array $attributes): User
    {
        /** @var array{name: string, password: string} $data */
        $data = Validator::make($attributes, [
            'name' => ['required', 'string', 'max:255'], 'password' => $this->passwordRules(),
            'email' => ['prohibited'], 'role' => ['prohibited'], 'client_ids' => ['prohibited'],
        ])->validate();

        return DB::transaction(function () use ($token, $data): User {
            $query = Invitation::query()->where('token_hash', hash('sha256', $token));
            NotificationLocks::forUpdate($query->getQuery());
            $invitation = $query->firstOrFail();
            abort_if($invitation->expires_at->isPast(), 410);
            $userQuery = User::query()->where('email', $invitation->email);
            NotificationLocks::forUpdate($userQuery->getQuery());
            $user = $userQuery->first() ?? new User(['email' => $invitation->email]);
            abort_if($user->exists && $user->disabled_at === null, 409);
            $user->fill(['name' => $data['name'], 'password' => $data['password'], 'client_scope' => $invitation->client_scope]);
            $user->forceFill(['email_verified_at' => now(), 'disabled_at' => null])->save();
            $user->syncRoles([$invitation->role]);
            $user->clients()->sync($invitation->client_ids);
            // A re-activated user keeps the account channel and grid they had before.
            ($this->accountChannel)($user);
            $invitation->delete();

            return $user;
        });
    }
}
