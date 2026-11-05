<?php

declare(strict_types=1);

$timeout = filter_var(env('HEPTAPOD_CRAWL_TIMEOUT_SECONDS', 1200), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1200]]);
if ($timeout === false) {
    throw new InvalidArgumentException('HEPTAPOD_CRAWL_TIMEOUT_SECONDS must be between 1 and 1200 seconds.');
}
$maxStatusUrls = filter_var(env('HEPTAPOD_CRAWL_MAX_STATUS_URLS', 10000), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 25000]]);
if ($maxStatusUrls === false) {
    throw new InvalidArgumentException('HEPTAPOD_CRAWL_MAX_STATUS_URLS must be between 1 and 25000.');
}

return [
    'interval_seconds' => 86400,
    'timeout_seconds' => $timeout,
    'lock_wait_seconds' => 1200,
    'concurrency' => 5,
    'request_timeout_seconds' => 10,
    'max_response_bytes' => 2097152,
    'max_status_urls' => $maxStatusUrls,
    'defaults' => [
        'max_depth' => 5,
        'max_pages' => 2000,
        'exclude_patterns' => [],
        'do_not_crawl_urls' => [],
        'force_crawl_urls' => [],
        'whitelisted_urls' => [],
        'check_include_external_links' => true,
        'types' => ['link', 'image', 'script', 'stylesheet', 'og:image'],
        'new_links_only' => false,
        'respect_robots' => true,
    ],
];
