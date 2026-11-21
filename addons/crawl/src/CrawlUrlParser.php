<?php

declare(strict_types=1);

namespace Heptapod\Addons\Crawl;

use League\Uri\Uri;
use Spatie\Crawler\Enums\ResourceType;
use Spatie\Crawler\ExtractedUrl;
use Spatie\Crawler\UrlParsers\UrlParser;

final class CrawlUrlParser implements UrlParser
{
    /** @var array<string, array{url: ExtractedUrl, found_on_url: string}> */
    public array $external = [];

    /** @var array<string, array{url: ExtractedUrl, found_on_url: string}> */
    public array $resources = [];

    public bool $truncated = false;

    public bool $resourceCapReached = false;

    public ?int $depth = null;

    /** @var array<string, CrawlDiscoveryReason> */
    public array $discoveryReasons = [];

    /** @var array<string, true> */
    public array $depthExcluded = [];

    public function incomplete(CrawlDiscoveryReason $reason): void
    {
        $this->discoveryReasons[$reason->value] = $reason;
    }

    /** @var array<string, int> URL => HTTP error status of pages whose links could not be searched. */
    public array $httpErrorPages = [];

    public function httpErrorPage(string $url, int $status): void
    {
        $this->httpErrorPages[$url] = $status;
        $this->incomplete(CrawlDiscoveryReason::HttpErrorPages);
    }

    /** @var array<string, true> */
    public array $discovered = [];

    public function __construct(private readonly Uri $origin, private readonly UrlParser $parser, private readonly int $maximumDepth, private readonly int $externalLimit, private readonly int $resourceLimit, public readonly CrawlObserver $observer) {}

    /** @return array<int, ExtractedUrl> */
    public function extractUrls(string $html, string $baseUrl): array
    {
        if (! BrowserUrl::isSameSite($baseUrl, $this->origin->toString())) {
            return [];
        }
        if ($this->depth === null) {
            throw new \LogicException('URL extraction requires traversal depth.');
        }
        $internal = [];
        foreach ($this->parser->extractUrls($html, $baseUrl) as $url) {
            if ($this->observer->artifactByteLimitReached) {
                $this->truncated = true;
                $this->resourceCapReached = true;
                break;
            }
            $boundedLinkText = $this->observer->boundLinkText($url->linkText);
            if ($url->isMalformed()) {
                $this->observer->invalid($url->url, $baseUrl, $boundedLinkText, $url->resourceType);

                continue;
            }
            if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $url->url) === 1 && preg_match('/^https?:/i', $url->url) !== 1) {
                continue;
            }
            $normalized = strlen($url->url) <= CrawlPersistence::MAX_URL_BYTES ? BrowserUrl::normalize($url->url, $baseUrl) : null;
            if ($normalized === null) {
                $this->observer->invalid($url->url, $baseUrl, $boundedLinkText, $url->resourceType);

                continue;
            }
            $url = new ExtractedUrl($normalized, $boundedLinkText, $url->resourceType);
            $sameSite = BrowserUrl::isSameSite($normalized, $this->origin->toString());
            if (BrowserUrl::isEmailProtection($normalized, $this->origin->toString())) {
                continue;
            }
            $discoveredUrl = Uri::new($url->url)->withUserInfo(null)->withFragment(null)->toString();
            if (! isset($this->discovered[$discoveredUrl]) && count($this->discovered) <= CrawlPersistence::COMPARISON_LIMIT) {
                if (! $this->observer->consumeArtifactBytes(strlen($discoveredUrl) + 3)) {
                    $this->truncated = true;
                    $this->resourceCapReached = true;
                    break;
                }
                $this->discovered[$discoveredUrl] = true;
            }
            if ($sameSite && $url->resourceType !== ResourceType::Link) {
                if (! isset($this->resources[$url->url]) && count($this->resources) >= $this->resourceLimit) {
                    $this->truncated = true;
                    $this->resourceCapReached = true;

                    continue;
                }
                $this->resources[$url->url] = ['url' => $url, 'found_on_url' => $baseUrl];
            } elseif ($sameSite) {
                if ($this->depth >= $this->maximumDepth && ! isset($this->depthExcluded[$url->url]) && count($this->depthExcluded) <= CrawlPersistence::COMPARISON_LIMIT) {
                    if (! $this->observer->consumeArtifactBytes(strlen($url->url) + 3)) {
                        $this->truncated = true;
                        $this->resourceCapReached = true;
                        break;
                    }
                    $this->depthExcluded[$url->url] = true;
                }
                $internal[] = $url;
            } elseif ($this->externalLimit > 0 && $this->depth < $this->maximumDepth) {
                if (isset($this->external[$url->url])) {
                    continue;
                }
                if (count($this->external) >= $this->externalLimit) {
                    $this->truncated = true;
                    $this->resourceCapReached = true;

                    continue;
                }
                $this->external[$url->url] = ['url' => $url, 'found_on_url' => $baseUrl];
            }
        }

        return $internal;
    }
}
