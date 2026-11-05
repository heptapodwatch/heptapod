<?php

namespace Heptapod\Http\Requests\Settings;

use Heptapod\Concerns\ProfileValidationRules;
use Heptapod\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProfileUpdateRequest extends FormRequest
{
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
            'locale' => ['sometimes', 'required', 'string', 'in:en,de'],
            'timezone' => ['sometimes', 'required', 'string', 'timezone:all'],
        ];
    }
}
