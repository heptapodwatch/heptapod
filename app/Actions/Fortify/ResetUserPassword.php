<?php

declare(strict_types=1);

namespace Heptapod\Actions\Fortify;

use Heptapod\Actions\RevokeMemberAccess;
use Heptapod\Concerns\PasswordValidationRules;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    public function __construct(private readonly RevokeMemberAccess $revoke) {}

    /**
     * Validate and reset the user's forgotten password. A reset assumes the old credentials leaked: every session (through
     * the session's password hash), API token and live connection of the account ends.
     *
     * @param  array<string, string>  $input
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        DB::transaction(function () use ($user, $input): void {
            // The same exclusive user lock as removal: a token issued concurrently either exists before the revocation
            // deletes it or waits and is refused (CreateApiToken).
            NotificationLocks::forUpdate(User::query()->whereKey($user->id)->getQuery())->first(['id']);
            $user->forceFill([
                'password' => $input['password'],
            ])->save();
            ($this->revoke)($user);
        });
    }
}
