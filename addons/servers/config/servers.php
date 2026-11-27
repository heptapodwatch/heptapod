<?php

declare(strict_types=1);

return [
    // The agent waits this long between samples (the ingest response tells it); "No report" after two missed intervals.
    'interval_seconds' => 60,
    'retention_days' => 14,
    // Instance-wide defaults; the gauges colour by them and mark them with a tick.
    'thresholds' => [
        'disk_used_percent' => 90,
        'memory_used_percent' => 90,
    ],
    'agent_release_url' => 'https://github.com/heptapodwatch/heptapod/releases/download',
];
