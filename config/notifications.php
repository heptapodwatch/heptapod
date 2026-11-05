<?php

declare(strict_types=1);

return [
    // Delays after the opening alert and each reminder; the final delay repeats. Null disables reminders.
    'renotify_intervals_seconds' => [3600, 14400, 86400],
    // Addons merge their own key into notifications.event_types.<check key>.
    'event_types' => [],
];
