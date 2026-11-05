<?php

declare(strict_types=1);

return [
    // Empty disables the schedule; explicit command execution still rejects a missing URL.
    'heartbeat_url' => env('INSTANCE_HEARTBEAT_URL'),
];
