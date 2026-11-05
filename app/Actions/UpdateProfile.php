<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Models\NotificationDestination;
use Heptapod\Models\User;
use Illuminate\Support\Facades\DB;

final class UpdateProfile
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(User $user, array $attributes): void
    {
        DB::transaction(function () use ($user, $attributes): void {
            $user->fill($attributes);
            $emailChanged = $user->isDirty('email');
            $nameChanged = $user->isDirty('name');
            if ($emailChanged) {
                $user->email_verified_at = null;
            }
            $user->save();
            // The account channel derives address, language and timezone from the account; only its label is stored.
            if ($nameChanged) {
                NotificationDestination::query()->where('user_id', $user->id)->where('account_mail', true)->update(['label' => $user->name]);
            }
        });
    }
}
