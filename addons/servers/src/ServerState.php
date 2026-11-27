<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers;

use Carbon\CarbonImmutable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** What the instance hears from a server; display only until server alerts exist. Never "Down". */
#[TypeScript]
enum ServerState: string
{
    case Awaiting = 'awaiting';
    case Reporting = 'reporting';
    case NoReport = 'no_report';

    /** "No report" after two missed intervals. */
    public static function of(?CarbonImmutable $lastReportAt, CarbonImmutable $now): self
    {
        return match (true) {
            ! $lastReportAt instanceof CarbonImmutable => self::Awaiting,
            $lastReportAt->lt($now->subSeconds(2 * config()->integer('servers.interval_seconds'))) => self::NoReport,
            default => self::Reporting,
        };
    }
}
