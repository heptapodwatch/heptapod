<?php

declare(strict_types=1);

namespace Heptapod\Addons\Crawl;

use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Pool;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRunPresentationPartData;
use Heptapod\Contracts\Http\AutomatedCheckBlock;
use Heptapod\Contracts\Http\AutomatedCheckBlockReason;
use Heptapod\Contracts\Http\FetchException;
use Heptapod\Contracts\Http\FetchFailure;
use Heptapod\Contracts\Http\FetchFailureKind;
use Heptapod\Contracts\Http\FetchRequest;
use Heptapod\Contracts\Http\FetchResult;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Contracts\Http\RedirectPolicy;
use Heptapod\Contracts\Http\TargetHost;
use Heptapod\Contracts\SiteCheckSettingsReader;
use Illuminate\Cache\RedisStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use League\Uri\Uri;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Spatie\Crawler\Crawler;
use Spatie\Crawler\Enums\FinishReason;
use Spatie\Crawler\Enums\ResourceType;
use Spatie\Crawler\UrlParsers\LinkUrlParser;
use Uri\WhatWg\Url;

final readonly class CrawlCollector
{
    // Recollect cached crawls that lack observed-page coverage and declared discovery reasons.
    // 12: retain oversized-start metadata even when no final URL is available.
    private const int HTML_SCAN_VERSION = 12;

    public function __construct(private OutboundHttp $http, private SiteCheckSettingsReader $settingsReader) {}

    public function ensureFresh(CheckContext $context): CrawlArtifact
    {
        $settings = $this->settingsReader->read($context->siteId, CheckKey::BrokenLinks);
        $config = array_replace(BrokenLinksCheck::configSchema()->defaults(), $settings?->enabled === true ? $settings->config : []);
        /** @var array{max_depth: int, max_pages: int, exclude_patterns: list<string>, do_not_crawl_urls: list<string>, force_crawl_urls: list<string>, check_include_external_links: bool, types: list<string>, respect_robots: bool} $config */
        $crawlSettings = array_diff_key($config, array_flip(['whitelisted_urls', 'new_links_only']));
        $runtimeSettings = array_diff_key(Config::array('heptapod.crawl'), array_flip(['defaults', 'interval_seconds', 'lock_wait_seconds']));
        $fingerprint = hash_hmac('sha256', json_encode([self::HTML_SCAN_VERSION, $crawlSettings, $context->crawlerHeaders, $runtimeSettings], JSON_THROW_ON_ERROR), Crypt::getKey());
        $cache = Cache::store('redis')->getStore();
        if (! $cache instanceof RedisStore) {
            throw new \LogicException('Crawl coordination requires Redis.');
        }

        /** @var CrawlArtifact */
        return $cache->lock('crawl:site:'.$context->siteId, Config::integer('heptapod.crawl.timeout_seconds') + 30)
            ->block(Config::integer('heptapod.crawl.lock_wait_seconds'), function () use ($context, $config, $fingerprint): CrawlArtifact {
                $cached = DB::table('crawl_collections')->where('site_id', $context->siteId)->value('artifact');
                if (! $context->manualRun && ! $context->confirmsAlert && is_string($cached)) {
                    $artifact = CrawlArtifact::from(json_decode($cached, true, flags: JSON_THROW_ON_ERROR));
                    if ($this->fresh($artifact, $context, $fingerprint)) {
                        $artifact->notes[] = new CheckRunPresentationPartData('crawl.collection_reused', ['date' => $artifact->collected_at]);

                        return $artifact;
                    }
                }
                $targetUrl = new Url($context->url)->withFragment(null)->toAsciiString();
                $observer = new CrawlObserver($targetUrl, $this->http, $context, $config['max_pages']);
                $headers = [];
                foreach ($context->crawlerHeaders as $header) {
                    $headers[$header['name']][] = $header['value'];
                }
                $request = new FetchRequest(
                    $targetUrl,
                    headers: $headers,
                    maxWireBytes: Config::integer('heptapod.crawl.max_response_bytes'),
                    maxDecodedBytes: Config::integer('heptapod.crawl.max_response_bytes'),
                    redirects: RedirectPolicy::Untrusted,
                    siteHost: TargetHost::fromUrl($context->url),
                    timeoutSeconds: Config::integer('heptapod.crawl.request_timeout_seconds'),
                    acceptedStatuses: range(200, 599),
                    // A refused start page still errors the run through CrawlTargetUnavailable.
                    refusalIsFinding: true,
                );
                $clientConfig = $this->http->clientConfig($request, $context);
                $stack = $clientConfig['handler'];
                if (! $stack instanceof HandlerStack) {
                    throw new \LogicException('Crawl redirect policy requires a handler stack.');
                }
                // Mark only the start request outside Guzzle's redirect loop; judge every hop against the configured site.
                $stack->before('allow_redirects', static fn (callable $next): \Closure => static function (RequestInterface $message, array $options) use ($next, $targetUrl): PromiseInterface {
                    $options['crawl_start_request'] = (string) $message->getUri() === $targetUrl;

                    /** @var callable(RequestInterface, array<array-key, mixed>): PromiseInterface $next */
                    return $next($message, $options);
                }, 'crawl_start_request');
                $stack->after('allow_redirects', static fn (callable $next): \Closure => static function (RequestInterface $message, array $options) use ($next, $targetUrl, $context): PromiseInterface {
                    if (($options['crawl_start_request'] ?? false) && ! BrowserUrl::isSameSite((string) $message->getUri(), $targetUrl)) {
                        throw new CrawlStartRedirectOffSite((string) $message->getUri());
                    }
                    if (($options['crawl_start_request'] ?? false) && $context->crawlerHeaders !== [] && ! Uri::new($targetUrl)->isSameOrigin((string) $message->getUri())) {
                        throw new CrawlStartRedirectOriginChanged((string) $message->getUri());
                    }

                    /** @var callable(RequestInterface, array<array-key, mixed>): PromiseInterface $next */
                    return $next($message, $options);
                }, 'crawl_start_redirect_policy');
                $crawler = QueueCrawler::create($targetUrl, $clientConfig)
                    ->depth($config['max_depth'])->limit($config['max_pages'])
                    ->concurrency(Config::integer('heptapod.crawl.concurrency'))
                    ->timeLimit(max(1, (int) floor($context->deadline->getTimestampMs() / 1000 - microtime(true))))
                    ->shouldStopCallback(static function (Crawler $crawler) use ($context): bool {
                        $context->runControl?->assertActive();

                        return CarbonImmutable::now()->greaterThanOrEqualTo($context->deadline);
                    })
                    ->maxResponseSizeInBytes(Config::integer('heptapod.crawl.max_response_bytes'))
                    ->neverCrawl([...$config['exclude_patterns'], ...$config['do_not_crawl_urls']])
                    ->alwaysCrawl($config['force_crawl_urls'])
                    ->shouldCrawl(static fn (string $url): bool => BrowserUrl::isSameSite($url, $targetUrl))
                    ->addObserver($observer)->fulfilledHandler(CrawlResponseHandler::class)->failedHandler(CrawlFailureHandler::class);
                $parser = new CrawlUrlParser(Uri::new($targetUrl), new LinkUrlParser(resourceTypes: array_map(static fn (string $type): ResourceType => $type === 'og:image' ? ResourceType::OpenGraphImage : ResourceType::from($type), $config['types'])), $config['max_depth'], $config['check_include_external_links'] ? Config::integer('heptapod.crawl.max_status_urls') : 0, Config::integer('heptapod.crawl.max_status_urls'), $observer);
                $parser->discovered[$targetUrl] = true;
                $crawler->urlParser($parser);
                if (! $config['respect_robots']) {
                    $crawler->ignoreRobots();
                }
                $context->runProgress?->report(0, $config['max_pages'], 'crawl');
                $reason = $crawler->start();
                $context->runControl?->assertActive();
                $timeLimitReached = $this->rootOutcome($observer, $crawler, $reason, $targetUrl, $context);
                $checkedUrls = array_fill_keys(array_column($observer->urls, 'crawled_url'), true);
                $candidates = array_values([...$parser->resources, ...$parser->external]);
                $pending = [];
                $results = [];
                $responseLabels = [];
                $requests = function () use ($candidates, $crawler, $targetUrl, $headers, $context, $parser, $observer, &$checkedUrls, &$pending): \Generator {
                    foreach ($candidates as $index => $candidate) {
                        $url = $candidate['url'];
                        if (isset($checkedUrls[$url->url])) {
                            continue;
                        }
                        $forced = $crawler->matchesAlwaysCrawl($url->url);
                        if (! $forced && $crawler->matchesNeverCrawl($url->url)) {
                            continue;
                        }
                        $sameSite = BrowserUrl::isSameSite($url->url, $targetUrl);
                        if ($sameSite && ! $forced && $crawler->mustRespectRobots() && ! ($crawler->getRobotsTxt() ?? throw new \LogicException('Missing crawl robots policy.'))->allows($url->url, $crawler->getUserAgent())) {
                            continue;
                        }
                        if (microtime(true) >= $context->deadline->getTimestampMs() / 1000) {
                            $parser->truncated = true;
                            $parser->incomplete(CrawlDiscoveryReason::TimeLimit);
                            break;
                        }
                        if (Uri::new($url->url)->getUserInfo() !== null) {
                            $observer->external($url->url, new FetchResult(failure: new FetchFailure(FetchFailureKind::RedirectBlocked)), $candidate['found_on_url'], $url->linkText, $url->resourceType);

                            continue;
                        }
                        $pending[$index] = $candidate;
                        $checkedUrls[$url->url] = true;
                        // Sharing crawl coverage never grants configured headers to a different origin.
                        yield $index => new Request('GET', $url->url, Uri::new($targetUrl)->isSameOrigin($url->url) ? $headers : []);
                    }
                };
                // Addon-owned pool uses OutboundHttp's guarded config; concurrency differs from the paced sitemap client.
                $client = new Client($this->http->clientConfig($request, $context));
                new Pool($client, $requests(), [
                    'concurrency' => Config::integer('heptapod.crawl.concurrency'),
                    'fulfilled' => static function (ResponseInterface $response, int $index) use (&$results, &$responseLabels, &$pending, $targetUrl): void {
                        $responseLabels[$index] = LinkClassificationPolicy::responseLabel(BrowserUrl::isSameSite($pending[$index]['url']->url, $targetUrl), $response->getStatusCode(), $response->getHeaders(), $response->getBody()->read(AutomatedCheckBlock::BODY_PREFIX_BYTES));
                        $results[$index] = new FetchResult($response->getStatusCode());
                        $response->getBody()->close();
                    },
                    'rejected' => static function (mixed $reason, int $index) use (&$results): void {
                        if (! $reason instanceof FetchException) {
                            throw $reason instanceof \Throwable ? $reason : new \LogicException('Status request rejected without an exception.');
                        }
                        $results[$index] = new FetchResult(status: $reason->failure->status, failure: $reason->failure);
                    },
                ])->promise()->wait();
                ksort($results);
                foreach ($results as $index => $result) {
                    $context->runControl?->assertActive();
                    $candidate = $pending[$index];
                    $url = $candidate['url'];
                    if ($result->failure?->kind === FetchFailureKind::TooLarge) {
                        $getStatus = $result->status;
                        $result = $this->http->fetch(new FetchRequest($url->url, method: 'HEAD', maxWireBytes: $request->maxWireBytes, maxDecodedBytes: $request->maxDecodedBytes, redirects: RedirectPolicy::Untrusted, siteHost: $request->siteHost, timeoutSeconds: $request->timeoutSeconds, refusalIsFinding: true), $context);
                        if ($getStatus !== null) {
                            $result = new FetchResult($getStatus);
                        }
                        if ($result->status !== null && ! $result->failure instanceof FetchFailure) {
                            $responseLabels[$index] = LinkClassificationPolicy::responseLabel(BrowserUrl::isSameSite($url->url, $targetUrl), $result->status, [], '');
                        }
                    }
                    if ($result->failure?->kind === FetchFailureKind::Timeout && microtime(true) >= $context->deadline->getTimestampMs() / 1000) {
                        $parser->truncated = true;
                        $parser->incomplete(CrawlDiscoveryReason::TimeLimit);

                        continue;
                    }
                    $observer->external($url->url, $result, $candidate['found_on_url'], $url->linkText, $url->resourceType, $responseLabels[$index] ?? null);
                }
                $context->runProgress?->report(min(count($observer->observedPages), $config['max_pages']), $config['max_pages'], 'crawl');
                $pageCapReached = $reason === FinishReason::CrawlLimitReached && $crawler->getCrawlProgress()->urlsPending > 0;
                $timeLimitReached = $timeLimitReached || microtime(true) >= $context->deadline->getTimestampMs() / 1000;
                $rateLimited = array_any($observer->urls, static fn (CrawlFinding $finding): bool => $finding->error_message === AutomatedCheckBlockReason::RateLimited->value);
                $truncated = $rateLimited || $timeLimitReached || $pageCapReached || $parser->truncated || $observer->invalidTruncated || ! in_array($targetUrl, $observer->observedPages, true);
                foreach ([
                    CrawlDiscoveryReason::PageLimit->value => $pageCapReached,
                    CrawlDiscoveryReason::TimeLimit->value => $timeLimitReached,
                    CrawlDiscoveryReason::ResourceLimit->value => $parser->resourceCapReached,
                    CrawlDiscoveryReason::InvalidUrlLimit->value => $observer->invalidTruncated,
                    CrawlDiscoveryReason::RateLimited->value => $rateLimited,
                    CrawlDiscoveryReason::StartPageUnavailable->value => ! in_array($targetUrl, $observer->observedPages, true),
                ] as $cause => $applies) {
                    if ($applies) {
                        $parser->incomplete(CrawlDiscoveryReason::from($cause));
                    }
                }
                $observedUrls = array_values(array_unique([...array_column($observer->urls, 'crawled_url'), ...$observer->observedPages]));
                foreach ($observer->unreadablePages as $unreadable) {
                    $parser->incomplete($unreadable);
                }
                $httpErrorStatuses = array_values(array_unique($parser->httpErrorPages));
                sort($httpErrorStatuses);
                $notes = array_values(array_map(static fn (CrawlDiscoveryReason $reason): CheckRunPresentationPartData => $reason->note(match ($reason) {
                    CrawlDiscoveryReason::HttpErrorPages => ['count' => count($parser->httpErrorPages), 'statuses' => implode(', ', $httpErrorStatuses)],
                    CrawlDiscoveryReason::PageTooLarge => ['count' => count(array_filter($observer->unreadablePages, static fn (CrawlDiscoveryReason $unreadable): bool => $unreadable === CrawlDiscoveryReason::PageTooLarge))],
                    default => [],
                }), $parser->discoveryReasons));
                $depthExcluded = count(array_diff(array_keys($parser->depthExcluded), $observedUrls));
                if ($depthExcluded > 0) {
                    $notes[] = new CheckRunPresentationPartData('crawl.coverage.depth_limit', ['count' => $depthExcluded, 'depth' => $config['max_depth']]);
                }
                $artifact = new CrawlArtifact($targetUrl, CarbonImmutable::now()->toIso8601String(), $config, $observer->urls, $observer->mixed, $this->targetFingerprint($context->url), $truncated, $observedUrls, $observer->observedPages, array_keys($parser->discovered), ! $truncated && $parser->discoveryReasons === [], $pageCapReached, $timeLimitReached, $parser->resourceCapReached, $fingerprint, $observer->notCrawlableReason, $observer->nofollowPages, $notes, LinkClassificationPolicy::VERSION, $observer->startPageFinalUrl, $observer->startPageTooLarge)->redact(array_column($context->crawlerHeaders, 'value'));
                DB::table('crawl_collections')->updateOrInsert(['site_id' => $context->siteId], ['artifact' => json_encode($artifact->privateStorage(), JSON_THROW_ON_ERROR), 'collected_at' => $artifact->collected_at]);

                return $artifact;
            });
    }

    /**
     * Root fetch failure precedes interruption, robots, explicit exclusion and unexplained emptiness.
     *
     * @return bool Whether the collection reached its time limit.
     */
    private function rootOutcome(CrawlObserver $observer, Crawler $crawler, FinishReason $reason, string $targetUrl, CheckContext $context): bool
    {
        if ($observer->rootFailure instanceof FetchException) {
            throw new CrawlTargetUnavailable($observer->rootFailure);
        }
        $timeLimitReached = $reason === FinishReason::TimeLimitReached || CarbonImmutable::now()->greaterThanOrEqualTo($context->deadline) || microtime(true) >= $context->deadline->getTimestampMs() / 1000;
        if ($reason === FinishReason::Interrupted && ! $timeLimitReached) {
            throw new \RuntimeException('Crawl was interrupted.');
        }
        $nothingObserved = $observer->urls === [] && $observer->observedPages === [];
        if ($nothingObserved && ! $observer->notCrawlableReason instanceof NotCrawlableReason && $crawler->mustRespectRobots() && ! ($crawler->getRobotsTxt() ?? throw new \LogicException('Missing crawl robots policy.'))->allows($targetUrl, $crawler->getUserAgent())) {
            $observer->notCrawlableReason = NotCrawlableReason::RobotsDisallowed;
        }
        // Robots is reported first: removing the exclusion would not unblock a root that robots.txt also disallows.
        if ($nothingObserved && ! $observer->notCrawlableReason instanceof NotCrawlableReason && ! $crawler->matchesAlwaysCrawl($targetUrl) && $crawler->matchesNeverCrawl($targetUrl)) {
            $observer->notCrawlableReason = NotCrawlableReason::StartUrlExcluded;
        }
        if (! $timeLimitReached && ! $observer->notCrawlableReason instanceof NotCrawlableReason && ($nothingObserved || (isset($observer->urls[0]) && $observer->urls[0]->status_code === null && $observer->urls[0]->error_message !== 'private_target' && $observer->urls[0]->crawled_url === $targetUrl))) {
            throw new \RuntimeException('Crawl target could not be collected.');
        }

        return $timeLimitReached;
    }

    private function fresh(CrawlArtifact $artifact, CheckContext $context, string $fingerprint): bool
    {
        // Recollect cached informational outcomes that the former root policy misclassified.
        return $artifact->not_crawlable_reason !== NotCrawlableReason::NoIndex
            && ($artifact->not_crawlable_reason !== NotCrawlableReason::AuthenticationRequired || $context->crawlerHeaders === [])
            && $artifact->config_fingerprint !== null && hash_equals($artifact->config_fingerprint, $fingerprint) && hash_equals($artifact->target_fingerprint, $this->targetFingerprint($context->url)) && CarbonImmutable::parse($artifact->collected_at)->addSeconds(Config::integer('heptapod.crawl.interval_seconds'))->gt($context->startedAt);
    }

    private function targetFingerprint(#[\SensitiveParameter] string $url): string
    {
        return hash_hmac('sha256', new Url($url)->toAsciiString(), Crypt::getKey());
    }
}
