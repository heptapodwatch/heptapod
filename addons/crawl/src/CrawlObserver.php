<?php

declare(strict_types=1);

namespace Heptapod\Addons\Crawl;

use GuzzleHttp\Exception\RequestException;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\Http\AutomatedCheckBlockReason;
use Heptapod\Contracts\Http\FetchException;
use Heptapod\Contracts\Http\FetchFailure;
use Heptapod\Contracts\Http\FetchFailureKind;
use Heptapod\Contracts\Http\FetchRequest;
use Heptapod\Contracts\Http\FetchResult;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Contracts\Http\RedirectPolicy;
use Heptapod\Contracts\Http\TargetHost;
use Illuminate\Support\Facades\Config;
use Spatie\Crawler\CrawlObservers\CrawlObserver as SpatieObserver;
use Spatie\Crawler\CrawlProgress;
use Spatie\Crawler\CrawlResponse;
use Spatie\Crawler\Enums\ResourceType;
use Spatie\Crawler\TransferStatistics;
use Symfony\Component\DomCrawler\Crawler as DomCrawler;
use Uri\InvalidUriException;
use Uri\Rfc3986\Uri;
use Uri\WhatWg\Url;

final class CrawlObserver extends SpatieObserver
{
    private const int INVALID_URLS_PER_PAGE = 100;

    public ?FetchException $rootFailure = null;

    public ?string $startPageFinalUrl = null;

    public bool $startPageTooLarge = false;

    public ?NotCrawlableReason $notCrawlableReason = null;

    public bool $invalidTruncated = false;

    /** @var array<string, CrawlDiscoveryReason> Oversized responses whose links could not be extracted, by URL. */
    public array $unreadablePages = [];

    /** @var array<string, array<string, true>> */
    private array $invalidOnPage = [];

    /** @var list<CrawlFinding> */
    public array $urls = [];

    /** @var list<MixedContentFinding> */
    public array $mixed = [];

    /** @var list<string> */
    public array $observedPages = [];

    /** @var list<string> Pages inspected for mixed content without permission to follow their links. */
    public array $nofollowPages = [];

    public function __construct(private readonly string $siteUrl, private readonly OutboundHttp $http, private readonly CheckContext $context, private readonly int $pageCap) {}

    /** @param ?string $responseLabel LinkClassificationPolicy::responseLabel() of a received response */
    public function external(string $url, FetchResult $result, string $foundOn, ?string $text, ResourceType $type, ?string $responseLabel = null): void
    {
        // A non-PSR status (e.g. 999) arrives as an HttpStatus failure and is classified by record().
        $error = $result->failure instanceof FetchFailure && $result->failure->kind !== FetchFailureKind::HttpStatus ? LinkClassificationPolicy::failureLabel($result->failure) : $responseLabel;
        $this->record($url, $result->status, $foundOn, $text, $type, $error);
    }

    public function notVerifiable(string $url, CrawlResponse $response, AutomatedCheckBlockReason $reason): void
    {
        $this->context->runControl?->assertActive();
        $this->record($url, $response->status(), $response->foundOnUrl(), $response->linkText(), $response->resourceType(), $reason->value);
    }

    public function crawled(string $url, CrawlResponse $response, CrawlProgress $progress): void
    {
        $this->context->runControl?->assertActive();
        $this->record($url, $response->status(), $response->foundOnUrl(), $response->linkText(), $response->resourceType(), null);
        $this->inspectPage($url, $response);
    }

    public function inspectPage(string $url, CrawlResponse $response): void
    {
        $this->context->runControl?->assertActive();
        $pageUrl = array_last($response->redirectHistory()) ?? $url;
        $mime = strtolower(trim(explode(';', $response->header('Content-Type') ?? '', 2)[0]));
        if (! BrowserUrl::isSameSite($pageUrl, $this->siteUrl) || ! $response->isSuccessful() || ! in_array($mime, ['text/html', 'application/xhtml+xml'], true)) {
            return;
        }
        $this->scanMixedContent($url, $pageUrl, $response->body());
        // Observation is recovery evidence, so publish it only after the content scan completes.
        $this->observedPages = array_values(array_unique([...$this->observedPages, $this->safeUrl($url), $this->safeUrl($pageUrl)]));
        $this->context->runProgress?->report(min(count($this->observedPages), $this->pageCap), $this->pageCap, 'crawl');
    }

