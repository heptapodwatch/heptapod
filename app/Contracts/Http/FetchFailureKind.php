<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Http;

enum FetchFailureKind: string
{
    case Timeout = 'timeout';
    case TooLarge = 'too_large';
    case Tls = 'tls';
    case Dns = 'dns';
    case Connect = 'connect';
    case RedirectBlocked = 'redirect_blocked';
    case TooManyRedirects = 'too_many_redirects';
    case PrivateTarget = 'private_target';
    case HttpStatus = 'http_status';
    case InvalidRequest = 'invalid_request';
}
