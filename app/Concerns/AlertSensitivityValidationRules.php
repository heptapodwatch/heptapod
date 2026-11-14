<?php

declare(strict_types=1);

namespace Heptapod\Concerns;

use Heptapod\Enums\AlertReminders;
use Heptapod\Enums\AlertSensitivity;
use Illuminate\Validation\Rules\Enum;

trait AlertSensitivityValidationRules
{
    /** API/import timings outside the UI range stay valid until the minutes value is changed. */
    protected function alertAfterMinutesRange(?int $storedMinutes, mixed $submittedMinutes): string
    {
        return $storedMinutes !== null && filter_var($submittedMinutes, FILTER_VALIDATE_INT) === $storedMinutes
            ? 'between:'.AlertSensitivity::MinStoredMinutes.','.AlertSensitivity::MaxStoredMinutes
            : 'between:'.AlertSensitivity::MinMinutes.','.AlertSensitivity::MaxMinutes;
    }

    protected function alertRecoveryChecksRange(): string
    {
        return 'between:'.AlertSensitivity::MinRecoveryChecks.','.AlertSensitivity::MaxRecoveryChecks;
    }

    /**
     * The four settings are saved together; a site override sends all four or all null (inherit).
     *
     * @return array<string, list<string|Enum>>
     */
    protected function alertSensitivityRules(?int $storedMinutes, bool $nullable): array
    {
        $fields = ['alert_after_minutes', 'alert_recovery_checks', 'alert_confirms_other_checks', 'alert_reminders'];
        $presence = static fn (string $field): array => $nullable
            ? ['present_with:'.implode(',', array_diff($fields, [$field])), 'required_with:'.implode(',', array_diff($fields, [$field])), 'nullable']
            : ['required'];

        return [
            'alert_after_minutes' => [...$presence('alert_after_minutes'), 'integer', $this->alertAfterMinutesRange($storedMinutes, $this->input('alert_after_minutes'))],
            'alert_recovery_checks' => [...$presence('alert_recovery_checks'), 'integer', $this->alertRecoveryChecksRange()],
            'alert_confirms_other_checks' => [...$presence('alert_confirms_other_checks'), 'boolean'],
            'alert_reminders' => [...$presence('alert_reminders'), new Enum(AlertReminders::class)],
        ];
    }
}