    private function scanMixedContent(string $url, string $pageUrl, string $body): void
    {
        if (parse_url($pageUrl, PHP_URL_SCHEME) !== 'https' || $body === '') {
            return;
        }
        $dom = new DomCrawler($body, $pageUrl);
        $base = $dom->filter('base[href]');
        $baseUrl = $pageUrl;
        if ($base->count() !== 0) {
            try {
                $baseUrl = new Uri($pageUrl)->resolve($base->attr('href') ?? '')->toRawString();
            } catch (InvalidUriException) {
                // A malformed base cannot make the rest of the page unavailable.
            }
        }
        // Inspect resources, not navigation links. Browser rendering is deliberately not used.
        foreach ($dom->filter('img[src], img[srcset], script[src], link[href], iframe[src], source[src], source[srcset], video[src], video[poster], audio[src], object[data], embed[src]') as $element) {
            if (! $element instanceof \DOMElement) {
                continue;
            }
            if ($element->tagName === 'link' && array_intersect(explode(' ', str_replace(["\t", "\n", "\r", "\f"], ' ', strtolower($element->getAttribute('rel')))), ['stylesheet', 'preload', 'modulepreload', 'icon']) === []) {
                continue;
            }
            $resources = [];
            foreach (['src', 'href', 'poster', 'data'] as $attribute) {
                if ($element->hasAttribute($attribute)) {
                    $resources[] = $element->getAttribute($attribute);
                }
            }
            foreach (explode(',', $element->getAttribute('srcset')) as $candidate) {
                $resource = preg_split('/\s+/', trim($candidate))[0] ?? '';
                if ($resource !== '') {
                    $resources[] = $resource;
                }
            }
            foreach ($resources as $resource) {
                try {
                    if (preg_match('/^https?:\/\//i', $resource) === 1 && preg_match('/[^\x00-\x7f]/', $resource) === 1) {
                        $resource = new Url($resource)->toAsciiString();
                    }
                    $resolved = new Uri($baseUrl)->resolve($resource)->toRawString();
                } catch (InvalidUriException) {
                    continue;
                }
                if (parse_url($resolved, PHP_URL_SCHEME) === 'http') {
                    $this->mixed[] = new MixedContentFinding($element->tagName, $this->safeUrl($resolved), $this->safeUrl($pageUrl), $this->safeUrl($url));
                }
            }
        }
    }

    public function excludePageFromLinkCoverage(string $url, string $finalUrl): void
    {
        $this->nofollowPages = array_values(array_unique([...$this->nofollowPages, $this->safeUrl($url), $this->safeUrl($finalUrl)]));
    }

    public function authenticationRequired(int $status): void
    {
        if ($this->context->crawlerHeaders !== []) {
            $this->rootFailure = new FetchException(new FetchFailure(FetchFailureKind::HttpStatus, $status));
        } else {
            $this->notCrawlableReason = NotCrawlableReason::AuthenticationRequired;
        }
    }

