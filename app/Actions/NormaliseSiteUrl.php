<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final readonly class NormaliseSiteUrl
{
    public function __invoke(string $value): string
    {
        Validator::make(['url' => $value], ['url' => ['required', 'string', 'max:2048']])->validate();
        $url = trim($value);
        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        } elseif (preg_match('~^[a-z][a-z\d+.-]*:~i', $url) !== 1 || preg_match('~^[^/?#:]+:\d+(?:[/?#]|$)~', $url) === 1) {
            $url = 'https://'.$url;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || ! isset($parts['host'])) {
            throw ValidationException::withMessages(['url' => __('sites.url_invalid')]);
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw ValidationException::withMessages(['url' => __('api.url_credentials_unsupported')]);
        }
        $scheme = strtolower($parts['scheme']);
        $host = rtrim(strtolower($parts['host']), '.');
        if (! str_starts_with($host, '[')) {
            $host = $host === '' ? false : idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($host === false) {
                throw ValidationException::withMessages(['url' => __('sites.url_invalid')]);
            }
        }
        $port = $parts['port'] ?? null;
        $path = $parts['path'] ?? '';
        $url = $scheme.'://'.$host.($port === null || $port === ($scheme === 'https' ? 443 : 80) ? '' : ':'.$port)
            .($path === '/' ? '' : $path).(isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '');
        Validator::make(['url' => $url], ['url' => ['max:2048', 'url:http,https']], ['url.url' => __('sites.url_invalid')])->validate();

        return $url;
    }

    public function key(string $url): string
    {
        return hash('sha256', preg_replace('~^https?://~', '', $this($url)) ?? throw new \LogicException('Invalid URL key pattern.'));
    }
}
