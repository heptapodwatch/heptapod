<?php

declare(strict_types=1);

use Dedoc\Scramble\OpenApiVersion;

// Fixed values only: docs/openapi.json must not depend on the environment that exports it.
return [
    'api_path' => 'api',
    'api_domain' => null,
    'export_path' => 'docs/openapi.json',
    'openapi_version' => OpenApiVersion::V3_1,
    'info' => [
        'version' => '1',
        'description' => '',
    ],
    'ui' => [
        'title' => 'Heptapod API',
    ],
    'servers' => null,
    // No Scramble UI or asset routes: the app serves only the committed document at /api/openapi.json.
    'dev_tools' => ['enabled' => false],
    'extensions' => [],
    'security_strategy' => null,
];
