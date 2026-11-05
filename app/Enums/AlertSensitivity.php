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
            self::Relaxed => 10,
            self::Patient => 30,
        };
    }

    public function recoveryChecks(): int
    {
        return $this === self::Strict ? 1 : 3;
    }

    public static function fromTiming(int $minutes, int $checks): ?self
    {
        foreach (self::cases() as $preset) {
            if ($preset->alertAfterMinutes() === $minutes && $preset->recoveryChecks() === $checks) {
                return $preset;
            }
        }

        return null;
    }
}
