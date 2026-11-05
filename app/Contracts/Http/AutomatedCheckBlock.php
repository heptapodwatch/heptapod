<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Http;

/** Explicit vendor evidence only; a plain forbidden response remains a broken link. */
final class AutomatedCheckBlock
{
    public const int BODY_PREFIX_BYTES = 16384;

    /** @param array<array<string>> $headers */
    public static function reason(?int $status, array $headers, string $body): ?AutomatedCheckBlockReason
    {
        // Vendor metadata or marker text on a readable 2xx page is not evidence of a block.
        // Only final 4xx/5xx responses can be bot protection or rate limiting.
        if ($status === null || $status < 400 || $status > 599) {
            return null;
        }
        if ($status === 429) {
            return AutomatedCheckBlockReason::RateLimited;
        }
        $normalized = [];
        foreach ($headers as $name => $values) {
            $normalized[strtolower($name)] = strtolower(implode(', ', $values));
        }
        $prefix = strtolower(substr($body, 0, self::BODY_PREFIX_BYTES));
        $server = $normalized['server'] ?? '';

        // Cloudflare documents cf-mitigated: challenge; cf-chl is its challenge markup.
        if (($normalized['cf-mitigated'] ?? '') === 'challenge' || str_contains($prefix, 'cf-chl')) {
            return AutomatedCheckBlockReason::BotProtection;
        }
        // Cloudflare's interstitial title is evidence only together with its server header.
        if (str_contains($server, 'cloudflare') && preg_match('~<title[^>]*>\s*just a moment\.\.\.\s*</title>~', $prefix) === 1) {
            return AutomatedCheckBlockReason::BotProtection;
        }
        // Cloudflare WAF block pages can omit the cf-mitigated header.
        if ($status === 403 && str_contains($server, 'cloudflare') && (preg_match('~<title[^>]*>\s*attention required!\s*\|\s*cloudflare\s*</title>~', $prefix) === 1 || str_contains($prefix, 'cf-error-details'))) {
            return AutomatedCheckBlockReason::BotProtection;
        }
        // Akamai's 403 Access Denied page identifies the block with a Reference # and AkamaiGHost.
        if ($status === 403 && str_contains($server, 'akamaighost') && str_contains($prefix, 'access denied') && preg_match('/reference\s*#\s*[^\s<>]+/', $prefix) === 1) {
            return AutomatedCheckBlockReason::BotProtection;
        }
        // Observed Sucuri block pages, not proxy metadata:
        // https://wordpress.org/support/topic/access-denied-sucuri-website-firewall/
        // https://www.statproxies.com/glossary/anti-bot-status-codes/sucuri
        $sucuriTitle = preg_match('~<title[^>]*>\s*access denied - sucuri website firewall\s*</title>~', $prefix) === 1;
        $sucuriHeading = preg_match('~<h1[^>]*>\s*(?:access denied - )?sucuri website firewall\s*</h1>~', $prefix) === 1 && str_contains($prefix, 'your support id is');
        if ($status === 403 && ($sucuriTitle || $sucuriHeading)) {
            return AutomatedCheckBlockReason::BotProtection;
        }
        // Imperva's 403 block page reports an Incapsula incident ID; headers/cookies alone do not.
        if ($status === 403 && str_contains($prefix, 'incapsula incident id')) {
            return AutomatedCheckBlockReason::BotProtection;
        }

        return null;
    }
}
