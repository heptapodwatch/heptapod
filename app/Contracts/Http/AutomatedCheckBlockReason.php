<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Http;

enum AutomatedCheckBlockReason: string
{
    case BotProtection = 'bot_protection';
    case RateLimited = 'rate_limited';
}
