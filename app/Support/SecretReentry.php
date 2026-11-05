<?php

declare(strict_types=1);

namespace Heptapod\Support;

use Illuminate\Support\Arr;

final class SecretReentry
{
    /** @return array{string, string, int} */
    public static function origin(string $url): array
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException('Target origins require a validated absolute URL.');
        }
        $scheme = strtolower($parts['scheme']);

        return [$scheme, strtolower($parts['host']), $parts['port'] ?? ($scheme === 'https' ? 443 : 80)];
    }

    /**
     * @param  array<array-key, mixed>  $secrets
     * @param  bool  $allowClearedListSecrets  Site API drops empty credential rows before persistence; probes reject them.
     * @return array<string, string>
     */
    public static function errors(array $secrets, mixed $supplied, string $prefix, bool $allowClearedListSecrets = false): array
    {
        $errors = [];
        foreach (Arr::dot($secrets) as $path => $secret) {
            if (is_string($secret) && $secret !== '' && ! self::reentered($supplied, $path, $allowClearedListSecrets)) {
                $errors[$prefix.'.'.$path] = __('api.secrets_host_change');
            }
        }

        return $errors;
    }

    private static function reentered(mixed $supplied, string $path, bool $allowClearedListSecrets): bool
    {
        $inList = false;
        foreach (explode('.', $path) as $part) {
            if (! is_array($supplied)) {
                return false;
            }
            $inList = $inList || (array_is_list($supplied) && preg_match('/\A[0-9]+\z/', $part) === 1);
            if (! array_key_exists($part, $supplied)) {
                // A supplied replacement list may deliberately remove an old credential header.
                return $part === (string) (int) $part && (int) $part >= 0 && array_is_list($supplied);
            }
            $supplied = $supplied[$part];
        }

        return is_string($supplied) && $supplied !== '********' && ($supplied !== '' || ($allowClearedListSecrets && $inList));
    }
}
