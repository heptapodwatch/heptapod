<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Actions\Fortify\CreateNewUser;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Exceptions\OwnerAlreadyExists;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class CreateOwner
{
    public function __construct(private CreateNewUser $createUser, private EnsureAccountChannel $accountChannel) {}

    public function __invoke(string $email, string $name, string $password, bool $ifMissing = false, bool $generatedPassword = false): ?User
    {
        return DB::transaction(function () use ($email, $name, $password, $ifMissing, $generatedPassword): ?User {
            // Serialize first-owner creation across concurrent installers on the reference role.
            $role = NotificationLocks::forUpdate(DB::table('roles')->where('name', 'Owner')->where('guard_name', 'web'))->first()
                ?? throw new LogicException('Owner role is missing; run migrations first.');

            if (DB::table('model_has_roles')->where('role_id', $role->id)->where('model_type', (new User)->getMorphClass())->exists()) {
                if ($ifMissing) {
                    return null;
                }

                throw new OwnerAlreadyExists('An Owner already exists. Use password reset to recover access.');
            }

            $user = $this->createUser->create([
                'email' => $email,
                'name' => $name,
                'password' => $password,
                'password_confirmation' => $password,
            ], generatedPassword: $generatedPassword);
            $user->forceFill(['email_verified_at' => now()])->save();
            $user->assignRole('Owner');
            // The first Owner of an empty install hears everything except Changes (design 1.3). Preconfigured routing
            // (an imported backup) stays untouched: the Owner then only gets System, as an account without routing did.
            $preconfigured = NotificationDestination::query()->getQuery()->exists();
            ($this->accountChannel)($user, firstOwner: ! $preconfigured, silent: $preconfigured);

            return $user;
        });
    }
}
