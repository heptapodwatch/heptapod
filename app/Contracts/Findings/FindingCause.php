<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Findings;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Response or transport cause recorded for one observation of a finding. */
#[TypeScript]
enum FindingCause: string
{
    case RequestFailed = 'request_failed';
    case AccessRefused = 'access_refused';
    case LoginRequired = 'login_required';
    case BotProtection = 'bot_protection';
    case RateLimited = 'rate_limited';
    case Timeout = 'timeout';
    case ConnectFailed = 'connect_failed';
    case ConnectionReset = 'connection_reset';
    case DnsUnresolved = 'dns_unresolved';
    case TooManyRedirects = 'too_many_redirects';
    case TlsExpired = 'tls_expired';
    case TlsNotYetValid = 'tls_not_yet_valid';
    case TlsNameMismatch = 'tls_name_mismatch';
    case TlsUntrusted = 'tls_untrusted';
    case TlsHandshakeFailed = 'tls_handshake_failed';
    case PrivateTarget = 'private_target';
    case InvalidUrl = 'invalid_url';
    case PageTooLarge = 'page_too_large';
    case CauseNotRecorded = 'cause_not_recorded';
}
