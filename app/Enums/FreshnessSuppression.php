<?php

declare(strict_types=1);

namespace Heptapod\Enums;

use Heptapod\Contracts\CheckKey;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum FreshnessSuppression: string
{
    case Down = 'down';
    case Maintenance = 'maintenance';
    case Snooze = 'snooze';
    case UptimeError = 'uptime_error';

    public function labelKey(): string
    {
        return match ($this) {
            self::Down => 'Site is down',
            self::Maintenance => 'Maintenance',
            self::Snooze => 'Alerts are snoozed',
            self::UptimeError => 'Uptime check error',
        };
    }

    public function headlineKey(CheckKey $type): string
    {
        $reason = match ($this) {
            self::Down => 'down',
            self::Maintenance => 'maintenance',
            self::Snooze => 'snooze',
            self::UptimeError => 'uptime_error',
        };

        return 'freshness.suppressed.'.($type === CheckKey::Performance ? 'performance.' : 'check.').$reason;
    }
}
