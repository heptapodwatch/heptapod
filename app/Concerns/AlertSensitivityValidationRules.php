<?php

declare(strict_types=1);

namespace Heptapod\Concerns;

use Heptapod\Enums\AlertSensitivity;

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
}
