import { TooltipHint } from '@/components/truncated-text';
import { PageContainer } from '@/components/page-container';
import { AbsoluteTime } from '@/components/absolute-time';
import { SectionHeading } from '@/components/section-heading';
import { StatusBadge } from '@/components/monitoring';
import { PageHeader } from '@/components/page-header';
import { show as checkShow } from '@/routes/sites/checks';
import { Head, usePage } from '@inertiajs/react';
import { useHttpRequest } from '@/hooks/use-http-request';
import { Layers } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { InfoTooltip } from '@/components/info-tooltip';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import {
    ListHeader,
    listRowPadding,
    tableRowStyle,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import { focusRingClass } from '@/lib/interaction';
import { StatTile } from '@/components/stat-tile';
import { DatabaseGrowth } from '@/components/database-growth';
import { useFormat } from '@/hooks/use-format';
import { data as readData } from '@/routes/settings/system';
import { TextLink } from '@/components/text-link';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';

type Metric = Heptapod.SystemHealth.HealthMetric;
type Page = Heptapod.Data.Ui.SystemHealthPageData;

/** Human names for the configured queues (`connection:queue` keys) and browser services; the technical name stays in the tooltip. */
const queueLabels: Record<string, string> = {
    'checks-fast': 'system.queue.checks_fast',
    'checks-crawl': 'system.queue.checks_crawl',
    'checks-browser': 'system.queue.checks_browser',
    notifications: 'system.queue.notifications',
    reports: 'system.queue.reports',
    icons: 'system.queue.icons',
    broadcasts: 'system.queue.broadcasts',
};
const serviceLabels: Record<string, string> = {
    lighthouse: 'system.service.lighthouse',
    gotenberg: 'system.service.gotenberg',
};
function technicalLabel(labels: Record<string, string>, name: string): string {
    const label = labels[name];
    if (!label) throw new Error(`System health: no label for ${name}`);
    return label;
}

function TechnicalName({ label, name }: { label: string; name: string }) {
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span
                    tabIndex={0}
                    className={cn(
                        'min-w-0 rounded-sm wrap-anywhere',
                        focusRingClass,
                    )}
                >
                    {label}
                </span>
            </TooltipTrigger>
            <TooltipContent>{name}</TooltipContent>
        </Tooltip>
    );
}

function HealthState({
    metric,
    queue = false,
}: {
    metric: Metric;
    queue?: boolean;
}) {
    const labels = queue
        ? {
              ok: 'Within backlog threshold',
              warn: 'Elevated backlog',
              down: 'High backlog',
              'not-configured': 'Unknown',
          }
        : {
              ok: 'Observed OK',
              warn: 'Warning',
              down: 'Unreachable',
              'not-configured': 'Not configured',
          };
    const states = {
        ok: 'ok',
        warn: 'warning',
        down: 'check-error',
        'not-configured': 'unavailable',
    } as const;
    return (
        <StatusBadge
            state={states[metric.status]}
            label={labels[metric.status]}
        />
    );
}

