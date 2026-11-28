<?php

declare(strict_types=1);

namespace Heptapod\Addons\Crawl;

use Heptapod\Contracts\SecretRedactor;
use League\Uri\Contracts\UriException;
use League\Uri\Uri;

final class CrawlWhitelist
{
    /** @var array<string, true> */
    private array $exact = [];

    /** @var list<string> */
    private array $patterns = [];

    public bool $ignoredInvalid = false;

    /** @param list<string> $entries */
    public function __construct(array $entries)
    {
        foreach ($entries as $entry) {
            if (! self::valid($entry)) {
                $this->ignoredInvalid = true;

                continue;
            }
            if (str_contains($entry, '*')) {
                $pattern = $this->normalizePattern($entry);
                $this->patterns[] = '#\A'.str_replace('\*', '.*', preg_quote($pattern, '#')).'\z#s';
            } elseif (($canonical = self::canonical($entry)) !== null) {
                $this->exact[$canonical] = true;
            }
        }
    }

    /** @param list<string> $entries
     * @param  array<array-key, mixed>  $secrets
     * @return list<string>
     */
    public static function redact(array $entries, array $secrets, string $siteUrl): array
    {
        return array_map(static fn (string $url): string => BrowserUrl::comparableRedacted(SecretRedactor::redact($url, $secrets, [$siteUrl])), $entries);
    }

    public static function valid(mixed $entry, bool $allowLegacy = false): bool
    {
        if (! is_string($entry) || $entry === '' || $entry !== trim($entry) || strlen($entry) > 1024 || preg_match('/[\x00-\x1f\x7f]/', $entry) === 1) {
            return false;
        }
        if ($allowLegacy || str_contains($entry, '*')) {
            return true;
        }
        try {
            $normalized = BrowserUrl::normalize($entry);
            if ($normalized === null) {
                return false;
            }
            $uri = Uri::new($normalized);
        } catch (UriException|\InvalidArgumentException) {
            return false;
        }

        return in_array($uri->getScheme(), ['http', 'https'], true) && $uri->getHost() !== null;
    }

    public function matches(string $url): bool
    {
        return $this->match($url) !== null;
    }

    /** A pattern wins over an exact entry: removing the exact entry would not restore the finding. */
    public function match(string $url): ?string
    {
        if (($this->exact === [] && $this->patterns === []) || ($normalized = BrowserUrl::normalize(BrowserUrl::comparableRedacted($url))) === null) {
            return null;
        }
        $canonical = self::trimPath($normalized);
        foreach ($this->patterns as $pattern) {
            if (preg_match($pattern, $normalized) === 1 || ($normalized !== $canonical && preg_match($pattern, $canonical) === 1)) {
                return 'pattern';
            }
        }

        return isset($this->exact[$canonical]) ? 'exact' : null;
    }

    public static function canonical(string $url): ?string
    {
        $normalized = BrowserUrl::normalize($url);

        return $normalized === null ? null : self::trimPath($normalized);
    }

    private function normalizePattern(string $pattern): string
    {
        $normalized = BrowserUrl::normalize($pattern);
        if ($normalized !== null) {
            return $normalized;
        }

        // A leading wildcard need not be a URL, but its path and query still use URL encoding.
        $schemeWildcard = str_starts_with($pattern, '*://');
        try {
            $uri = Uri::new($schemeWildcard ? substr($pattern, 2) : $pattern)->withFragment(null);

            return ($schemeWildcard ? '*:' : '').$uri->toString();
        } catch (UriException) {
            return str_replace(' ', '%20', explode('#', $pattern, 2)[0]);
        }
    }

    private static function trimPath(string $normalized): string
    {
        $uri = Uri::new($normalized);
        $path = rtrim($uri->getPath(), '/');

        return $uri->withPath($path === '' ? '/' : $path)->toString();
    }
}
