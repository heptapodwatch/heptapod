<?php

declare(strict_types=1);

namespace Heptapod\Checks;

use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Uri;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\Http\FetchFailure;
use Heptapod\Contracts\Http\FetchRequest;
use Heptapod\Contracts\Http\FetchResult;
use Heptapod\Contracts\Http\HtmlPage;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Contracts\Http\RedirectPolicy;
use Heptapod\Contracts\Http\SitemapLocations;
use Heptapod\Data\Ui\SiteProbeData;

final readonly class SiteProbe
{
    public function __construct(private OutboundHttp $http, private CheckRegistry $registry) {}

    public function __invoke(string $url): SiteProbeData
    {
        $now = CarbonImmutable::now('UTC');
        $context = new CheckContext(0, 0, 0, '', $url, $now, $now->addSeconds(12), [], [], null);
        $response = $this->http->fetch($this->request($url, true), $context);
        if ($response->failure instanceof FetchFailure) {
            $reason = $response->failure->summary();

            return new SiteProbeData(null, null, null, null, null, null, null, 'probe_failed', false, $this->recommendedChecks($url), normalised_url: $url,
                failure_reason: __($reason['key'], $reason['params']));
        }
        $finalUrl = $response->effectiveUrl ?? throw new \LogicException('A successful site probe must expose its final URL.');
        $contentType = '';
        foreach ($response->headers as $name => $values) {
            if (strtolower($name) === 'content-type') {
                $contentType = implode(',', $values);
            }
        }
        $publicHtml = HtmlPage::unavailableReason($response->status, $contentType, $response->body, $url, $finalUrl) === null;
        $sitemapUrl = null;
        if ($publicHtml) {
            $candidate = (string) new Uri($finalUrl)->withPath('/sitemap.xml')->withQuery('')->withFragment('');
            $sitemap = $this->http->fetch($this->request($candidate, false), $context);
            if ($sitemap->status === 200 && ! $sitemap->failure instanceof FetchFailure) {
                $sitemapUrl = $sitemap->effectiveUrl;
            } elseif ($sitemap->status === 404) {
                $robotsUrl = (string) new Uri($finalUrl)->withPath('/robots.txt')->withQuery('')->withFragment('');
                $robots = $this->http->fetch($this->request($robotsUrl, false), $context);
                if (! $robots->failure instanceof FetchFailure) {
                    foreach (SitemapLocations::fromRobots($robots->body, 5) as $location) {
                        if (filter_var($location, FILTER_VALIDATE_URL) === false || ! in_array(parse_url($location, PHP_URL_SCHEME), ['http', 'https'], true)) {
                            continue;
                        }
                        $sitemap = $this->http->fetch($this->request($location, false), $context);
                        if ($sitemap->status === 200 && ! $sitemap->failure instanceof FetchFailure) {
                            $sitemapUrl = $sitemap->effectiveUrl;
                            break;
                        }
                    }
                }
            }
        }
        $server = $response->headers['Server'][0] ?? $response->headers['server'][0] ?? null;

        return new SiteProbeData(
            $finalUrl,
            $response->status,
            is_string($server) ? substr($server, 0, 120) : null,
            $response->certificate?->issuer,
            $response->certificate?->validTo,
            $response->certificateUnavailableReason,
            $sitemapUrl,
            null,
            $publicHtml,
            $this->recommendedChecks($url, $publicHtml, $sitemapUrl),
            normalised_url: $url,
        );
    }

    /** @return list<CheckKey> */
    public function recommendedChecks(string $url, bool $publicHtml = false, ?string $sitemapUrl = null): array
    {
        return array_values(array_filter($this->registry->keys(), function (CheckKey $key) use ($url, $publicHtml, $sitemapUrl): bool {
            $definition = $this->registry->get($key)->definition();
            if ($this->registry->availability($key)->reason !== null || ! $definition->recommended || ($definition->requiresHttps && ! str_starts_with($url, 'https://'))) {
                return false;
            }

            return match ($key) {
                CheckKey::BrokenLinks, CheckKey::MixedContent, CheckKey::Lighthouse => $publicHtml,
                CheckKey::Sitemap => $publicHtml && $sitemapUrl !== null,
                default => true,
            };
        }));
    }

    /** @param array<array-key, string|non-empty-array<string>> $headers */
    public function fetch(string $url, #[\SensitiveParameter] array $headers): FetchResult
    {
        $now = CarbonImmutable::now('UTC');
        $context = new CheckContext(0, 0, 0, '', $url, $now, $now->addSeconds(12), [], [], null);

        return $this->http->fetch($this->request($url, false, $headers, maxRedirects: 0, acceptAllStatuses: true), $context);
    }

    /** @param array<array-key, string|non-empty-array<string>> $headers */
    private function request(string $url, bool $certificate, #[\SensitiveParameter] array $headers = [], int $maxRedirects = 3, bool $acceptAllStatuses = false): FetchRequest
    {
        return new FetchRequest($url, headers: $headers, maxWireBytes: HtmlPage::MAX_HTML_BYTES, maxDecodedBytes: HtmlPage::MAX_HTML_BYTES,
            redirects: RedirectPolicy::Untrusted, maxRedirects: $maxRedirects, timeoutSeconds: 8,
            acceptedStatuses: $certificate || $acceptAllStatuses ? range(200, 599) : [200],
            withCertificateInfo: $certificate, publicTargetsOnly: true);
    }
}
