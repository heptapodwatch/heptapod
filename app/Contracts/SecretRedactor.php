<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Heptapod\Support\RedactionContext;
use Illuminate\Support\Arr;

/**
 * ASCII case-insensitive redaction of overlapping literal, JSON-escaped and URL-encoded secret echoes.
 * Candidates require at least four Unicode code points (four bytes for opaque input).
 * Node strips unmatched control characters from the output; PHP preserves them.
 * Covers one percent-decode/encode level and control-filtered echoes; recursive decoding and Unicode normalization are not guaranteed.
 */
final class SecretRedactor
{
    // Intentional asymmetry with Node: this persistence pass also covers JSON \uXXXX, \/ and '+'-as-space query echoes; case folding is ASCII-only.
    /**
     * @param  array<array-key, mixed>  $secrets
     * @param  array<array-key, mixed>  $urls
     */
    public static function redactValues(mixed $value, #[\SensitiveParameter] array $secrets, array $urls = []): mixed
    {
        $secrets = self::secretValues($secrets);
        if (is_string($value)) {
            return self::redact($value, $secrets, $urls);
        }
        if (! is_array($value)) {
            return $value;
        }
        $redacted = [];
        foreach ($value as $key => $child) {
            $key = is_string($key) ? self::redact($key, $secrets, $urls) : $key;
            $redacted[$key] = self::redactValues($child, $secrets, $urls);
        }

        return $redacted;
    }

    /**
     * Redacts nested string values without changing keys or non-string leaves.
     *
     * @template T of array<array-key, mixed>
     *
     * @param  T  $values
     * @return T
     */
    public static function redactStringValues(array $values, RedactionContext $context): array
    {
        array_walk_recursive($values, static function (mixed &$value) use ($context): void {
            if (is_string($value)) {
                $value = self::redact($value, $context->secrets, $context->urls);
            }
        });

        return $values;
    }

    /**
     * @param  array<array-key, mixed>  $secrets
     * @param  array<array-key, mixed>  $urls
     */
    public static function redact(string $text, #[\SensitiveParameter] array $secrets, #[\SensitiveParameter] array $urls = []): string
    {
        return self::maskCredentialParameters(self::redactSecrets($text, $secrets, $urls));
    }

    /**
     * Masks the value of credential-named query parameters in any http(s) URL in the text, whether or not the value
     * is a known secret: discovered URLs (crawl redirects, sitemap entries) can carry tokens or signed-URL signatures.
     * The parameter name and every other parameter stay readable. PHP and Lighthouse share config/credential-parameters.json.
     */
    private static function maskCredentialParameters(string $text): string
    {
        return preg_replace_callback(self::UrlQuery, static fn (array $url): string => $url[1].(preg_replace_callback(self::QueryParameter,
            static fn (array $parameter): string => $parameter[1].$parameter[2].'='.($parameter[3] !== '' && self::isCredentialName($parameter[2]) ? '[redacted]' : $parameter[3]),
            $url[2]) ?? throw new \RuntimeException('Cannot mask credential parameters.')),
            $text) ?? throw new \RuntimeException('Cannot mask credential parameters.');
    }

    /** Compares the decoded name without array brackets, so percent-encoded names and array names (token[], Token[0]) all match token. */
    private static function isCredentialName(string $name): bool
    {
        $name = strtolower(preg_replace('/\[\d*\]$/', '', rawurldecode(str_replace('+', ' ', $name))) ?? $name);

        $parameters = self::credentialParameters();

        return in_array($name, $parameters['names'], true) || preg_match('/'.$parameters['suffixPattern'].'/', $name) === 1;
    }

    /** An http(s) URL up to its query: group 1 is everything before the query, group 2 the query without fragment. */
    private const string UrlQuery = '~(https?://[^\s?#"\'<>]*\?)([^\s#"\'<>]*)~i';

    /** Group 1: leading separator; group 2: raw name; group 3: the value up to the next parameter. */
    private const string QueryParameter = '~(^|[&;])([^&;=]+)=([^&;]*)~';

    /** @return array{names: list<string>, suffixPattern: string} */
    private static function credentialParameters(): array
    {
        /** @var array{names: list<string>, suffixPattern: string}|null $parameters */
        static $parameters = null;
        if ($parameters === null) {
            $json = file_get_contents(__DIR__.'/../../config/credential-parameters.json');
            if ($json === false) {
                throw new \RuntimeException('Credential parameter policy is missing.');
            }
            $policy = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($policy) || ! is_array($policy['names'] ?? null) || ! array_is_list($policy['names']) || $policy['names'] === []
                || ! is_string($policy['suffixPattern'] ?? null)) {
                throw new \RuntimeException('Invalid credential parameter policy.');
            }
            $names = [];
            foreach ($policy['names'] as $name) {
                if (! is_string($name)) {
                    throw new \RuntimeException('Credential parameter names must be strings.');
                }
                $names[] = $name;
            }
            $parameters = ['names' => $names, 'suffixPattern' => $policy['suffixPattern']];
        }

        return $parameters;
    }

    /**
     * @param  array<array-key, mixed>  $secrets
     * @param  array<array-key, mixed>  $urls
     */
    private static function redactSecrets(string $text, #[\SensitiveParameter] array $secrets, #[\SensitiveParameter] array $urls): string
    {
        $values = self::secretValues($secrets);
        foreach (Arr::flatten($urls) as $url) {
            if (! is_string($url) || ! str_starts_with($url, 'http')) {
                continue;
            }
            $parts = parse_url($url);
            if ($parts === false) {
                continue;
            }
            // Secret webhook URLs can carry credentials in path segments, not only query/userinfo.
            if (in_array($url, Arr::flatten($secrets), true)) {
                foreach (explode('/', $parts['path'] ?? '') as $segment) {
                    $values[] = $segment;
                    $values[] = rawurldecode($segment);
                }
            }
            foreach (['user', 'pass', 'query'] as $key) {
                if (isset($parts[$key])) {
                    $values[] = $parts[$key];
                    $values[] = rawurldecode($parts[$key]);
                }
            }
            foreach (explode('&', $parts['query'] ?? '') as $parameter) {
                $value = explode('=', $parameter, 2)[1] ?? '';
                $values[] = $value;
                $values[] = urldecode($value);
            }
        }
        $candidates = [];
        foreach ($values as $value) {
            // Query decoders differ on '+'. Protect both echoes, with and without controls.
            foreach (array_unique([$value, rawurldecode($value), urldecode($value)]) as $secret) {
                $candidates[] = $secret;
                $pattern = mb_check_encoding($secret, 'UTF-8') ? '/[\x00-\x1f\x7f-\x{9f}]/u' : '/[\x00-\x1f\x7f-\x9f]/';
                $candidates[] = preg_replace($pattern, '', $secret) ?? throw new \RuntimeException('Cannot filter secret controls.');
            }
        }
        $redactions = [];
        foreach ($candidates as $secret) {
            if ((mb_check_encoding($secret, 'UTF-8') ? mb_strlen($secret, 'UTF-8') : strlen($secret)) >= 4) {
                $redactions[$secret] = true;
                // Providers can echo the same value inside JSON or a percent-encoded URL.
                // Opaque URL bytes have no valid JSON string representation.
                if (mb_check_encoding($secret, 'UTF-8')) {
                    $redactions[substr(json_encode($secret, JSON_THROW_ON_ERROR), 1, -1)] = true;
                }
                $redactions[rawurlencode($secret)] = true;
                $redactions[urlencode($secret)] = true;
            }
        }

        if ($redactions === []) {
            return $text;
        }
        $mask = '[redacted]';
        $redactions[$mask] = true;
        $offsets = [];
        $removed = 0;
        $controlPattern = mb_check_encoding($text, 'UTF-8') ? '/[\x00-\x1f\x7f-\x{9f}]+/u' : '/[\x00-\x1f\x7f-\x9f]+/';
        $filtered = preg_replace_callback($controlPattern, static function (array $match) use (&$offsets, &$removed): string {
            [$control, $position] = $match[0];
            $filteredPosition = $position - $removed;
            $removed += strlen($control);
            $offsets[] = [$filteredPosition, $removed];

            return '';
        }, $text, flags: PREG_OFFSET_CAPTURE) ?? throw new \RuntimeException('Cannot filter diagnostic controls.');
        $merged = [];
        foreach (array_map(strval(...), array_keys($redactions)) as $variant) {
            $merged = self::mergeMatches($text, $variant, $merged);
            if ($offsets !== []) {
                $merged = self::mergeMatches($filtered, $variant, $merged, $offsets);
            }
        }
        // Replace only after merging, so neither a mask nor a partial replacement hides an overlap.
        $parts = [];
        $copied = 0;
        foreach ($merged as [$start, $end]) {
            $parts[] = substr($text, $copied, $start - $copied);
            $parts[] = $mask;
            $copied = $end;
        }
        $parts[] = substr($text, $copied);

        return implode('', $parts);
    }

    /**
     * @param  array<array-key, mixed>  $secrets
     * @return list<string>
     */
    private static function secretValues(#[\SensitiveParameter] array $secrets): array
    {
        $values = [];
        foreach (Arr::flatten($secrets) as $secret) {
            if (! is_string($secret)) {
                throw new \InvalidArgumentException('Redaction secrets must be strings.');
            }
            $values[] = $secret;
        }

        return $values;
    }

    /**
     * @param  list<array{int, int}>  $merged
     * @param  list<array{int, int}>  $offsets  Filtered byte positions and cumulative removed bytes.
     * @return list<array{int, int}>
     */
    private static function mergeMatches(string $text, string $variant, array $merged, array $offsets = []): array
    {
        $ranges = [];
        $append = static function (int $start, int $end) use (&$ranges): void {
            $last = array_key_last($ranges);
            if ($last !== null && $start <= $ranges[$last][1]) {
                $ranges[$last][1] = max($ranges[$last][1], $end);
            } else {
                $ranges[] = [$start, $end];
            }
        };
        $existing = 0;
        $searched = 0;
        $length = strlen($variant);
        $startOffset = $endOffset = $startShift = $endShift = 0;
        while (($position = stripos($text, $variant, $searched)) !== false) {
            $end = $position + $length;
            if ($offsets !== []) {
                // Start after leading controls; end before trailing controls. Internal controls belong to the secret.
                while (isset($offsets[$startOffset]) && $offsets[$startOffset][0] <= $position) {
                    $startShift = $offsets[$startOffset++][1];
                }
                while (isset($offsets[$endOffset]) && $offsets[$endOffset][0] < $end) {
                    $endShift = $offsets[$endOffset++][1];
                }
            }
            $start = $position + $startShift;
            while (isset($merged[$existing]) && $merged[$existing][0] <= $start) {
                $append(...$merged[$existing++]);
            }
            $append($start, $end + $endShift);
            // Include occurrences overlapping this one.
            $searched = $position + 1;
        }
        while (isset($merged[$existing])) {
            $append(...$merged[$existing++]);
        }

        // Both streams are ascending; coalesce immediately instead of retaining every match.
        return $ranges;
    }
}
