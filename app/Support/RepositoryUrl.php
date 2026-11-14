<?php

declare(strict_types=1);

namespace Heptapod\Support;

/**
 * The one canonical form of a repository link, shared by the Site settings save, the API write and the API filter:
 * `https://host/path` with a lower-case host, without `.git` and trailing slash. SSH remotes (`git@host:org/repo.git`,
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

    /** The canonical URL, or null for anything that is not a repository address (including embedded credentials). */
    public static function canonical(string $value): ?string
    {
        $parts = self::parts($value);
        if ($parts === null || self::credentials($parts)) {
            return null;
        }
        $path = rtrim($parts['path'] ?? '', '/');
        if (str_ends_with(strtolower($path), '.git')) {
            $path = rtrim(substr($path, 0, -4), '/');
        }
        if ($path === '' || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        // An SSH port says nothing about the web address; a non-default HTTP(S) port is part of it.
        $port = $parts['scheme'] === 'https' && isset($parts['port']) && ! in_array($parts['port'], [80, 443], true) ? ':'.$parts['port'] : '';
        $url = 'https://'.strtolower($parts['host']).$port.$path;

        return strlen($url) <= self::MaxLength ? $url : null;
    }

    /** True for `https://token@…` or `user:pass@…`; the SSH login name (`git@`) is not a credential. */
    public static function hasCredentials(string $value): bool
    {
        $parts = self::parts($value);

        return $parts !== null && self::credentials($parts);
    }

    /** @return array{scheme: 'https'|'ssh', host: string, port?: int, user?: string, pass?: string, path?: string, query?: string, fragment?: string}|null */
    private static function parts(string $value): ?array
    {
        $value = trim($value);
        if (preg_match('~^[\w.-]+@([^\s:/@]+):(?!/)(\S+)$~', $value, $scp) === 1) {
            $value = 'ssh://git@'.$scp[1].'/'.$scp[2];
        }
        if (preg_match('~^(https?|ssh)://~i', $value) !== 1 || preg_match('~\s~', $value) === 1) {
            return null;
        }
        $parts = parse_url($value);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            return null;
        }
        $parts['scheme'] = strtolower($parts['scheme']) === 'ssh' ? 'ssh' : 'https';

        return $parts;
    }

    /** @param array{scheme: 'https'|'ssh', user?: string, pass?: string} $parts */
    private static function credentials(array $parts): bool
    {
        return isset($parts['pass']) || ($parts['scheme'] === 'https' && isset($parts['user']));
    }
}
