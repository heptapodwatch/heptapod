<?php

declare(strict_types=1);
use Heptapod\Checks\Execution\WorkerMemoryBudget;
use Heptapod\Contracts\Http\TargetHost;

$browserEnabled = filter_var(env('HEPTAPOD_BROWSER_ENABLED', true), FILTER_VALIDATE_BOOLEAN);
$updateChecksEnabled = filter_var(env('HEPTAPOD_UPDATE_CHECK_ENABLED', true), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
if ($updateChecksEnabled === null) {
    throw new InvalidArgumentException('HEPTAPOD_UPDATE_CHECK_ENABLED must be true or false.');
}

$metadataJson = file_get_contents(__DIR__.'/outbound-metadata.json');
if ($metadataJson === false) {
    throw new RuntimeException('Outbound metadata policy is missing.');
}
$metadataTargets = json_decode($metadataJson, true, flags: JSON_THROW_ON_ERROR);
if (! is_array($metadataTargets) || count($metadataTargets) !== 2 || ! isset($metadataTargets['hosts'], $metadataTargets['addresses'])) {
    throw new RuntimeException('Invalid outbound metadata policy.');
}
foreach (['hosts', 'addresses'] as $kind) {
    $targets = $metadataTargets[$kind];
    if (! is_array($targets) || ! array_is_list($targets) || $targets === []) {
        throw new RuntimeException('Outbound metadata policy requires nonempty target lists.');
    }
    foreach ($targets as $target) {
        if (! is_string($target) || $target === '' || ($kind === 'addresses' && filter_var($target, FILTER_VALIDATE_IP) === false)
            || ($kind === 'hosts' && TargetHost::normalize($target) !== $target)) {
            throw new RuntimeException('Invalid outbound metadata policy target.');
        }
    }
}

$deliveryPrivateHosts = [];
$deliveryPrivateHostsValue = env('HEPTAPOD_DELIVERY_PRIVATE_HOSTS', '');
if (! is_string($deliveryPrivateHostsValue)) {
    throw new InvalidArgumentException('HEPTAPOD_DELIVERY_PRIVATE_HOSTS must be a comma-separated list of exact hosts.');
}
if (trim($deliveryPrivateHostsValue) !== '') {
    foreach (explode(',', $deliveryPrivateHostsValue) as $entry) {
        $host = TargetHost::normalize(trim($entry));
        if (filter_var($host, FILTER_VALIDATE_IP) === false
            && (strlen($host) > 253 || preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/D', $host) !== 1)) {
            throw new InvalidArgumentException('HEPTAPOD_DELIVERY_PRIVATE_HOSTS accepts exact host names or IPs, not URLs, wildcards or networks.');
        }
        $deliveryPrivateHosts[] = $host;
    }
    $deliveryPrivateHosts = array_values(array_unique($deliveryPrivateHosts));
}

$crawlPhpLimitMiB = filter_var(env('HEPTAPOD_CRAWL_MEMORY_LIMIT_MB', WorkerMemoryBudget::DefaultCrawlPhpLimitMiB), FILTER_VALIDATE_INT, ['options' => ['min_range' => 256, 'max_range' => 4096]]);
if ($crawlPhpLimitMiB === false) {
    throw new InvalidArgumentException('HEPTAPOD_CRAWL_MEMORY_LIMIT_MB must be an integer number of MiB from 256 to 4096.');
}

return [
    'freshness' => [
        'interval_multiplier' => (int) env('HEPTAPOD_FRESHNESS_INTERVAL_MULTIPLIER', 3),
        'queues' => [
            'checks-fast' => ['min_seconds' => (int) env('HEPTAPOD_FRESHNESS_FAST_MIN_SECONDS', 900), 'max_seconds' => (int) env('HEPTAPOD_FRESHNESS_FAST_MAX_SECONDS', 21600)],
            'checks-crawl' => ['min_seconds' => (int) env('HEPTAPOD_FRESHNESS_CRAWL_MIN_SECONDS', 7200), 'max_seconds' => (int) env('HEPTAPOD_FRESHNESS_CRAWL_MAX_SECONDS', 21600)],
            'checks-browser' => ['min_seconds' => (int) env('HEPTAPOD_FRESHNESS_BROWSER_MIN_SECONDS', 7200), 'max_seconds' => (int) env('HEPTAPOD_FRESHNESS_BROWSER_MAX_SECONDS', 21600)],
        ],
    ],
    'version' => env('HEPTAPOD_VERSION', 'unknown'),
    'upgrade_backup_keep' => filter_var(env('HEPTAPOD_UPGRADE_BACKUP_KEEP', 3), FILTER_VALIDATE_INT),
    'updates' => [
        'enabled_by_server' => $updateChecksEnabled,
        'release_url' => env('HEPTAPOD_UPDATE_CHECK_URL', 'https://api.github.com/repos/heptapodwatch/heptapod/releases/latest'),
        'release_page_url' => 'https://github.com/heptapodwatch/heptapod/releases',
    ],
    'browser' => ['enabled' => $browserEnabled],
    // Crawl worker PHP memory_limit; the worker container budget (HORIZON_MEMORY_LIMIT) is derived from it.
    'worker_memory' => ['crawl_php_limit_mib' => $crawlPhpLimitMiB],
    'maintenance' => ['horizon_days' => 30],
    'uptime' => [
        'max_response_bytes' => filter_var(env('UPTIME_MAX_RESPONSE_BYTES', 10485760), FILTER_VALIDATE_INT),
    ],
    'system_issues' => [
        // Optional mount visible to the app. An explicitly configured but unreadable path is an alert.
        'postgres_data_path' => env('HEPTAPOD_POSTGRES_DATA_PATH'),
        // Non-production failure seam: throws between a transition and its delivery rows for this kind; null in production.
        'dev_fail_after_transition' => env('APP_ENV') === 'production' ? null : env('HEPTAPOD_DEV_FAIL_AFTER_TRANSITION'),
        // mail_transport_dead opens after 3 failures and this long without a successful send.
        // Non-production clock seam: dev stacks may shorten it with HEPTAPOD_DEV_MAIL_DEAD_AFTER_SECONDS to prove the rule; production always uses 1 h.
        'mail_dead_after_seconds' => env('APP_ENV') === 'production' ? 3600 : filter_var(env('HEPTAPOD_DEV_MAIL_DEAD_AFTER_SECONDS', 3600), FILTER_VALIDATE_INT),
    ],
    'health' => [
        'dispatcher_ok_seconds' => 60, 'dispatcher_warn_seconds' => 120,
        'scheduler_ok_seconds' => 90, 'scheduler_warn_seconds' => 180,
        'queue_warn_depth' => 100, 'queue_down_depth' => 1000,
        'worker_cgroup_path' => '/sys/fs/cgroup',
        'scheduler_cache_key' => 'heptapod:scheduler:last-tick',
        'external_ping_cache_key' => 'heptapod:heartbeat:last-ping',
        'browser_urls' => [
            'lighthouse' => $browserEnabled ? rtrim((string) env('LIGHTHOUSE_URL', 'http://lighthouse:3000'), '/').'/health' : null,
            'gotenberg' => $browserEnabled ? rtrim((string) env('GOTENBERG_URL', 'http://gotenberg:3000'), '/').'/health' : null,
        ],
    ],
    'outbound' => [
        'metadata_targets' => $metadataTargets,
        'delivery_private_hosts' => $deliveryPrivateHosts,
    ],
];
