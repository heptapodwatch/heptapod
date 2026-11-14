<?php

declare(strict_types=1);

namespace Heptapod\Enums;

enum AlertSensitivity: string
{
    case Strict = 'strict';
    case Balanced = 'balanced';
    case Relaxed = 'relaxed';
    case Patient = 'patient';

    public const self DEFAULT = self::Relaxed;

    public const int MinMinutes = 2;

    public const int MaxMinutes = 60;

    public const int MinStoredMinutes = 1;

    public const int MaxStoredMinutes = 1000;

    public const int MinRecoveryChecks = 1;

    public const int MaxRecoveryChecks = 10;

    /** @return array{minutes_min: int, minutes_max: int, stored_minutes_min: int, stored_minutes_max: int, recovery_min: int, recovery_max: int} */
    public static function bounds(): array
    {
        return ['minutes_min' => self::MinMinutes, 'minutes_max' => self::MaxMinutes,
            'stored_minutes_min' => self::MinStoredMinutes, 'stored_minutes_max' => self::MaxStoredMinutes,
            'recovery_min' => self::MinRecoveryChecks, 'recovery_max' => self::MaxRecoveryChecks];
    }

    public static function recoveryChecksFor(int $minutes): int
    {
        return $minutes <= self::Strict->alertAfterMinutes() ? self::Strict->recoveryChecks() : self::Relaxed->recoveryChecks();
    }

    public function alertAfterMinutes(): int
    {
        return match ($this) {
            self::Strict => 2,
            self::Balanced => 5,
            self::Relaxed => 30,
            self::Patient => 60,
        };
    }

    public function recoveryChecks(): int
    {
        return $this === self::Strict ? 1 : 3;
    }

    /** Failed and Check error alerts of other checks wait for the next run to show the same problem. */
    public function confirmsOtherChecks(): bool
    {
        return $this !== self::Strict;
    }

    public function reminders(): AlertReminders
    {
        return in_array($this, [self::Relaxed, self::Patient], true) ? AlertReminders::Daily : AlertReminders::Standard;
    }

    /** The preset whose four settings equal these values; null is Custom. */
    public static function matching(int $minutes, int $checks, bool $confirmsOtherChecks, AlertReminders $reminders): ?self
    {
        foreach (self::cases() as $preset) {
            if ($preset->alertAfterMinutes() === $minutes && $preset->recoveryChecks() === $checks
                && $preset->confirmsOtherChecks() === $confirmsOtherChecks && $preset->reminders() === $reminders) {
                return $preset;
            }
        }

        return null;
    }
}
