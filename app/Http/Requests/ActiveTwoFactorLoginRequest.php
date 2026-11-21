<?php

declare(strict_types=1);

namespace Heptapod\Http\Requests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Exceptions\HttpResponseException;
use Laravel\Fortify\Contracts\FailedTwoFactorLoginResponse;
use Laravel\Fortify\Http\Requests\TwoFactorLoginRequest;

/** A pending two-factor challenge completes only for an active account whose password is still the one that started it. */
final class ActiveTwoFactorLoginRequest extends TwoFactorLoginRequest
{
    /** Stored when the challenge starts (FortifyServiceProvider); a password reset in between voids the challenge. */
    public const string CredentialsKey = 'login.credentials';

    public static function credentialsFingerprint(Authenticatable $user): string
    {
        return hash_hmac('sha256', $user->getAuthPassword(), config()->string('app.key'));
    }

    public function hasChallengedUser(): bool
    {
        return $this->activeChallengedUser() instanceof Authenticatable;
    }

    public function challengedUser(): mixed
    {
        if (! $this->activeChallengedUser() instanceof Authenticatable) {
            throw new HttpResponseException(app(FailedTwoFactorLoginResponse::class)->toResponse($this));
        }

        return parent::challengedUser();
    }

    private function activeChallengedUser(): ?Authenticatable
    {
        $id = $this->session()->get('login.id');
        $user = $id === null ? null : app(StatefulGuard::class)->getProvider()->retrieveById($id);
        $started = $this->session()->get(self::CredentialsKey);

        return $user !== null && is_string($started) && hash_equals($started, self::credentialsFingerprint($user)) ? $user : null;
    }
}
