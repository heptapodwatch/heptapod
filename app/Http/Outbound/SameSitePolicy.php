<?php

declare(strict_types=1);

namespace Heptapod\Http\Outbound;

use Heptapod\Contracts\Http\SameSite;
use Heptapod\Contracts\Http\TargetHost;
use Heptapod\Contracts\PublicSuffixList;
use Pdp\Domain;
use Uri\Rfc3986\Uri;

/** Crawl coverage allows a registrable apex and its www alias, never arbitrary sibling subdomains. */
final readonly class SameSitePolicy implements SameSite
{
    public function __construct(private PublicSuffixList $suffixes) {}

    public function isSameSite(string $siteUrl, string $candidateUrl): bool
    {
        $site = new Uri($siteUrl);
        $candidate = new Uri($candidateUrl);
        $sitePort = $site->getPort() ?? ($site->getScheme() === 'https' ? 443 : 80);
        $candidatePort = $candidate->getPort() ?? ($candidate->getScheme() === 'https' ? 443 : 80);
        $sameTransport = $candidate->getScheme() === $site->getScheme() && $candidatePort === $sitePort;
        $upgrade = $site->getScheme() === 'http' && $sitePort === 80
            && $candidate->getScheme() === 'https' && $candidatePort === 443;
        if (! $sameTransport && ! $upgrade) {
            return false;
        }
        $siteHost = TargetHost::fromUrl($siteUrl);
        $candidateHost = TargetHost::fromUrl($candidateUrl);
        if ($candidateHost === $siteHost) {
            return true;
        }
        $apex = str_starts_with($siteHost, 'www.') ? substr($siteHost, 4) : $siteHost;
        if (! in_array($candidateHost, [$apex, 'www.'.$apex], true)) {
            return false;
        }
        $domain = $this->suffixes->rules()->resolve(Domain::fromIDNA2008($apex));

        return $domain->suffix()->isKnown() && $domain->registrableDomain()->toString() === $apex;
    }
}
