<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Models\Incident;
use Illuminate\Validation\ValidationException;

final class SnoozeIncidentReminders
{
    public function __invoke(Incident $incident, ?int $minutes): Incident
    {
        if ($incident->resolved_at !== null) {
            throw ValidationException::withMessages(['incident' => __('api.unsupported')]);
        }
        if ($minutes !== null && ($minutes < 1 || $minutes > 525600)) {
            throw ValidationException::withMessages(['minutes' => __('api.invalid_minutes')]);
        }
        $incident->update(['snoozed_until' => $minutes === null ? null : now()->addMinutes($minutes)]);

        return $incident;
    }
}
