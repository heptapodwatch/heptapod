<?php

declare(strict_types=1);

namespace Heptapod\Maintenance;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class RecurringMaintenanceAttributes
{
    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function validate(array $attributes): array
    {
        /** @var array<string, mixed> */
        return Validator::make($attributes, [
            'name' => 'nullable|string|max:255', 'reason' => 'nullable|string|max:255',
            'target_type' => 'sometimes|in:site,client,tag', 'target_id' => 'nullable|integer|min:1',
            'mute_alerts' => 'sometimes|boolean', 'catch_up_at_end' => 'sometimes|boolean',
            'recurrence_type' => 'required|in:daily,weekly,monthly', 'interval' => 'sometimes|integer|between:1,52',
            'nth_weekday' => 'nullable|required_with:day_of_week|integer|between:1,5',
            'day_of_week' => 'nullable|required_with:nth_weekday|integer|between:0,6',
            'anchor_date' => 'nullable|date_format:Y-m-d',
            'days_of_week' => 'required_if:recurrence_type,weekly|nullable|array|min:1', 'days_of_week.*' => 'integer|between:0,6|distinct',
            'day_of_month' => [Rule::requiredIf(($attributes['recurrence_type'] ?? null) === 'monthly' && ! isset($attributes['nth_weekday'])), 'nullable', 'integer', 'between:1,31', Rule::prohibitedIf(isset($attributes['nth_weekday']))],
            'start_time' => 'required|date_format:H:i', 'end_time' => 'required|date_format:H:i', 'timezone' => 'required|timezone:all',
        ])->validate();
    }
}
