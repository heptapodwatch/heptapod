<?php

declare(strict_types=1);

namespace Heptapod\Addons\Crawl;

use Heptapod\Contracts\Http\SameSite;
use Uri\InvalidUriException;
use Uri\Rfc3986\Uri;
use Uri\WhatWg\Url;

final class BrowserUrl
{
    public static function isEmailProtection(string $url, string $siteUrl): bool
    {
        $target = self::policyUrl($url);
        if (! $target instanceof Uri || $target->getPath() !== '/cdn-cgi/l/email-protection') {
            return false;
        }

        return self::isSameSite($url, $siteUrl);
    }

    public static function isSameSite(string $url, string $siteUrl): bool
    {
        $site = self::policyUrl($siteUrl);
        $candidate = self::policyUrl($url);

        return $site instanceof Uri && $candidate instanceof Uri
            && app(SameSite::class)->isSameSite($site->toRawString(), $candidate->toRawString());
    }

    private static function policyUrl(string $url): ?Uri
    {
        try {
            $parsed = new Url($url);
            if (! in_array($parsed->getScheme(), ['http', 'https'], true)) {
                return null;
            }

            // Retained evidence can contain redaction masks that are not valid RFC 3986 userinfo or queries.
            return new Uri($parsed->withUsername('')->withPassword('')->withQuery(null)->withFragment(null)->toAsciiString());
        } catch (InvalidUriException) {
            return null;
        }
    }

    /** Only the comparison form is encoded; stored evidence keeps the redaction mask readable. */
    public static function comparableRedacted(string $url): string
    {
        return str_replace('[redacted]', '%5Bredacted%5D', $url);
    }

    /** Bound remote URL evidence to 400 UTF-8 bytes plus a hash that distinguishes truncated values. */
    public static function boundedEvidenceUrl(string $url): string
    {
        return strlen($url) > 400 ? mb_strcut($url, 0, 400, 'UTF-8').'#'.hash('sha256', $url) : $url;
    }

    public static function normalize(string $url, ?string $base = null): ?string
    {
        try {
            $parsed = new Url($url, $base === null ? null : new Url($base));
            if (! in_array($parsed->getScheme(), ['http', 'https'], true)) {
                return null;
            }

            // Evidence uses RFC 3986 URIs; WHATWG alone accepts malformed percent escapes.
            return new Uri($parsed->withFragment(null)->toAsciiString())->toRawString();
        } catch (InvalidUriException) {
            return null;
        }
    }
}
