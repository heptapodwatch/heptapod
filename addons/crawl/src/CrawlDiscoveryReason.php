<?php

declare(strict_types=1);

namespace Heptapod\Addons\Crawl;

use Heptapod\Contracts\CheckRunPresentationPartData;

enum CrawlDiscoveryReason: string
{
    case RequestFailed = 'request_failed';
    case HttpErrorPages = 'http_error_pages';
    case PageTooLarge = 'page_too_large';
    case BotProtection = 'bot_protection';
    case RateLimited = 'rate_limited';
    case Nofollow = 'nofollow';
    case PageLimit = 'page_limit';
    case TimeLimit = 'time_limit';
    case ResourceLimit = 'resource_limit';
    case InvalidUrlLimit = 'invalid_url_limit';
    case StartPageUnavailable = 'start_page_unavailable';

    /** @param array<string, string|int> $params */
    public function note(array $params = []): CheckRunPresentationPartData
    {
        return new CheckRunPresentationPartData('crawl.discovery.'.$this->value, $params);
    }
}
