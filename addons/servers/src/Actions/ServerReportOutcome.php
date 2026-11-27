<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers\Actions;

enum ServerReportOutcome
{
    case Stored;
    /** The sample_id is already stored (a retry); not a new report. */
    case Duplicate;
    /** The token was rotated while the report was in flight. */
    case Unauthorized;
    /** The server was removed while the report was in flight. */
    case Removed;
}
