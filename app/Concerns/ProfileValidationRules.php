<?php

namespace Heptapod\Concerns;

use Heptapod\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;

trait ProfileValidationRules
{
    /**
     * Get the validation rules used to validate user profiles.
     *
     * @return array<string, array<int, ValidationRule|\Closure|array<mixed>|string>>
     */
    protected function profileRules(?int $userId = null): array
    {
        return [
            'name' => $this->nameRules(),
            'email' => $this->emailRules($userId),
        ];
    }

    /**
     * Get the validation rules used to validate user names.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function nameRules(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /**
     * Get the validation rules used to validate user emails.
     *
     * @return array<int, ValidationRule|\Closure|array<mixed>|string>
     */
    protected function emailRules(?int $userId = null): array
    {
        return [
            'required',
            'string',
            'email',
            'max:255',
            // Sign-in resolves an address in lower case, so addresses that differ only in case are the same account.
            static function (string $attribute, mixed $value, \Closure $fail) use ($userId): void {
                $taken = User::query()->getQuery()->whereRaw('lower(email) = lower(?)', [$value]);
                if ($userId !== null) {
                    $taken->where('id', '<>', $userId);
                }
                if ($taken->exists()) {
                    $fail(__('validation.unique', ['attribute' => __('validation.attributes.'.$attribute)]));
                }
            },
        ];
    }
}
