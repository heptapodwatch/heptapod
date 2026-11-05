<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Http;

enum RedirectPolicy: string
{
    case Off = 'off';
    case Operator = 'operator';
    case Untrusted = 'untrusted';
}
