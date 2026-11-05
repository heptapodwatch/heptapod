<?php

declare(strict_types=1);

namespace Heptapod\Import;

/** Historical source identifiers: do not rename these stored-data literals. */
final class LegacySourceMap
{
    public const string NamespacePrefix = 'Lynkeus\\';

    /** Accept raw text and JSON/COPY-escaped separators, but not JSON quote/newline/tab escapes. */
    public const string ClassNamePattern = 'Lynkeus[\\\\]+[A-Z_][A-Za-z0-9_]*';

    public const string Brand = 'Lynkeus';

    public const string BackupDirectory = 'Lynkeus';

    public const string Database = 'lynkeus';

    public const string Role = 'lynkeus';

    public const string DatabaseVolume = 'lynkeus_postgres';

    public const string StorageVolume = 'lynkeus_storage';

    public const string ImportName = 'import-lynkeus';

    /** @var array<string, string> */
    public const array Environment = [
        'LYNKEUS_APP_RUN_MIGRATIONS' => 'HEPTAPOD_APP_RUN_MIGRATIONS',
        'LYNKEUS_BROWSER_ENABLED' => 'HEPTAPOD_BROWSER_ENABLED',
        'LYNKEUS_CRAWL_CONCURRENCY' => 'HEPTAPOD_CRAWL_CONCURRENCY',
        'LYNKEUS_CRAWL_MAX_STATUS_URLS' => 'HEPTAPOD_CRAWL_MAX_STATUS_URLS',
        'LYNKEUS_CRAWL_TIMEOUT_SECONDS' => 'HEPTAPOD_CRAWL_TIMEOUT_SECONDS',
        'LYNKEUS_DELIVERY_PRIVATE_HOSTS' => 'HEPTAPOD_DELIVERY_PRIVATE_HOSTS',
        'LYNKEUS_DEV_FAIL_AFTER_TRANSITION' => '(drop: development only)',
        'LYNKEUS_DEV_MAIL_DEAD_AFTER_SECONDS' => '(drop: development only)',
        'LYNKEUS_HEAVY_SLOTS' => 'HEPTAPOD_HEAVY_SLOTS',
        'LYNKEUS_NOTIFICATION_LIVE' => 'HEPTAPOD_NOTIFICATION_LIVE',
        'LYNKEUS_ROLE' => 'HEPTAPOD_ROLE',
        'LYNKEUS_STORAGE_DISK' => 'HEPTAPOD_STORAGE_DISK',
        'LYNKEUS_UPDATE_CHECK_ENABLED' => 'HEPTAPOD_UPDATE_CHECK_ENABLED',
        'LYNKEUS_UPDATE_CHECK_URL' => 'HEPTAPOD_UPDATE_CHECK_URL',
        'LYNKEUS_VERSION' => '(drop: image build argument)',
        'LYNKEUS_VERSION_BUILD' => '(drop: image build argument)',
        'LYNKEUS_WORKER_PHP_INI_SCAN_DIR' => '(drop: managed by the image entrypoint)',
    ];
}
