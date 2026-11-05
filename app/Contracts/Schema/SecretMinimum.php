<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Schema;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

final class SecretMinimum implements ValidationRule
{
    public bool $implicit = true;

    public static function tooShort(mixed $value): bool
    {
        return is_string($value) && $value !== '' && mb_strlen(trim($value), 'UTF-8') < 4;
    }

    /** @param Closure(string, ?string=): PotentiallyTranslatedString $fail */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (self::tooShort($value)) {
            $fail('validation.secret_minimum')->translate();
        }
    }
}
