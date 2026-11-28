<?php

declare(strict_types=1);

namespace Heptapod\Addons\Crawl;

use Spatie\Crawler\Crawler;

final class QueueCrawler extends Crawler
{
    private ?CrawlWhitelist $excluded = null;

    private ?CrawlWhitelist $forced = null;

    /** @param list<string> $excluded
     * @param  list<string>  $forced
     */
    public function scopePatterns(array $excluded, array $forced): self
    {
        $this->excluded = new CrawlWhitelist($excluded);
        $this->forced = new CrawlWhitelist($forced);

        return $this;
    }

    /** Spatie checks always/never before robots and the shouldCrawl profile. Keep that order. */
    #[\Override]
    public function matchesAlwaysCrawl(string $url): bool
    {
        return $this->forced?->matches($url) ?? false;
    }

    #[\Override]
    public function matchesNeverCrawl(string $url): bool
    {
        return $this->excluded?->matches($url) ?? false;
    }

    /** @param array<array-key, mixed> $clientOptions */
    #[\Override]
    public static function create(string $url, array $clientOptions = []): self
    {
        // The package factory uses new self rather than late static binding.
        $crawler = new self($clientOptions);
        $crawler->baseUrl = $url;

        return $crawler;
    }

    /** The queue worker owns process signals, including while a crawl is running. */
    #[\Override]
    protected function canHandleSignals(): bool
    {
        return false;
    }
}
