<?php

declare(strict_types=1);

namespace Heptapod\Queries;

use Carbon\CarbonImmutable;
use Heptapod\Checks\CauseSummary;
use Heptapod\Checks\CheckRunPresentations;
use Heptapod\Checks\CheckSettingsProjection;
use Heptapod\Checks\FreshnessSuppressionState;
use Heptapod\Checks\History\RollupReader;
use Heptapod\Checks\NoResultFreshness;
use Heptapod\Checks\PerformanceAvailability;
use Heptapod\Contracts\CheckDetailData;
use Heptapod\Contracts\CheckDetailProvider;
use Heptapod\Contracts\CheckHistoryData;
use Heptapod\Contracts\CheckHistoryProvider;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\CheckRunPresentationData;
use Heptapod\Contracts\CheckRunPresentationPartData;
use Heptapod\Contracts\LatestRunEvidence;
use Heptapod\Contracts\Mail\MailDesign;
use Heptapod\Contracts\RunReference;
use Heptapod\Contracts\Schema\Schema;
use Heptapod\Data\Ui\AlertSensitivityData;
use Heptapod\Data\Ui\CheckOptionData;
use Heptapod\Data\Ui\CheckRunData;
use Heptapod\Data\Ui\CheckScheduleData;
use Heptapod\Data\Ui\CheckSettingsData;
use Heptapod\Data\Ui\CheckStripData;
use Heptapod\Data\Ui\CheckTabPageData;
use Heptapod\Data\Ui\ClientData;
use Heptapod\Data\Ui\MaintenanceSiteHeaderData;
use Heptapod\Data\Ui\MetricSeriesData;
use Heptapod\Data\Ui\PerformanceNoiseData;
use Heptapod\Data\Ui\ResponsePointData;
use Heptapod\Data\Ui\ResponseTimeSeriesData;
use Heptapod\Data\Ui\RunAllCheckData;
use Heptapod\Data\Ui\SiteIdentityData;
use Heptapod\Data\Ui\SiteOverviewPageData;
use Heptapod\Data\Ui\SiteRecordData;
use Heptapod\Data\Ui\SiteRowData;
use Heptapod\Data\Ui\SiteSettingsPageData;
use Heptapod\Data\Ui\UpcomingItemData;
use Heptapod\Data\Ui\UptimeBucketData;
use Heptapod\Enums\AlertSensitivity;
use Heptapod\Enums\FreshnessSuppression;
use Heptapod\Enums\IncidentCause;
use Heptapod\Enums\NotificationCategory;
use Heptapod\Events\Notifications\AlertDelay;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\Client;
use Heptapod\Models\Incident;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Policies\SitePolicy;
use Heptapod\Settings\InstanceSettings;
use Heptapod\Support\RedactionContext;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\LaravelData\Lazy;

