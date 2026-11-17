<?php

declare(strict_types=1);

return [
    'service_url' => env('LIGHTHOUSE_SERVICE_URL', 'http://lighthouse:3000'),
    'proxy_control_url' => env('LIGHTHOUSE_PROXY_CONTROL_URL', 'http://lighthouse-proxy-control:3129'),
    // The Lighthouse service enforces the same budget and margin; both read docker/lighthouse/audit-budget.json.
    'audit_budget' => (array) json_decode((string) file_get_contents(base_path('docker/lighthouse/audit-budget.json')), true, flags: JSON_THROW_ON_ERROR),
];
