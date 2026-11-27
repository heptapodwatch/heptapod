<?php

declare(strict_types=1);

return [
    // The agent waits this long between samples (the ingest response tells it).
    'interval_seconds' => 60,
    'retention_days' => 14,
    // Instance-wide defaults; each server can change any of them (Settings…). The gauges colour by them and mark them with
    // a tick; alerting on them (a value at or above for the duration) comes with server alerts.
    'thresholds' => [
        'disk' => ['percent' => 90, 'minutes' => 15],
        'memory' => ['percent' => 90, 'minutes' => 10],
        // The 5-minute load at or above this many times the vCPU count.
        'load' => ['per_core' => 2.0, 'minutes' => 15],
        // "No report" once the newest report is this old: two missed intervals.
        'no_report_minutes' => 2,
    ],
    'agent_release_url' => 'https://github.com/heptapodwatch/heptapod/releases/download',
];