final readonly class SiteDetailReader
{
    public function __construct(private SitesReader $sites, private HistoryReader $history, private EventsReader $events, private IncidentsReader $incidents, private RollupReader $rollupReader, private CheckRegistry $registry, private CauseSummary $summaries, private CheckRunPresentations $presentations, private SitePolicy $policy, private CheckRunsReader $checkRuns, private UptimeReader $uptime, private MaintenanceReader $maintenanceReader, private NoResultFreshness $freshness, private PerformanceAvailability $performanceAvailability, private InstanceSettings $instanceSettings, private UpcomingReader $upcoming, private NotificationExplanationReader $explanations) {}

    public function site(User $user, int $siteId): SiteRowData
    {
        $query = $this->sites->projected($user, includeArchived: true);
        $query->getQuery()->where('sites.id', $siteId);
        $site = $query->firstOrFail();

        return $this->sites->rows(collect([$site]))[0];
    }

    public function header(User $user, int $siteId): SiteIdentityData
    {
        $query = $this->sites->projected($user, includeArchived: true);
        $query->getQuery()->select('sites.id', 'sites.name', 'sites.url', 'sites.client_id', 'sites.state', 'sites.icon_hash', 'sites.icon_backing')->where('sites.id', $siteId);

        return $this->sites->identity($query->firstOrFail());
    }

    public function client(User $user, SiteRowData $site): ClientData
    {
        $client = $this->policy->visibleClients($user, includeArchived: true)->whereKey($site->client_id)->firstOrFail()->load(Client::AvatarRelations);

        return ClientData::fromClient($client);
    }

    public function settings(User $user, int $siteId): SiteSettingsPageData
    {
        $site = $this->site($user, $siteId);
        $stored = Site::query()->findOrFail($siteId);
        Gate::forUser($user)->authorize('move', $stored);

        $redirectQuery = Check::query()->where('site_id', $siteId)->where('enabled', true);
        $redirectQuery->getQuery()->whereIn('type', [CheckKey::BrokenLinks->value, CheckKey::MixedContent->value])
            ->whereNotNull('latest_run_ended_at')->orderByDesc('latest_run_ended_at')->orderByDesc('id');
        $redirect = $redirectQuery->first();
        $redirectSummary = $redirect !== null && in_array($redirect->summary, ['crawl.start_redirect_off_site', 'crawl.start_redirect_credentials_origin'], true)
            ? $redirect->summary : null;
        $redirectTarget = null;
        if ($redirectSummary !== null) {
            $redirectTarget = $redirect?->summary_params['start_redirect_target_url'] ?? null;
            if (! is_string($redirectTarget)) {
                throw new \LogicException('Crawl redirect target must be text.');
            }
        }

        $checksQuery = Check::query();
        $checksQuery->getQuery()->where('site_id', $siteId)->orderBy('id');

        return new SiteSettingsPageData($site, $this->client($user, $site),
            $stored->resume_at?->toIso8601String(), DB::table('checks')->where('site_id', $siteId)->count(),
            DB::table('incidents')->where('site_id', $siteId)->count(),
            AlertSensitivityData::fromDelay(AlertDelay::for($stored, $this->instanceSettings)),
            AlertSensitivityData::fromDelay(AlertDelay::team($this->instanceSettings)),
            AlertSensitivityData::presets(), AlertSensitivity::bounds(), Lazy::inertia(fn (): int => $this->resultCount($user, $siteId)), $this->runAllChecks($site),
            $this->maintenance($user, $siteId), $stored->icon_fetched_at?->toIso8601String(), $stored->icon_manual_result, $redirectTarget, $redirectSummary, $this->sites->clientChoices($user), \DateTimeZone::listIdentifiers(),
            $this->checkSettings($site, array_values($checksQuery->get()->all()), static fn (Check $check): bool => Gate::forUser($user)->allows('update', $check)),
            array_map(fn (CheckKey $key): CheckOptionData => CheckOptionData::fromType($this->registry->get($key)), $this->registry->keys()));
    }

    /**
     * The settings sheet contract of each given check of one site, in the site's check order.
     *
     * @param  list<Check>  $checks
     * @param  \Closure(Check): bool  $mayUpdate  the check update policy
     * @return list<CheckSettingsData>
     */
    public function checkSettings(SiteRowData $site, array $checks, \Closure $mayUpdate): array
    {
        if ($checks === []) {
            return [];
        }
        // CheckPolicy::update is the policy of the check's site, so one evaluation covers every check of this site.
        $canUpdate = $mayUpdate($checks[0]);
        $byId = [];
        foreach ($checks as $check) {
            if ($check->site_id !== $site->id) {
                throw new \LogicException('Check settings belong to one site.');
            }
            $byId[$check->id] = $check;
        }
        $settings = [];
        foreach ($site->checks as $strip) {
            $check = $byId[$strip->id] ?? null;
            if (! $check instanceof Check) {
                continue;
            }
            $type = in_array($check->type, $this->registry->keys(), true) ? $this->registry->get($check->type) : null;
            $settings[] = new CheckSettingsData($site->id, $check->id, $check->type, $strip->label_key, $check->enabled,
                $check->interval_seconds, $type?->definition()->configSchema ?? new Schema([]),
                (object) CheckSettingsProjection::masked($check), $canUpdate);
        }

        return $settings;
    }

    public function maintenance(User $user, int $siteId): ?MaintenanceSiteHeaderData
    {
        return $this->maintenanceReader->siteHeader($user, Site::query()->findOrFail($siteId));
    }

    public function resultCount(User $user, int $siteId): int
    {
        $site = Site::query()->findOrFail($siteId);
        Gate::forUser($user)->authorize('update', $site);
        $checkIds = DB::table('checks')->where('site_id', $siteId)->select('id');

        return DB::table('check_runs')->whereIn('check_id', $checkIds)->count();
    }

    public function overviewPage(User $user, int $siteId): SiteOverviewPageData
    {
        $query = $this->sites->projected($user, includeArchived: true);
        $query->getQuery()->where('sites.id', $siteId);
        $storedSite = $query->firstOrFail();
        $site = $this->sites->rows(collect([$storedSite]), includePresentations: true)[0];
        $history = null;
        $client = null;
        $getClient = function () use ($user, $site, &$client): ClientData {
            return $client ??= $this->client($user, $site);
        };
        /** @return array{uptime: list<UptimeBucketData>, response_times: list<ResponseTimeSeriesData>, uptime_percentage: ?float} */
        $getHistory = function () use ($user, $siteId, &$history): array {
            return $history ??= $this->history->read($user, $siteId);
        };
        $uptime = array_find($site->checks, static fn (CheckStripData $check): bool => $check->key === CheckKey::Uptime);
        $lighthouse = array_find($site->checks, static fn (CheckStripData $check): bool => $check->key === CheckKey::Lighthouse && $check->enabled);

        return new SiteOverviewPageData(
            site: Lazy::closure(static fn (): SiteRowData => $site),
            header: Lazy::closure(fn (): SiteIdentityData => $this->header($user, $siteId)),
            history: Lazy::closure(static fn (): array => $getHistory()['uptime']),
            events: Lazy::closure(fn (): array => $this->events->read($user, $siteId)),
            incidents: Lazy::closure(function () use ($siteId): array {
                $query = Incident::query()->with(['site', 'check']);
                $query->getQuery()->where('site_id', $siteId)->orderByDesc('opened_at')->orderByDesc('id')->limit(20);

                return $this->incidents->presentedRows($query->get());
            }),
            client: Lazy::closure(static fn (): ClientData => $getClient()),
            run_all_checks: Lazy::closure(fn (): array => $this->runAllChecks($site)),
            can_manage: Lazy::closure(static fn (): bool => $getClient()->archived_at === null && $user->hasAnyRole(['Owner', 'Admin'])),
            uptime_ranges: Lazy::closure(fn (): array => $this->uptimeRanges($site, $getHistory()['uptime_percentage'])),
            run_all_result: Lazy::closure(static function (): ?array {
                $result = request()->session()->get('run_all');
                if ($result !== null && ! is_array($result)) {
                    throw new \LogicException('Run-all result must be an array.');
                }

                return $result;
            }),
            maintenance: Lazy::closure(fn (): ?MaintenanceSiteHeaderData => $this->maintenance($user, $siteId)),
            record: Lazy::inertiaDeferred(fn (): ?SiteRecordData => $uptime instanceof CheckStripData ? $this->record($siteId, $uptime->id) : null, 'overview'),
            upcoming: Lazy::inertiaDeferred(fn (): array => $this->nextUpcoming($user, $siteId), 'overview'),
            alert_channels: Lazy::inertiaDeferred(fn (): ?int => $uptime?->enabled === true ? $this->alertChannels($user, $uptime->id, NotificationCategory::Outage) : null, 'overview'),
            lighthouse: Lazy::inertiaDeferred(fn (): ?CheckHistoryData => $lighthouse instanceof CheckStripData
                ? $this->checkHistory($user, Check::query()->with('site')->findOrFail($lighthouse->id)) : null, 'overview'),
            can_move: Lazy::closure(static fn (): bool => $user->hasAnyRole(['Owner', 'Admin'])),
        );
    }

    /** Uptime outages overlapping the last 30 days with Uptime's own downtime (maintenance and unmonitored time excluded), and the Performance tab's latest hourly average. */
    private function record(int $siteId, int $uptimeCheckId): SiteRecordData
    {
        $now = CarbonImmutable::now('UTC');
        $from = $now->subDays(30);
        $outages = DB::table('incidents')->where('site_id', $siteId)->where('check_id', $uptimeCheckId)->where('cause', IncidentCause::Failed->value)
            ->where('first_failure_at', '<', $now->format('Y-m-d H:i:s.uP'))
            ->where(static fn (QueryBuilder $overlap): QueryBuilder => $overlap->where('first_failure_at', '>=', $from->format('Y-m-d H:i:s.uP'))
                ->orWhereNull('resolved_at')->orWhere('resolved_at', '>', $from->format('Y-m-d H:i:s.uP')))
            ->selectRaw('count(*) AS outage_count, max(first_failure_at) AS last_started_at')->first();
        if ($outages === null || ! is_numeric($outages->outage_count)) {
            throw new \LogicException('Site outage aggregate is invalid.');
        }
        [, $downtimeMs] = $this->uptime->durations([$uptimeCheckId], $from, $now)[$uptimeCheckId]
            ?? throw new \LogicException('The uptime window must cover its own check.');
        $lastStartedAt = $outages->last_started_at;
        if ($lastStartedAt !== null && ! is_string($lastStartedAt)) {
            throw new \LogicException('Site outage start must be a timestamp.');
        }
        // The Performance tab shows the newest measured hour of its last 24 hourly averages.
        $hours = array_slice($this->hourlyResponses($uptimeCheckId)['average']->points, -24);
        $response = array_find(array_reverse($hours), static fn (ResponsePointData $point): bool => $point->value !== null)?->value;

        return new SiteRecordData((int) $outages->outage_count, intdiv($downtimeMs, 1000),
            $lastStartedAt === null ? null : CarbonImmutable::parse($lastStartedAt)->utc()->toIso8601String(), $response);
    }

    /** @return list<UpcomingItemData> the next certificate expiry, domain expiry and maintenance window of one site within a year */
    private function nextUpcoming(User $user, int $siteId): array
    {
        $next = [];
        // Maintenance is read on its own: a daily series would otherwise fill the reader's 100 items before a later expiry.
        foreach ([['maintenance'], ['certificate', 'domain']] as $types) {
            foreach ($this->upcoming->read($user, 365, $types, siteId: $siteId) as $item) {
                $next[$item->type] ??= $item;
            }
        }
        usort($next, static fn (UpcomingItemData $a, UpcomingItemData $b): int => [$a->date, $a->key] <=> [$b->date, $b->key]);

        return $next;
    }

    /** How many channels the one-line "Who gets this" names: every channel told now or later, or already alerted about the open episode; null when the check never reports the category. */
    private function alertChannels(User $user, int $checkId, NotificationCategory $category): ?int
    {
        $check = Check::query()->with('site.client')->findOrFail($checkId);
        $explanation = $this->explanations->explain($user, $check, $category, CarbonImmutable::now('UTC'));
        if ($explanation['status'] === 'not_applicable') {
            return null;
        }

        return count(array_filter($explanation['recipients'], static fn (array $recipient): bool => $recipient['status'] !== 'not_sent'
            || $recipient['reason'] === 'notifications.suppression.episode_already_alerted'));
    }

    /** @return list<RunAllCheckData> */
    private function runAllChecks(SiteRowData $site): array
    {
        $checks = [];
        foreach ($site->checks as $check) {
            if (! $check->enabled || ! in_array($check->key, $this->registry->keys(), true)) {
                continue;
            }
            $definition = $this->registry->get($check->key)->definition();
            $checks[] = new RunAllCheckData($check->id, $check->key, $definition->queue, $definition->timeout,
                $this->registry->availability($check->key)->reason === null);
        }

        return $checks;
    }

    /** @return array{hours24: ?float, days7: ?float, days30: ?float, days90: ?float} */
    private function uptimeRanges(SiteRowData $site, ?float $hours24): array
    {
        $uptimeIds = array_values(array_map(static fn (CheckStripData $check): int => $check->id,
            array_filter($site->checks, static fn (CheckStripData $check): bool => $check->key === CheckKey::Uptime)));
        $now = CarbonImmutable::now('UTC');
        $ranges = $this->uptime->percentagesForRanges($uptimeIds, ['days7' => $now->subDays(7), 'days90' => $now->subDays(90)], $now);

        return ['hours24' => $hours24, 'days7' => $ranges['days7'], 'days30' => $site->uptime, 'days90' => $ranges['days90']];
    }

    public function checkPage(User $user, int $siteId, int $checkId): CheckTabPageData
    {
        $site = $this->site($user, $siteId);
        $checkQuery = Check::query();
        $checkQuery->getQuery()->where('site_id', $siteId)->where('id', $checkId);
        $check = $checkQuery->firstOrFail();
        // Performance is derived from uptime observations, so its percentile must use that same source.
        $p95CheckId = $check->type === CheckKey::Performance
            ? array_find($site->checks, static fn (CheckStripData $item): bool => $item->key === CheckKey::Uptime)?->id
            : $checkId;
        $suppression = $this->freshness->suppression($check, CarbonImmutable::now('UTC'));
        $canUpdate = null;
        $getCanUpdate = static function () use ($user, $check, &$canUpdate): bool {
            return $canUpdate ??= Gate::forUser($user)->allows('update', $check);
        };
        $runs = null;
        $getRuns = function () use ($checkId, &$runs): array {
            return $runs ??= $this->runs($checkId);
        };
        $metrics = null;
        $hourly = null;
        $getHourly = function () use ($p95CheckId, &$hourly): ?array {
            return $p95CheckId === null ? null : ($hourly ??= $this->hourlyResponses($p95CheckId));
        };
        $getMetrics = function () use ($checkId, $check, &$metrics, $getHourly): array {
            if ($metrics === null) {
                $metrics = $this->metrics($checkId, $check->type === CheckKey::Uptime);
                $stats = $check->type === CheckKey::Performance ? $getHourly() : null;
                if ($stats !== null) {
                    $metrics[] = $stats['average'];
                }
            }

            return $metrics;
        };

        return new CheckTabPageData(
            site: Lazy::closure(static fn (): SiteRowData => $site),
            header: Lazy::closure(fn (): SiteIdentityData => $this->header($user, $siteId)),
            check: Lazy::closure(function () use ($site, $checkId, $check): CheckStripData {
                $strip = array_find($site->checks, static fn (CheckStripData $item): bool => $item->id === $checkId);
                if (! $strip instanceof CheckStripData) {
                    throw new \LogicException('Scoped site projection must contain its check.');
                }

                return $strip->withPresentation($this->currentPresentation($check));
            }),
            can_run: Lazy::closure(static fn (): bool => $getCanUpdate()),
            schedule: Lazy::closure(fn (): CheckScheduleData => $this->checkRuns->schedule($check)),
            failure_threshold: Lazy::closure(static fn (): int => $check->failure_threshold),
            runs: Lazy::closure(static fn (): array => $getRuns()),
            metrics: Lazy::closure(static fn (): array => $getMetrics()),
            check_settings: Lazy::closure(fn (): CheckSettingsData => $this->checkSettings($site, [$check], static fn (): bool => $getCanUpdate())[0]
                ?? throw new \LogicException('Scoped site projection must contain its check.')),
            detail: Lazy::closure(fn (): ?CheckDetailData => $this->detail($check)),
            response_times: Lazy::closure(fn (): array => $this->history->read($user, $siteId, $checkId)['response_times']),
            phase_series: Lazy::closure(fn (): array => $check->type === CheckKey::Uptime ? $this->checkPhases($getMetrics()) : []),
            response_p95_hourly: Lazy::closure(static fn (): ?MetricSeriesData => $getHourly()['p95'] ?? null),
            client: Lazy::closure(fn (): ClientData => $this->client($user, $site)),
            history: Lazy::closure(fn (): ?CheckHistoryData => $this->checkHistory($user, $check)),
            run_all_checks: Lazy::closure(fn (): array => $this->runAllChecks($site)),
            maintenance: Lazy::closure(fn (): ?MaintenanceSiteHeaderData => $this->maintenance($user, $siteId)),
            performance_noise: Lazy::closure(fn (): ?PerformanceNoiseData => $check->type === CheckKey::Performance ? $this->performanceNoise($check, $getRuns()) : null),
            performance_unavailable: Lazy::closure(fn (): ?CheckRunPresentationPartData => $this->performanceAvailability->forCheck($check, $suppression)),
            freshness_suppressed_by: Lazy::closure(static fn (): ?FreshnessSuppression => $suppression?->reason),
            freshness_suppressed_since: Lazy::closure(static fn (): ?string => $suppression?->sinceAt()?->toIso8601String()),
            freshness_latest_error_at: Lazy::closure(static fn (): ?string => $suppression?->latestErrorAt()?->toIso8601String()),
            freshness_suppression: Lazy::closure(static fn (): ?CheckRunPresentationPartData => $suppression instanceof FreshnessSuppressionState ? new CheckRunPresentationPartData(
                $suppression->headlineKey($check->type), ['date' => $suppression->at->toIso8601String()],
            ) : null),
            mail_design: Lazy::closure(fn (): MailDesign => $this->instanceSettings->mail_design),
            alert_channels: Lazy::inertiaDeferred(fn (): ?int => $check->enabled
                ? $this->alertChannels($user, $checkId, $check->type === CheckKey::Uptime ? NotificationCategory::Outage : NotificationCategory::Failure) : null),
            can_move: Lazy::closure(static fn (): bool => $user->hasAnyRole(['Owner', 'Admin'])),
        );
    }

    /** @param list<CheckRunData> $runs */
    private function performanceNoise(Check $check, array $runs): PerformanceNoiseData
    {
        $minimum = $this->registry->get($check->type)->definition()->minimumIncreaseInMs
            ?? throw new \LogicException('Performance needs its minimum increase.');
        $points = [];
        $seen = [];
        foreach ($runs as $run) {
            $hour = CarbonImmutable::parse($run->ended_at)->utc()->startOfHour()->toIso8601String();
            if (isset($seen[$hour])) {
                continue;
            }
            $seen[$hour] = true;
            $params = $run->summary_params;
            // Confirmation/success summaries do not retain a p90/median threshold. Do not borrow older facts for that hour.
            if (! isset($params['median'], $params['baseline'], $params['p90'], $params['relative_threshold'])) {
                continue;
            }
            foreach (['median', 'baseline', 'p90', 'relative_threshold'] as $name) {
                if (! is_int($params[$name]) && ! is_float($params[$name])) {
                    throw new \LogicException('Retained performance thresholds must be numeric.');
                }
            }
            $baseline = (float) $params['baseline'];
            $median = (float) $params['median'];
            $relativeThreshold = (float) $params['relative_threshold'];
            $points[] = ['time' => $hour, 'baseline' => $baseline, 'median' => $median,
                'threshold' => max($relativeThreshold, $baseline + $minimum),
                'marked' => $median > $relativeThreshold && $minimum <= $median - $baseline];
        }

        return new PerformanceNoiseData($minimum, $points);
    }

    /** @return list<MetricSeriesData> */
    private function metrics(int $checkId, bool $timingPeaks): array
    {
        $until = CarbonImmutable::now('UTC');
        $samples = $timingPeaks
            ? $this->rollupReader->responsePeaks([$checkId], $until->subDay(), $until, 5)[$checkId]
            : $this->rollupReader->recentMany([$checkId], $until->subDay(), $until)[$checkId];
        $points = [];
        foreach ($samples as $bucket) {
            if (array_key_exists('metric_maxima', $bucket)) {
                $values = $bucket['metric_maxima'];
            } else {
                $values = $bucket['metric_averages'];
            }
            foreach ($values as $key => $value) {
                $points[$key][] = new ResponsePointData($bucket['bucket_start']->toIso8601String(), $value,
                    $bucket['failed_count'], $bucket['errored_count'], $bucket['warning_count']);
            }
        }
        $metrics = [];
        foreach ($points as $key => $values) {
            $metrics[] = new MetricSeriesData($key, str_ends_with($key, '_in_seconds') ? 's' : '', $values);
        }

        return $metrics;
    }

    /** @param list<MetricSeriesData> $metrics
     * @return list<MetricSeriesData>
     */
    private function checkPhases(array $metrics): array
    {
        $keys = ['dns_time_in_seconds', 'tcp_time_in_seconds', 'ssl_handshake_time_in_seconds', 'remote_server_processing_time_in_seconds', 'download_time_in_seconds'];
        $phases = [];
        foreach ($metrics as $series) {
            if (! in_array($series->key, $keys, true)) {
                continue;
            }
            $phases[] = new MetricSeriesData($series->key, 'ms', array_map(
                static fn (ResponsePointData $point): ResponsePointData => new ResponsePointData($point->time, $point->value === null ? null : $point->value * 1000,
                    $point->failure_count, $point->error_count, $point->warning_count),
                $series->points,
            ));
        }

        return $phases;
    }

    /** @return array{average: MetricSeriesData, p95: MetricSeriesData} */
    private function hourlyResponses(int $checkId): array
    {
        $end = CarbonImmutable::now('UTC')->startOfHour();
        $p95 = [];
        $average = [];
        foreach ($this->rollupReader->read($checkId, 'hourly', $end->subDays(7), $end) as $bucket) {
            $value = $bucket['metric_p95']['total_time_in_seconds'] ?? null;
            $p95[] = new ResponsePointData($bucket['bucket_start']->toIso8601String(), $value === null ? null : 1000 * $value);
            $mean = $bucket['metric_averages']['total_time_in_seconds'] ?? null;
            $average[] = new ResponsePointData($bucket['bucket_start']->toIso8601String(), $mean === null ? null : 1000 * $mean);
        }

        return ['p95' => new MetricSeriesData('total_time_in_seconds', 'ms', $p95),
            'average' => new MetricSeriesData('performance.hourly_average', 'ms', $average)];
    }

    public function currentPresentation(Check $check): ?CheckRunPresentationData
    {
        $run = $check->latest_run_id === null || $check->latest_run_ended_at === null ? null
            : CheckRun::forRun(new RunReference($check->latest_run_id, $check->latest_run_ended_at))
                ->where('check_id', $check->id)->first();

        return $this->presentations->forRun($check, $run);
    }

    public function checkHistory(User $user, Check $check): ?CheckHistoryData
    {
        $type = in_array($check->type, $this->registry->keys(), true) ? $this->registry->get($check->type) : null;
        $projection = $type instanceof CheckHistoryProvider ? $type->history($check->id, Gate::forUser($user)->allows('update', $check)) : null;
        if (! $projection instanceof CheckHistoryData) {
            return null;
        }
        $site = $check->site ?? throw new \LogicException('A check must belong to a site.');
        $context = RedactionContext::forSite($site, $check);

        return $projection->redacted($context->secrets, $context->urls);
    }

    public function detail(Check $check): ?CheckDetailData
    {
        $latestRun = null;
        if ($check->latest_run_id !== null && $check->latest_run_ended_at !== null) {
            $run = CheckRun::forRun(new RunReference($check->latest_run_id, $check->latest_run_ended_at))
                ->where('check_id', $check->id)->first();
            if ($run instanceof CheckRun) {
                $latestRun = new LatestRunEvidence($run->reference(), $run->result, $run->evidence);
            }
        }

        $type = in_array($check->type, $this->registry->keys(), true) ? $this->registry->get($check->type) : null;
        $detail = $type instanceof CheckDetailProvider ? $type->detail($check->id, $latestRun) : null;
        if (! $detail instanceof CheckDetailData) {
            return null;
        }
        $site = $check->site ?? throw new \LogicException('A check must belong to a site.');
        $context = RedactionContext::forSite($site, $check);

        return $detail->redacted($context->secrets, $context->urls);
    }

    /** @return list<CheckRunData> */
    public function runs(int $checkId, ?CarbonImmutable $from = null, ?CarbonImmutable $until = null): array
    {
        $query = CheckRun::query()->with('check.site');
        $query->getQuery()->where('check_id', $checkId)->orderByDesc('ended_at')->orderByDesc('id')->limit(20);
        if ($from instanceof CarbonImmutable) {
            $query->getQuery()->where('ended_at', '>=', $from);
        }
        if ($until instanceof CarbonImmutable) {
            $query->getQuery()->where('ended_at', '<=', $until);
        }
        $runs = [];
        foreach ($query->get() as $run) {
            $check = $run->check ?? throw new \LogicException('A retained run must have a check.');
            $summary = $this->summaries->project($check, $run);
            $runs[] = new CheckRunData($run->id, CheckResult::from($run->result->value), $run->started_at?->toIso8601String(),
                $run->ended_at->toIso8601String(), $summary['key'] ?? throw new \LogicException('A run must have a summary.'), $summary['params'], $this->presentations->forRun($check, $run));
        }

        return $runs;
    }
}
