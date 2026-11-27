<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What the instance hears from a server; display only until server alerts exist. Never "Down". ServerReader decides it
 * in SQL: "No report" once the newest report is older than the server's "No report after".
 */
#[TypeScript]
enum ServerState: string
{
    case Awaiting = 'awaiting';
    case Reporting = 'reporting';
    case NoReport = 'no_report';
}
