<?php

declare(strict_types=1);

namespace Heptapod\Addons\Crawl;

use Spatie\Crawler\Crawler;

/** The queue worker owns process signals, including while a crawl is running. */
final class QueueCrawler extends Crawler
{
    /** @param array<array-key, mixed> $clientOptions */
    #[\Override]
    public static function create(string $url, array $clientOptions = []): self
    {
        // The package factory uses new self rather than late static binding.
        $crawler = new self($clientOptions);
        $crawler->baseUrl = $url;

        return $crawler;
    }

    #[\Override]
    protected function canHandleSignals(): bool
    {
        return false;
    }
}
