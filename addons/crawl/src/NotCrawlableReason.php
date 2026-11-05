<?php

declare(strict_types=1);

namespace Heptapod\Addons\Crawl;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum NotCrawlableReason: string
{
    case AuthenticationRequired = 'authentication_required';
    case NonHtml = 'non_html';
    case LoginRedirect = 'login_redirect';
    case LoginPage = 'login_page';
    case NoIndex = 'noindex';
    case Nofollow = 'nofollow';
    case StartUrlExcluded = 'start_url_excluded';
    case RobotsDisallowed = 'robots_disallowed';
    case BotProtection = 'bot_protection';
    case RateLimited = 'rate_limited';
}
