<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Http;

/** Classifies a bounded HTTP response, without fetching or storing its contents. */
final class HtmlPage
{
    /** A page over this cap is refused with no body, so its head (including icon links) would be lost; keep it above typical start-page sizes. */
    public const int MAX_HTML_BYTES = 2097152;

    public static function unavailableReason(?int $status, string $contentType, string $body, string $requestedUrl, string $finalUrl): ?string
    {
        if (in_array($status, [401, 403], true)) {
            return 'authentication_required';
        }
        if ($status === null || $status < 200 || $status >= 300) {
            return 'http_status';
        }
        $mime = strtolower(trim(explode(';', $contentType, 2)[0]));
        if (! in_array($mime, ['text/html', 'application/xhtml+xml'], true)) {
            return 'non_html';
        }
        $path = parse_url($finalUrl, PHP_URL_PATH);
        if ($path === false) {
            throw new \InvalidArgumentException('HTML classification requires a valid final URL.');
        }
        $isLoginPath = preg_match('~/(?:login|log-in|signin|sign-in|wp-login\.php)(?:/|$)~i', $path ?? '') === 1;
        if (! $isLoginPath) {
            // A password field alone is common on public homepages (header login modals); only a login path is decisive.
            return null;
        }
        if ($requestedUrl !== $finalUrl) {
            return 'login_redirect';
        }

        return 'login_page';
    }
}
