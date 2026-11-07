<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Contracts\CheckDetailData;
use Heptapod\Contracts\CheckHistoryData;
use Heptapod\Contracts\CheckRunPresentationPartData;
use Heptapod\Contracts\Mail\MailDesign;
use Heptapod\Enums\FreshnessSuppression;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Lazy\ClosureLazy;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Props of GET /sites/{site}/checks/{check}. Every prop is a closure so partial reloads evaluate only the requested keys. */
#[TypeScript]
final class CheckTabPageData extends Data
{
    /**
     * @param  ClosureLazy|list<CheckRunData>  $runs
     * @param  ClosureLazy|list<MetricSeriesData>  $metrics
     * @param  ClosureLazy|list<ResponseTimeSeriesData>  $response_times
     * @param  ClosureLazy|list<MetricSeriesData>  $phase_series
     * @param  ClosureLazy|list<RunAllCheckData>  $run_all_checks
     */
    public function __construct(
        public readonly ClosureLazy|SiteRowData $site,
        public readonly ClosureLazy|SiteIdentityData $header,
        public readonly ClosureLazy|CheckStripData $check,
        public readonly ClosureLazy|bool $can_run,
        public readonly ClosureLazy|CheckScheduleData $schedule,
        public readonly ClosureLazy|int $failure_threshold,
        public readonly ClosureLazy|array $runs,
        public readonly ClosureLazy|array $metrics,
        public readonly ClosureLazy|CheckSettingsData $check_settings,
        public readonly ClosureLazy|CheckDetailData|null $detail,
        public readonly ClosureLazy|array $response_times,
        public readonly ClosureLazy|array $phase_series,
        public readonly ClosureLazy|MetricSeriesData|null $response_p95_hourly,
        public readonly ClosureLazy|ClientData $client,
        public readonly ClosureLazy|CheckHistoryData|null $history,
        public readonly ClosureLazy|array $run_all_checks,
        public readonly ClosureLazy|MaintenanceSiteHeaderData|null $maintenance,
        public readonly ClosureLazy|PerformanceNoiseData|null $performance_noise,
        public readonly ClosureLazy|CheckRunPresentationPartData|null $performance_unavailable,
        public readonly ClosureLazy|FreshnessSuppression|null $freshness_suppressed_by,
        public readonly ClosureLazy|string|null $freshness_suppressed_since,
        public readonly ClosureLazy|string|null $freshness_latest_error_at,
        public readonly ClosureLazy|CheckRunPresentationPartData|null $freshness_suppression,
        /** The instance mail design: the preset of a new message in Send to client. */
        public readonly ClosureLazy|MailDesign $mail_design,
        public readonly ClosureLazy|bool $can_move = false,
    ) {}
}
