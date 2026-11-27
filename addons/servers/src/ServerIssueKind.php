<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What a server issue is about, in display order: the first open one names the server's state. Never "Down": "No report"
 * means we could not hear from the server.
 */
#[TypeScript]
enum ServerIssueKind: string
{
    case NoReport = 'no_report';
    case DiskFull = 'disk_full';
    case MemoryLow = 'memory_low';
    case LoadHigh = 'load_high';
}
