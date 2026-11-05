<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Http;

final class TargetHost
{
    public static function normalize(string $host): string
    {
        $host = strtolower(rtrim(trim($host, '[]'), '.'));
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $host;
        }
        $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if ($ascii === false || $ascii === '') {
            throw new \InvalidArgumentException('Invalid target hostname.');
        }

        return $ascii;
    }

    public static function fromUrl(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host)) {
            throw new \InvalidArgumentException('Target requires an absolute HTTP(S) URL.');
        }

        return self::normalize($host);
    }
}
