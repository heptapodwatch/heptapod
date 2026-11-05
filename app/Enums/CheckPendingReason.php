<?php

declare(strict_types=1);

namespace Heptapod\Enums;

/** Why a check shows pending although its latest result is final; stored in checks.pending_reason (database-checked). */
enum CheckPendingReason: string
{
    case DnsAcceptRecheck = 'dns_accept_recheck';
}