export default function System({ systemPage }: { systemPage: Page }) {
    const { t } = useTranslation();
    const { number, timestamp, exact, duration } = useFormat();
    const [page, setPage] = useState(systemPage);
    const [failed, setFailed] = useState(false);
    const [now, setNow] = useState(() => Date.now());
    const query = useHttpRequest<Record<string, never>, Page>();
    useEffect(() => {
        setPage(systemPage);
        setFailed(false);
    }, [systemPage]);
    useEffect(() => {
        const timer = window.setInterval(() => setNow(Date.now()), 1000);
        return () => window.clearInterval(timer);
    }, []);
    const refresh = async () => {
        try {
            const canonical = await query.get(readData.url());
            setPage(canonical);
            setFailed(false);
        } catch {
            // A failed read preserves the last observation and explicitly marks it unverified.
            setFailed(true);
        }
    };
    const health = page.health;
    const stale = now - new Date(page.observed_at).getTime() > 60_000;
    const heartbeat = (metric: Metric) =>
        metric.value === null
            ? t('Unknown')
            : t(':duration ago', { duration: duration(metric.value) });
    const heartbeatDetail = (metric: Metric) =>
        metric.value === null
            ? t('No heartbeat recorded')
            : metric.status === 'ok'
              ? t('Recent heartbeat at sample time')
              : t('Stale heartbeat at sample time');
    const services = Object.entries(health.browserServices);
    const maintenance = health.checkDataMaintenance;
    const evaluation = health.systemIssueEvaluation;
    const evaluationFailed =
        evaluation.failed_at !== null &&
        (evaluation.evaluated_at === null ||
            evaluation.failed_at > evaluation.evaluated_at);
    const mail = health.mailTransport;
    const { mailLogOnly } = usePage().props;
    const needsAttention =
        maintenance.status === 'warn' ||
        health.dispatcher.status !== 'ok' ||
        health.scheduler.status !== 'ok' ||
        health.horizon.status !== 'ok' ||
        health.recentFailedJobs.status !== 'ok' ||
        services.some(([, metric]) => metric.status === 'down') ||
        health.reverb.status === 'down' ||
        Object.values(health.queues).some((metric) => metric.status !== 'ok');

    return (
        <PageContainer aria-busy={query.processing}>
            <Head title={t('System health')} />
            <PageHeader
                title={t('System health')}
                description={t('pages.system.description')}
                notices={
                    <>
                        {failed && (
                            <Alert tone="warning">
                                <AlertTitle>
                                    {t('Could not refresh system health.')}
                                </AlertTitle>
                                <AlertDescription>
                                    {t(
                                        'The previous snapshot is shown; try Refresh again.',
                                    )}
                                </AlertDescription>
                            </Alert>
                        )}
                        {health.deliveryPrivateHosts.length > 0 && (
                            <Alert tone="warning">
                                <AlertTitle>
                                    {t('Private delivery hosts allowed')}
                                </AlertTitle>
                                <AlertDescription className="font-mono break-words">
                                    {health.deliveryPrivateHosts.join(', ')}
                                </AlertDescription>
                            </Alert>
                        )}
                    </>
                }
                refresh={{
                    onRefresh: () => void refresh(),
                    refreshing: query.processing,
                }}
            />
            <div
                role="status"
                className="space-y-1 text-xs text-muted-foreground"
            >
                <TooltipHint content={exact(page.observed_at)}>
                    <p className="font-mono tabular-nums">
                        {t('Observed at :time', {
                            time: timestamp(page.observed_at),
                        })}
                    </p>
                </TooltipHint>
                <p>
                    {t(
                        'Read-only snapshot: Refresh reads telemetry and does not start monitoring.',
                    )}
                </p>
                {stale && (
                    <p className="font-medium text-foreground">
                        {t(
                            'Snapshot older than one minute. Refresh to read current conditions.',
                        )}
                    </p>
                )}
            </div>
            {(health.systemIssues.length > 0 || evaluationFailed) && (
                <section
                    aria-labelledby="system-issues-heading"
                    className="space-y-3"
                >
                    <SectionHeading id="system-issues-heading">
                        {t('Open system issues')}
                    </SectionHeading>
                    {evaluationFailed && (
                        <Alert tone="danger">
                            <AlertTitle>
                                {t('System alerts could not be evaluated.')}
                            </AlertTitle>
                            <TooltipHint
                                content={exact(evaluation.failed_at ?? '')}
                            >
                                <AlertDescription className="font-mono">
                                    {t('Failed :time: :error', {
                                        time: timestamp(
                                            evaluation.failed_at ?? '',
                                        ),
                                        error: evaluation.error ?? '',
                                    })}
                                </AlertDescription>
                            </TooltipHint>
                        </Alert>
                    )}
                    {health.systemIssues.map((issue) => (
                        <Alert
                            key={`${issue.kind}:${issue.scope}`}
                            tone={
                                issue.severity === 'down' ? 'danger' : 'warning'
                            }
                        >
                            <AlertTitle>{t(issue.title_key)}</AlertTitle>
                            <AlertDescription>
                                <TooltipHint content={exact(issue.openedAt)}>
                                    <p className="font-mono tabular-nums">
                                        {t('Since :time', {
                                            time: timestamp(issue.openedAt),
                                        })}
                                    </p>
                                </TooltipHint>
                                <p>{t(issue.action_key)}</p>
                                {issue.kind === 'check_not_fresh' &&
                                    typeof issue.details.no_result_cause ===
                                        'string' && (
                                        <p>
                                            {t(issue.details.no_result_cause)}
                                        </p>
                                    )}
                                {issue.kind === 'check_not_fresh' && (
                                    <TextLink
                                        href={checkShow.url({
                                            site: Number(issue.details.site_id),
                                            check: Number(
                                                issue.details.check_id,
                                            ),
                                        })}
                                    >
                                        {issue.details.site_name} ·{' '}
                                        {t(
                                            `checks.${issue.details.check_type}.label`,
                                        )}
                                    </TextLink>
                                )}
                                {issue.kind === 'disk_space_low' && (
                                    <>
                                        <p className="font-mono break-all">
                                            {issue.details.path}
                                        </p>
                                        <p>
                                            {t(
                                                issue.details.severity ===
                                                    'unreadable'
                                                    ? 'Cannot measure disk space'
                                                    : issue.details.severity ===
                                                        'critical'
                                                      ? 'Critical'
                                                      : 'Warning',
                                            )}
                                            {issue.details.free_percent !=
                                                null &&
                                                ` · ${t('Free space: :percent%', { percent: number(Number(issue.details.free_percent), { minimumFractionDigits: 2, maximumFractionDigits: 2 }) })}`}
                                        </p>
                                        {issue.details.severity ===
                                            'unreadable' && (
                                            <p className="break-words">
                                                {t(
                                                    `disk.read_failure.${typeof issue.details.reason === 'string' ? issue.details.reason : 'read_failed'}`,
                                                    {
                                                        path: issue.details
                                                            .path,
                                                    },
                                                )}
                                            </p>
                                        )}
                                    </>
                                )}
                            </AlertDescription>
                        </Alert>
                    ))}
                </section>
            )}
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatTile
                    bordered
                    label={t('Scheduler')}
                    value={heartbeat(health.scheduler)}
                    meta={heartbeatDetail(health.scheduler)}
                />
                <StatTile
                    bordered
                    label={t('Check activity')}
                    value={
                        page.last_result_at === null ? (
                            t('Unknown')
                        ) : (
                            <AbsoluteTime
                                value={page.last_result_at}
                                mode="time"
                            />
                        )
                    }
                    meta={
                        <>
                            {page.last_result_at === null ? (
                                t('No completed check recorded')
                            ) : (
                                <AbsoluteTime value={page.last_result_at} />
                            )}
                            {page.check_errors > 0 && (
                                <>
                                    <br />
                                    {t('{{count}} check errors', {
                                        count: page.check_errors,
                                        formattedCount: number(
                                            page.check_errors,
                                        ),
                                    })}
                                </>
                            )}
                            <br />
                            {t('On sites you can see')}
                        </>
                    }
                />
                <StatTile
                    bordered
                    label={t('Failed jobs')}
                    value={
                        health.recentFailedJobs.value === null
                            ? t('Unknown')
                            : number(health.recentFailedJobs.value)
                    }
                    meta={
                        <>
                            {t('Last 24 hours')}
                            <br />
                            {t('{{count}} total retained failures', {
                                count: health.failedJobsTotal,
                                formattedCount: number(health.failedJobsTotal),
                            })}
                        </>
                    }
                />
                <StatTile
                    bordered
                    label={t('Database')}
                    value={t(':size MB', {
                        size: number(health.databaseBytes / 1024 / 1024, {
                            maximumFractionDigits: 1,
                        }),
                    })}
                    meta={t(
                        'Current database size. Growth is projected below.',
                    )}
                />
            </div>
            <section aria-labelledby="queue-heading" className="space-y-2">
                <SectionHeading id="queue-heading">
                    {t('Queues')}
                </SectionHeading>
                <div className="@container/table overflow-hidden rounded-lg border">
                    {Object.keys(health.queues).length > 0 && (
                        <ListHeader
                            className="grid-cols-[minmax(0,1fr)_5rem_12rem] sm:grid"
                            aria-hidden="true"
                        >
                            <span>{t('Queue')}</span>
                            <span>{t('Waiting')}</span>
                            <span>{t('Backlog state')}</span>
                        </ListHeader>
                    )}
                    <ul>
                        {Object.entries(health.queues).map(([name, metric]) => (
                            <li
                                key={name}
                                className={cn(
                                    listRowPadding,
                                    tableRowStyle,
                                    'grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-2 last:border-b-0 sm:grid-cols-[minmax(0,1fr)_5rem_12rem]',
                                )}
                            >
                                <span className="min-w-0 text-cell">
                                    <TechnicalName
                                        label={t(
                                            technicalLabel(
                                                queueLabels,
                                                name.slice(
                                                    name.indexOf(':') + 1,
                                                ),
                                            ),
                                        )}
                                        name={name}
                                    />
                                </span>
                                <span className="font-mono text-cell tabular-nums">
                                    <span className="sr-only">
                                        {t('Waiting')}:{' '}
                                    </span>
                                    {metric.value === null
                                        ? t('Unknown')
                                        : number(metric.value)}
                                </span>
                                <span className="col-span-2 sm:col-span-1">
                                    <HealthState metric={metric} queue />
                                </span>
                            </li>
                        ))}
                    </ul>
                    {Object.keys(health.queues).length === 0 && (
                        <EmptyState
                            bare
                            icon={Layers}
                            title={t(
                                'No queues configured for this environment.',
                            )}
                            description={t(
                                'Queue depth appears here once the environment defines queues.',
                            )}
                        />
                    )}
                </div>
                <p className="text-xs leading-relaxed text-muted-foreground">
                    {t('Queue depth does not prove jobs are processing.')}{' '}
                    <InfoTooltip>
                        {t(
                            'Per-queue workers, oldest job age and checks per minute are not measured.',
                        )}
                    </InfoTooltip>
                </p>
            </section>
            {needsAttention && (
                <Alert tone="warning">
                    <AlertTitle>
                        {t('Monitoring observations need attention.')}
                    </AlertTitle>
                    <AlertDescription>
                        {t(
                            'Missing heartbeats, a backlog or an unavailable service can delay results.',
                        )}{' '}
                        <InfoTooltip>
                            {t(
                                'This snapshot does not prove other checks are unaffected.',
                            )}
                        </InfoTooltip>
                    </AlertDescription>
                </Alert>
            )}
            <section aria-labelledby="conditions-heading" className="space-y-3">
                <SectionHeading id="conditions-heading">
                    {t('Service conditions at sample time')}
                </SectionHeading>
                <dl className="grid gap-x-6 sm:grid-cols-2">
                    <div className="space-y-1 border-b border-(--border-soft) py-3">
                        <dt className="text-cell font-medium">
                            {t('Dispatcher')}
                        </dt>
                        <dd className="text-xs text-muted-foreground">
                            {heartbeat(health.dispatcher)} ·{' '}
                            {heartbeatDetail(health.dispatcher)}
                        </dd>
                    </div>
                    <div className="space-y-1 border-b border-(--border-soft) py-3">
                        <dt className="text-cell font-medium">
                            {t('Horizon workers')}
                        </dt>
                        <dd className="text-xs text-muted-foreground">
                            {health.workers.length === 0 ||
                            health.horizon.value === null
                                ? t('Unknown — no recent worker observations')
                                : t('{{count}} running workers observed', {
                                      count: health.horizon.value,
                                      formattedCount: number(
                                          health.horizon.value,
                                      ),
                                  })}
                            <br />
                            {health.horizon.status === 'ok'
                                ? t('Running Horizon observed')
                                : t('No running Horizon observed')}
                        </dd>
                    </div>
                    <div className="space-y-1 border-b border-(--border-soft) py-3">
                        <dt className="text-cell font-medium">
                            {t('Worker memory')}
                        </dt>
                        <dd className="text-xs text-muted-foreground">
                            {health.workerMemory.value === null
                                ? t(
                                      health.workerMemory.unavailableReason ===
                                          'no_sample'
                                          ? 'No sample yet'
                                          : health.workerMemory
                                                  .unavailableReason ===
                                              'unlimited'
                                            ? 'No memory limit set'
                                            : 'Unknown',
                                  )
                                : t(':percent% of worker memory limit', {
                                      percent: number(
                                          health.workerMemory.value,
                                      ),
                                  })}
                        </dd>
                    </div>
                    {services.map(([name, metric]) => (
                        <div
                            key={name}
                            className="space-y-1 border-b border-(--border-soft) py-3"
                        >
                            <dt className="text-cell font-medium">
                                <TechnicalName
                                    label={t(
                                        technicalLabel(serviceLabels, name),
                                    )}
                                    name={name}
                                />
                            </dt>
                            <dd>
                                <HealthState metric={metric} />
                                {metric.value !== null && (
                                    <span className="ml-2 font-mono text-xs text-muted-foreground">
                                        {t('HTTP :status', {
                                            status: metric.value,
                                        })}
                                    </span>
                                )}
                            </dd>
                        </div>
                    ))}
                    <div className="space-y-1 border-b border-(--border-soft) py-3">
                        <dt className="text-cell font-medium">{t('Reverb')}</dt>
                        <dd>
                            <HealthState metric={health.reverb} />
                            <p className="mt-1 text-xs text-muted-foreground">
                                {t(
                                    'TCP reachability only; not event delivery proof.',
                                )}
                            </p>
                        </dd>
                    </div>
                    <div className="space-y-1 border-b border-(--border-soft) py-3">
                        <dt className="text-cell font-medium">
                            {t('Last successful heartbeat')}
                        </dt>
                        <dd className="text-xs text-muted-foreground">
                            {health.lastExternalPing === null ? (
                                t('No successful heartbeat recorded')
                            ) : (
                                <AbsoluteTime value={health.lastExternalPing} />
                            )}
                        </dd>
                    </div>
                    <div className="space-y-1 border-b border-(--border-soft) py-3">
                        <dt className="text-cell font-medium">
                            {t('Check history processing')}
                        </dt>
                        <dd className="space-y-1 text-xs text-muted-foreground">
                            {maintenance.status === 'not-configured' ? (
                                <p>{t('No processing run recorded')}</p>
                            ) : (
                                <HealthState
                                    metric={{
                                        status: maintenance.status,
                                        value: null,
                                        error: null,
                                        unavailableReason: null,
                                    }}
                                />
                            )}
                            {maintenance.lastSuccessAt !== null &&
                                maintenance.lastSuccessSeconds !== null && (
                                    <TooltipHint
                                        content={exact(
                                            maintenance.lastSuccessAt,
                                        )}
                                    >
                                        <p className="font-mono tabular-nums">
                                            {t(
                                                'Last success :time, took :duration',
                                                {
                                                    time: timestamp(
                                                        maintenance.lastSuccessAt,
                                                    ),
                                                    duration: duration(
                                                        Math.max(
                                                            1,
                                                            Math.round(
                                                                maintenance.lastSuccessSeconds,
                                                            ),
                                                        ),
                                                    ),
                                                },
                                            )}
                                        </p>
                                    </TooltipHint>
                                )}
                            {maintenance.lastFailureAt !== null && (
                                <TooltipHint
                                    content={exact(maintenance.lastFailureAt)}
                                >
                                    <p className="font-mono tabular-nums">
                                        {t('Last failure :time', {
                                            time: timestamp(
                                                maintenance.lastFailureAt,
                                            ),
                                        })}
                                        {maintenance.consecutiveFailures > 0 &&
                                            ` · ${t('{{count}} failed runs in a row', { count: maintenance.consecutiveFailures, formattedCount: number(maintenance.consecutiveFailures) })}`}
                                    </p>
                                </TooltipHint>
                            )}
                        </dd>
                    </div>
                    <div className="space-y-1 border-b border-(--border-soft) py-3">
                        <dt className="text-cell font-medium">
                            {t('Mail delivery')}
                        </dt>
                        <dd className="space-y-1 text-xs text-muted-foreground">
                            <TooltipHint
                                content={
                                    mailLogOnly || mail.lastSuccessAt === null
                                        ? undefined
                                        : exact(mail.lastSuccessAt)
                                }
                            >
                                <p className="font-mono tabular-nums">
                                    {mailLogOnly
                                        ? t('Logged, not delivered')
                                        : t('Last successful send :time', {
                                              time:
                                                  mail.lastSuccessAt === null
                                                      ? t('never')
                                                      : timestamp(
                                                            mail.lastSuccessAt,
                                                        ),
                                          })}
                                    {mail.consecutiveFailures > 0 &&
                                        `, ${t(':count failing', { count: mail.consecutiveFailures })}`}
                                </p>
                            </TooltipHint>
                            {mail.consecutiveFailures > 0 &&
                                mail.lastFailureDiagnosis !== null && (
                                    <p className="font-mono break-words">
                                        {mail.lastFailureDiagnosis}
                                    </p>
                                )}
                        </dd>
                    </div>
                    {health.lastLiveTerminationFailure !== null && (
                        <div className="space-y-1 border-b border-(--border-soft) py-3">
                            <dt className="text-cell font-medium">
                                {t('Last live delivery failure')}
                            </dt>
                            <dd className="text-xs text-muted-foreground">
                                <AbsoluteTime
                                    value={health.lastLiveTerminationFailure}
                                />
                            </dd>
                        </div>
                    )}
                </dl>
                <p className="text-xs leading-relaxed text-muted-foreground">
                    {t(
                        'The /up endpoint checks application readiness, not scheduler, queue or all-worker health.',
                    )}
                </p>
            </section>
            <DatabaseGrowth growth={health.databaseGrowth} />
        </PageContainer>
    );
}
