<?php

declare(strict_types=1);

namespace Heptapod\Addons\Crawl;

use Heptapod\Contracts\SecretRedactor;
use Illuminate\Support\Str;
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
            if (! self::valid($entry) || ($canonical = self::canonical($entry)) === null) {
                $this->ignoredInvalid = true;

                continue;
            }
            if (str_contains($entry, '*')) {
                $this->patterns[] = $canonical;
            } else {
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

    public static function valid(mixed $entry): bool
    {
        if (! is_string($entry) || $entry === '' || $entry !== trim($entry) || strlen($entry) > 1024 || preg_match('/[\x00-\x1f\x7f]/', $entry) === 1) {
            return false;
        }
        if (str_contains($entry, '*')) {
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
        if (($this->exact === [] && $this->patterns === []) || ($target = self::canonical(BrowserUrl::comparableRedacted($url))) === null) {
            return null;
        }
        if (array_any($this->patterns, static fn (string $pattern): bool => Str::is($pattern, $target))) {
            return 'pattern';
        }

        return isset($this->exact[$target]) ? 'exact' : null;
    }

    public static function canonical(string $url): ?string
    {
        try {
            $normalized = BrowserUrl::normalize($url);
            if ($normalized === null) {
                return str_contains($url, '*') ? str_replace(' ', '%20', explode('#', $url, 2)[0]) : null;
            }
            $uri = Uri::new($normalized);
        } catch (UriException|\InvalidArgumentException) {
            return str_contains($url, '*') ? str_replace(' ', '%20', explode('#', $url, 2)[0]) : null;
        }
        $path = $uri->getPath();

        return $uri->withPath($path === '' || $path === '/' ? '/' : rtrim($path, '/'))->toString();
    }
}
