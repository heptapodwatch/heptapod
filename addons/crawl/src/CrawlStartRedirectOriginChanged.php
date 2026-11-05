<?php

declare(strict_types=1);

namespace Heptapod\Addons\Crawl;

/** Stops a same-site origin change before configured crawler headers can leave their origin. */
final class CrawlStartRedirectOriginChanged extends \RuntimeException
{
    public function __construct(public readonly string $targetUrl)
    {
        parent::__construct('Crawl start redirect changes the crawler credentials origin.');
    }
}
