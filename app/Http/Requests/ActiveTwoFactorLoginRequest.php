<?php

declare(strict_types=1);

namespace Heptapod\Http\Requests;

use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Exceptions\HttpResponseException;
use Laravel\Fortify\Contracts\FailedTwoFactorLoginResponse;
use Laravel\Fortify\Http\Requests\TwoFactorLoginRequest;

final class ActiveTwoFactorLoginRequest extends TwoFactorLoginRequest
{
    public function hasChallengedUser(): bool
    {
        return $this->activeChallengedUser() !== null;
    }

    public function challengedUser(): mixed
    {
        if ($this->activeChallengedUser() === null) {
            throw new HttpResponseException(app(FailedTwoFactorLoginResponse::class)->toResponse($this));
        }

        return parent::challengedUser();
    }

    private function activeChallengedUser(): mixed
    {
        $id = $this->session()->get('login.id');

        return $id === null ? null : app(StatefulGuard::class)->getProvider()->retrieveById($id);
    }
}
