<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** The snooze end a snooze form or request names: the check page's and the server's, so their rules cannot drift. */
final class SnoozeEnd
{
    /** At most a year ahead. */
    public const int MaxMinutes = 525600;

    /**
     * `minutes` (1 to a year) from now, or an absolute `snooze_until` with an offset, after now and at most a year ahead.
     *
     * @param  array<array-key, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function from(array $input): CarbonImmutable
    {
        $absolute = array_key_exists('snooze_until', $input);
        /** @var array{snooze_until?: string, minutes?: int|numeric-string} $valid */
        $valid = Validator::make($input, $absolute
            ? ['snooze_until' => ['required', 'string', 'date', 'regex:/(Z|[+-]\d{2}:\d{2})$/', 'after:now', 'before_or_equal:'.now()->addMinutes(self::MaxMinutes)->toIso8601String()]]
            : ['minutes' => ['required', 'integer', 'between:1,'.self::MaxMinutes]], ['snooze_until' => __('check_snooze.until_invalid')])->validate();

        return isset($valid['snooze_until']) ? CarbonImmutable::parse($valid['snooze_until'])->utc()
            : CarbonImmutable::now('UTC')->addMinutes((int) ($valid['minutes'] ?? 0));
    }
}
