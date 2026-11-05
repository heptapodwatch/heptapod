<?php

declare(strict_types=1);

namespace Heptapod\Addons\Crawl;

/** Carries the first off-site Location as untrusted evidence, without requesting that target. */
final class CrawlStartRedirectOffSite extends \RuntimeException
{
    public function __construct(public readonly string $targetUrl)
    {
        parent::__construct('Crawl start URL redirects off-site.');
    }
}
