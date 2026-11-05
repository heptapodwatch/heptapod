<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

/** No observation was produced; the recorder must classify its freshness effect. */
enum NoResultReason: string
{
    case PerformanceNoSamples = 'performance_no_samples';
    case PerformanceUptimeIntervalTooLong = 'performance_uptime_interval_too_long';
    case MixedContentStartPageNotScanned = 'mixed_content_start_page_not_scanned';
    case CronTasksPending = 'cron_tasks_pending';
}
