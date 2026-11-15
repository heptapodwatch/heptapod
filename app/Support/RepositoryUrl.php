<?php

declare(strict_types=1);

namespace Heptapod\Support;

/**
 * The one canonical form of a repository link, shared by the Site settings save, the API write and the API filter:
 * `https://host/path` with a lower-case ASCII host, without `.git` and trailing slash. SSH remotes (`git@host:org/repo.git`,
 * `ssh://git@host/org/repo.git`) convert, so `git remote get-url origin` can be passed unchanged.
 */
final class RepositoryUrl
{
    public const int MaxLength = 2048;

    public const int MaxPerSite = 10;

    /**
     * The validation rule of one repository URL. Given the submitted rows, a row whose canonical URL repeats an earlier
     * row fails as a duplicate, so the error sits on the later row.
     */
    public static function rule(mixed $rows = null): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail) use ($rows): void {
            if (! is_string($value)) {
                return;
            }
            if (self::hasCredentials($value)) {
                $fail(__('repositories.credentials'));

                return;
            }
            $canonical = self::canonical($value);
            if ($canonical === null) {
                $fail(__('repositories.invalid'));

                return;
            }
            if (! is_array($rows)) {
                return;
            }
            foreach (array_slice($rows, 0, (int) explode('.', $attribute)[1]) as $earlier) {
                if (is_array($earlier) && is_string($earlier['url'] ?? null) && self::canonical($earlier['url']) === $canonical) {
                    $fail(__('repositories.duplicate'));

                    return;
                }
            }
        };
    }

    /**
     * The canonical URL, or null for anything that is not a repository address (including embedded credentials).
     * Idempotent: a canonical URL is its own canonical form, so a stored link always finds itself through the filter.
     */
    public static function canonical(string $value): ?string
    {
        $parts = self::parts($value);
        if ($parts === null || self::credentials($parts) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        $path = $parts['path'] ?? '';
        do {
            $before = $path;
            $path = rtrim($path, '/');
            if (str_ends_with(strtolower($path), '.git')) {
                $path = substr($path, 0, -4);
            }
        } while ($path !== $before);
        $host = self::host($parts['host']);
        if ($path === '' || $host === null) {
            return null;
        }
        // An SSH port says nothing about the web address. The scheme's own default port goes, and so does 443, the
        // default of the stored https form; any other port (https on 80 included) is part of the address.
        $port = $parts['port'] ?? null;
        $keepPort = $parts['scheme'] !== 'ssh' && $port !== null && $port !== 443 && ($parts['scheme'] !== 'http' || $port !== 80);
        $url = 'https://'.$host.($keepPort ? ':'.$port : '').$path;

        return strlen($url) <= self::MaxLength ? $url : null;
    }

    /** A bracketed IP version 6 literal in lower case or a lower-case ASCII DNS name; null when invalid. */
    private static function host(string $host): ?string
    {
        if (str_starts_with($host, '[')) {
            $address = substr($host, 1, -1);

            return str_ends_with($host, ']') && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? '['.strtolower($address).']' : null;
        }
        $ascii = idn_to_ascii(rtrim($host, '.'), IDNA_DEFAULT | IDNA_USE_STD3_RULES | IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);

        return is_string($ascii) && $ascii !== '' ? $ascii : null;
    }

    /** True for `https://token@…` or `user:pass@…`; the SSH login name (`git@`) is not a credential. */
    public static function hasCredentials(string $value): bool
    {
        $parts = self::parts($value);

        return $parts !== null && self::credentials($parts);
    }

    /** @return array{scheme: 'http'|'https'|'ssh', host: string, port?: int, user?: string, pass?: string, path?: string, query?: string, fragment?: string}|null */
    private static function parts(string $value): ?array
    {
        $value = trim($value);
        // The scp form `user@host:path`; a bracketed IPv6 host keeps its colons.
        if (preg_match('~^[\w.-]+@(\[[^\]\s]+\]|[^\s:/@\[\]]+):(?!/)(\S+)$~', $value, $scp) === 1) {
            $value = 'ssh://git@'.$scp[1].'/'.$scp[2];
        }
        if (preg_match('~^(https?|ssh)://~i', $value) !== 1 || preg_match('~\s~', $value) === 1) {
            return null;
        }
        $parts = parse_url($value);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        $parts['scheme'] = $scheme === 'ssh' || $scheme === 'http' ? $scheme : 'https';

        return $parts;
    }

    /** @param array{scheme: 'http'|'https'|'ssh', user?: string, pass?: string} $parts */
    private static function credentials(array $parts): bool
    {
        return isset($parts['pass']) || ($parts['scheme'] !== 'ssh' && isset($parts['user']));
    }
}
