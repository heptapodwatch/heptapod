<?php

declare(strict_types=1);

return [
    // Addon config is merged only before config caching, just like root config files.
    'ca_file' => env('CERTIFICATE_HEALTH_CA_FILE'),
];
