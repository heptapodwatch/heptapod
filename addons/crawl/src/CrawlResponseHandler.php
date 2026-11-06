<?php

declare(strict_types=1);

namespace Heptapod\Addons\Crawl;

use Heptapod\Contracts\Http\AutomatedCheckBlock;
use Heptapod\Contracts\Http\AutomatedCheckBlockReason;
use Heptapod\Contracts\Http\FetchException;
use Heptapod\Contracts\Http\FetchFailure;
use Heptapod\Contracts\Http\FetchFailureKind;
use Heptapod\Contracts\Http\HtmlPage;
use Psr\Http\Message\ResponseInterface;
use Spatie\Crawler\CrawlerRobots;
use Spatie\Crawler\CrawlUrl;
use Spatie\Crawler\Handlers\CrawlRequestFulfilled;

final class CrawlResponseHandler extends CrawlRequestFulfilled
{
    protected function handle(ResponseInterface $response, mixed $index): void
    {
        $crawlUrl = $this->crawler->getCrawlQueue()->getUrlById($index);
        $body = $this->getBody($response);
        $parser = $this->crawler->getUrlParser();
        if (! $parser instanceof CrawlUrlParser) {
            throw new \LogicException('Response classification requires the crawl URL parser.');
        }
        if ($crawlUrl->depth === 0) {
            // The start response's destination is independent of HTML readability or descendant pages.
            $parser->observer->startPageFinalUrl = array_last($response->getHeader('X-Guzzle-Redirect-History')) ?? $crawlUrl->url;
        }
        $block = AutomatedCheckBlock::reason($response->getStatusCode(), $response->getHeaders(), substr($body, 0, AutomatedCheckBlock::BODY_PREFIX_BYTES));
        if ($block instanceof AutomatedCheckBlockReason) {
            // A protected page provides neither traversal coverage nor readable mixed-content evidence.
            $parser->incomplete($block === AutomatedCheckBlockReason::RateLimited ? CrawlDiscoveryReason::RateLimited : CrawlDiscoveryReason::BotProtection);
            $parser->observer->notVerifiable($crawlUrl->url, $this->createCrawlResponse($response, $crawlUrl, ''), $block);
            if ($crawlUrl->depth === 0) {
                $parser->observer->notCrawlableReason = match ($block) {
                    AutomatedCheckBlockReason::BotProtection => NotCrawlableReason::BotProtection,
                    AutomatedCheckBlockReason::RateLimited => NotCrawlableReason::RateLimited,
                };
            }
            $this->crawler->recordCrawled();

            return;
        }
        $robots = new CrawlerRobots($response->getHeaders(), $body, $this->crawler->mustRespectRobots());
        if (! $robots->mayFollow()) {
            $parser = $this->crawler->getUrlParser();
            if (! $parser instanceof CrawlUrlParser) {
                throw new \LogicException('Nofollow handling requires the crawl URL parser.');
            }
            $parser->observer->excludePageFromLinkCoverage($crawlUrl->url, array_last($response->getHeader('X-Guzzle-Redirect-History')) ?? $crawlUrl->url);
        }
        if ($crawlUrl->depth === 0) {
            $parser = $this->crawler->getUrlParser();
            if (! $parser instanceof CrawlUrlParser) {
                throw new \LogicException('Root response classification requires the crawl URL parser.');
            }
            $finalUrl = array_last($response->getHeader('X-Guzzle-Redirect-History')) ?? $crawlUrl->url;
            $reason = HtmlPage::unavailableReason($response->getStatusCode(), $response->getHeaderLine('Content-Type'), $body, $crawlUrl->url, $finalUrl);
            if ($reason === 'http_status') {
                $parser->observer->rootFailure = new FetchException(new FetchFailure(FetchFailureKind::HttpStatus, $response->getStatusCode()));
                $this->crawler->recordCrawled();

                return;
            }
            if ($reason === null && ! $robots->mayFollow()) {
                // Nofollow prohibits traversal, not inspecting the HTML already fetched.
                $parser->observer->inspectPage($crawlUrl->url, $this->createCrawlResponse($response, $crawlUrl, $body));
                $parser->incomplete(CrawlDiscoveryReason::Nofollow);
                $reason = 'nofollow';
            }
            if ($reason !== null) {
                if ($reason === 'authentication_required') {
                    $parser->observer->authenticationRequired($response->getStatusCode());
                } else {
                    $parser->observer->notCrawlableReason = NotCrawlableReason::from($reason);
                }
                $this->crawler->recordCrawled();

                return;
            }
        }
        if ($response->getStatusCode() >= 400) {
            // Retain ordinary HTTP failures, but never follow links from their error page.
            if (! in_array($response->getStatusCode(), [404, 410], true)) {
                $parser->httpErrorPage($crawlUrl->url, $response->getStatusCode());
            }
            $parser->observer->crawled($crawlUrl->url, $this->createCrawlResponse($response, $crawlUrl, ''), $this->crawler->getCrawlProgress());
            $this->crawler->recordCrawled();

            return;
        }
        $mime = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'), 2)[0]));
        $isSuccessfulHtml = $response->getStatusCode() >= 200 && $response->getStatusCode() < 300 && in_array($mime, ['text/html', 'application/xhtml+xml'], true);
        if (! $robots->mayIndex() && $isSuccessfulHtml) {
            $parser = $this->crawler->getUrlParser();
            if (! $parser instanceof CrawlUrlParser) {
                throw new \LogicException('Crawl response handling requires the crawl URL parser.');
            }
            // Spatie skips crawled() for noindex pages; retain coverage without creating a finding.
            $parser->observer->inspectPage($crawlUrl->url, $this->createCrawlResponse($response, $crawlUrl, $body));
        }
        if ($isSuccessfulHtml && ! $robots->mayFollow()) {
            $parser = $this->crawler->getUrlParser();
            if (! $parser instanceof CrawlUrlParser) {
                throw new \LogicException('Crawl response handling requires the crawl URL parser.');
            }
            $parser->incomplete(CrawlDiscoveryReason::Nofollow);
        }
        parent::handle($response, $index);
    }

    protected function getBaseUrl(ResponseInterface $response, CrawlUrl $crawlUrl): string
    {
        $parser = $this->crawler->getUrlParser();
        if (! $parser instanceof CrawlUrlParser) {
            throw new \LogicException('Crawl response handling requires the crawl URL parser.');
        }
        $parser->depth = $crawlUrl->depth;

        return parent::getBaseUrl($response, $crawlUrl);
    }
}