    public function crawlFailed(string $url, \Throwable $requestException, CrawlProgress $progress, ?string $foundOnUrl = null, ?string $linkText = null, ?ResourceType $resourceType = null, ?TransferStatistics $transferStats = null): void
    {
        $failure = null;
        for ($cause = $requestException; $cause instanceof \Throwable; $cause = $cause->getPrevious()) {
            if ($cause instanceof FetchException) {
                if ($cause->failure->kind === FetchFailureKind::TooLarge) {
                    $result = $this->http->fetch(new FetchRequest($url, method: 'HEAD', maxWireBytes: Config::integer('heptapod.crawl.max_response_bytes'), maxDecodedBytes: Config::integer('heptapod.crawl.max_response_bytes'), redirects: RedirectPolicy::Untrusted, siteHost: TargetHost::fromUrl($this->context->url), timeoutSeconds: Config::integer('heptapod.crawl.request_timeout_seconds'), refusalIsFinding: true), $this->context);
                    if ($url === $this->siteUrl) {
                        $this->startPageTooLarge = true;
                        $this->startPageFinalUrl = $result->effectiveUrl;
                    }
                    $mime = strtolower(trim(explode(';', $result->headers['Content-Type'][0] ?? '', 2)[0]));
                    // Only a successful HEAD naming a non-HTML type proves that no links were lost.
                    $confirmedNonHtml = ! $result->failure instanceof FetchFailure && $mime !== '' && ! in_array($mime, ['text/html', 'application/xhtml+xml'], true);
                    $headFailed = $result->failure instanceof FetchFailure && $result->failure->kind !== FetchFailureKind::HttpStatus;
                    if ($cause->failure->status !== null) {
                        $result = new FetchResult($cause->failure->status, headers: $result->headers);
                    }
                    $diagnostic = $confirmedNonHtml ? null : ($headFailed && $result->status === null ? 'request_failed' : 'page too large (links not extracted)');
                    if (! $confirmedNonHtml) {
                        $this->unreadablePages[$url] = CrawlDiscoveryReason::PageTooLarge;
                    }
                    $this->record($url, $result->status, $foundOnUrl, $linkText, $resourceType ?? ResourceType::Link, $diagnostic);

                    return;
                }
                $failure = $cause->failure;
                if ($url === $this->siteUrl && in_array($failure->status, [401, 403], true)) {
                    $this->authenticationRequired($failure->status);

                    return;
                }
                if ($url === $this->siteUrl) {
                    $this->rootFailure = $cause;
                }
                break;
            }
        }
        if (! $failure instanceof FetchFailure && $requestException instanceof RequestException && $requestException->hasResponse()) {
            $status = $requestException->getResponse()?->getStatusCode() ?? throw new \LogicException('HTTP failure response missing.');
            $this->record($url, $status, $foundOnUrl, $linkText, $resourceType ?? ResourceType::Link, null);

            return;
        }
        if (! $failure instanceof FetchFailure && $requestException->getPrevious() instanceof \Throwable) {
            throw $requestException->getPrevious();
        }
        $this->record($url, $failure?->status, $foundOnUrl, $linkText, $resourceType ?? ResourceType::Link, $failure instanceof FetchFailure ? LinkClassificationPolicy::failureLabel($failure) : 'request_failed');
    }

    public function invalid(string $url, string $foundOn, ?string $text, ResourceType $type): void
    {
        $withoutCredentials = preg_replace('~^(https?:)[\\\\/]*[^/\\\\?#]*@~i', '$1', $url) ?? throw new \LogicException('Invalid URL sanitization pattern.');
        $clean = preg_replace('/[\x00-\x1f\x7f]/', '', explode('#', $withoutCredentials, 2)[0]) ?? throw new \LogicException('Invalid URL sanitization pattern.');
        $label = BrowserUrl::boundedEvidenceUrl($clean);
        $source = $this->safeUrl($foundOn);
        if (isset($this->invalidOnPage[$source][$label])) {
            return;
        }
        if (count($this->invalidOnPage[$source] ?? []) >= self::INVALID_URLS_PER_PAGE || count($this->urls) >= CrawlPersistence::COMPARISON_LIMIT) {
            $this->invalidTruncated = true;

            return;
        }
        $this->invalidOnPage[$source][$label] = true;
        $this->urls[] = new CrawlFinding($label, $label, null, $source, $this->relative($source), $text, $type === ResourceType::OpenGraphImage ? 'og:image' : $type->value, 'invalid URL', false);
    }

    private function record(string $url, ?int $status, ?string $foundOn, ?string $text, ResourceType $type, ?string $error): void
    {
        $url = $this->safeUrl($url);
        $foundOn = $foundOn === null ? null : $this->safeUrl($foundOn);
        $internal = BrowserUrl::isSameSite($url, $this->siteUrl);
        // External responses can also arrive through the crawler queue; they get the same status policy.
        if (! $internal && $error === null && $status !== null && $status >= 400) {
            $error = LinkClassificationPolicy::responseLabel(false, $status, [], '');
        }
        $this->urls[] = new CrawlFinding($url, $this->relative($url), $status, $foundOn, $foundOn === null ? null : $this->relative($foundOn), $text, $type === ResourceType::OpenGraphImage ? 'og:image' : $type->value, $error, $internal);
    }

    private function safeUrl(string $url): string
    {
        return new Uri($url)->withUserInfo(null)->withFragment(null)->toRawString();
    }

    private function relative(string $url): string
    {
        $uri = new Uri($url);

        return $uri->getRawPath().($uri->getRawQuery() === null ? '' : '?'.$uri->getRawQuery());
    }
}
