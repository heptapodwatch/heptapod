<?php

declare(strict_types=1);

return [
    'service_url' => env('LIGHTHOUSE_SERVICE_URL', 'http://lighthouse:3000'),
    'proxy_control_url' => env('LIGHTHOUSE_PROXY_CONTROL_URL', 'http://lighthouse-proxy-control:3129'),
];
