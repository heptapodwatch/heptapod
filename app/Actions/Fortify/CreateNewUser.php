<?php

namespace Heptapod\Actions\Fortify;

use Heptapod\Concerns\PasswordValidationRules;
use Heptapod\Concerns\ProfileValidationRules;
use Heptapod\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<array-key, mixed>  $input
     */
    public function create(array $input, bool $generatedPassword = false): User
    {
        // A 32-character CSPRNG credential needs strength validation, not an external breach lookup.
        $passwordRules = $generatedPassword
            ? ['required', 'string', Password::min(12)->mixedCase()->letters()->numbers()->symbols(), 'confirmed']
            : $this->passwordRules();
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $passwordRules,
        ])->validate();

        return User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);
    }
}
