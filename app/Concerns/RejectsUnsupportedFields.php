<?php

declare(strict_types=1);

namespace Heptapod\Concerns;

use Illuminate\Validation\ValidationException;

/** A body key the endpoint does not take answers 422 instead of being dropped, like UpdateSite does for monitors. */
trait RejectsUnsupportedFields
{
    /**
     * @param  array<array-key, mixed>  $input
     * @param  list<string>  $fields
     */
    private function rejectUnsupportedFields(array $input, array $fields): void
    {
        foreach (array_diff(array_keys($input), $fields) as $key) {
            throw ValidationException::withMessages([(string) $key => __('api.unsupported_field')]);
        }
    }
}
