<?php

declare(strict_types=1);

namespace Heptapod\Concerns;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** A body key the endpoint does not take answers 422 instead of being dropped, like UpdateSite does for monitors. */
trait RejectsUnsupportedFields
{
    /** @param list<string> $fields */
    private function rejectUnsupportedFields(Request $request, array $fields): void
    {
        foreach (array_diff(array_keys($request->all()), $fields) as $key) {
            throw ValidationException::withMessages([(string) $key => __('api.unsupported_field')]);
        }
    }
}
