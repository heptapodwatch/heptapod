<?php

declare(strict_types=1);

namespace Heptapod\Addons\Crawl;

use GuzzleHttp\Exception\RequestException;
use Heptapod\Contracts\Http\FetchException;
use Heptapod\Contracts\Http\FetchFailureKind;
use Spatie\Crawler\Handlers\CrawlRequestFailed;

final class CrawlFailureHandler extends CrawlRequestFailed
{
    public function __invoke(\Exception $exception, mixed $index): void
    {
        $parser = $this->crawler->getUrlParser();
        if (! $parser instanceof CrawlUrlParser) {
            throw new \LogicException('Crawl failure handling requires the crawl URL parser.');
        }
        $status = null;
        $failure = null;
        for ($cause = $exception; $cause instanceof \Throwable; $cause = $cause->getPrevious()) {
            if ($cause instanceof CrawlStartRedirectOffSite || $cause instanceof CrawlStartRedirectOriginChanged) {
                throw $cause;
            }
            if ($cause instanceof FetchException) {
                $failure = $cause->failure;
                $status = $failure->kind === FetchFailureKind::HttpStatus ? $failure->status : null;
                break;
            }
            if ($cause instanceof RequestException && $cause->hasResponse()) {
                $status = $cause->getResponse()?->getStatusCode();
            }
        }
        // The observer classifies oversized responses after its HEAD probe; missing destinations are findings, not lost coverage.
        if ($failure?->kind !== FetchFailureKind::TooLarge && ! in_array($status, [404, 410], true)) {
            if ($status === null) {
                $parser->incomplete(CrawlDiscoveryReason::RequestFailed);
            } else {
                $parser->httpErrorPage($this->crawler->getCrawlQueue()->getUrlById($index)->url, $status);
            }
        }
        parent::__invoke($exception, $index);
    }
}
