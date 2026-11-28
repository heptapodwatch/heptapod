<?php

namespace Heptapod\Http\Requests\Settings;

use Heptapod\Concerns\PasswordValidationRules;
use Heptapod\Concerns\ProfileValidationRules;
use Heptapod\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    use PasswordValidationRules;
    use ProfileValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $user = $this->user();
        abort_unless($user instanceof User, 401);

        return [
            ...$this->profileRules($user->id),
            // The address receives password reset links, so changing it needs the current password, like a password change.
            'current_password' => [Rule::excludeIf($this->input('email') === $user->email), ...$this->currentPasswordRules()],
            'locale' => ['sometimes', 'required', 'string', Rule::in(config()->array('app.supported_locales'))],
            // Null returns to the default: the instance zone.
            'timezone' => ['sometimes', 'nullable', 'string', 'timezone:all'],
        ];
    }

    /**
     * Sign-in and invitations resolve an address in lower case, so the profile stores it that way. An address saved in
     * mixed case by an older version stays as it is while the user does not change it, so unrelated edits need no password.
     */
    protected function prepareForValidation(): void
    {
        $email = $this->input('email');
        $user = $this->user();
        if (is_string($email) && $user instanceof User) {
            $email = Str::lower(trim($email));
            $this->merge(['email' => $email === Str::lower($user->email) ? $user->email : $email]);
        }
    }
}
