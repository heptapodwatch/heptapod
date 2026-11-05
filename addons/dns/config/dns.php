<?php

declare(strict_types=1);

// Fetched 2026-10-13. Refresh cloudflare-proxy-ranges.json with both published lists:
// https://www.cloudflare.com/ips-v4 and https://www.cloudflare.com/ips-v6.
$rangesJson = file_get_contents(__DIR__.'/cloudflare-proxy-ranges.json');
if ($rangesJson === false) {
    throw new RuntimeException('Cloudflare DNS proxy ranges are missing.');
}
$ranges = json_decode($rangesJson, true, flags: JSON_THROW_ON_ERROR);
if (! is_array($ranges) || ! array_is_list($ranges) || $ranges === []) {
    throw new RuntimeException('Cloudflare DNS proxy ranges must be a nonempty list.');
}
foreach ($ranges as $range) {
    if (! is_string($range) || preg_match('/^([^\/]+)\/(\d+)$/D', $range, $parts) !== 1
        || filter_var($parts[1], FILTER_VALIDATE_IP) === false
        || (int) $parts[2] > (str_contains($parts[1], ':') ? 128 : 32)) {
        throw new RuntimeException('Invalid Cloudflare DNS proxy range.');
    }
}

return [
    'cloudflare_proxy_ranges' => $ranges,
    // null uses the OS resolver; overrides are useful for private authoritative DNS.
    'resolver' => env('DNS_RESOLVER'),
];
