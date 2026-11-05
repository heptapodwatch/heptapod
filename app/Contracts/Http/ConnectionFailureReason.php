<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Http;

/** PHP cURL exposes no OS errno, so a refused connection cannot be told apart from an unreachable host. */
enum ConnectionFailureReason: string
{
    /** TCP connect failed: refused or unreachable. */
    case ConnectFailed = 'connect_failed';
    /** The server closed or reset the connection after it was established. */
    case Reset = 'reset';
}
