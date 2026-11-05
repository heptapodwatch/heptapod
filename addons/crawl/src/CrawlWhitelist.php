<?php

declare(strict_types=1);

namespace Heptapod\Addons\Crawl;

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
            if (! self::valid($entry) || ($canonical = $this->canonical($entry)) === null) {
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
        if ($this->exact === [] && $this->patterns === []) {
            return false;
        }
        $target = $this->canonical($url);
        if ($target === null) {
            return false;
        }
        if (isset($this->exact[$target])) {
            return true;
        }

        return array_any($this->patterns, fn (string $pattern) => Str::is($pattern, $target));
    }

    private function canonical(string $url): ?string
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
