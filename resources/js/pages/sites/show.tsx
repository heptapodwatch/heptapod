import { AbsoluteTime } from '@/components/absolute-time';
import { Alert, AlertTitle } from '@/components/ui/alert';
import { PageContainer } from '@/components/page-container';
import { Head, router, usePage } from '@inertiajs/react';
import {
    isCoalescedStateChange,
    useLiveReload,
    useRunState,
} from '@/hooks/use-live';
import { useBufferedChanges } from '@/hooks/use-buffered-changes';
import { useRequestedRun } from '@/hooks/use-requested-run';
import { currentRunState } from '@/lib/live-store';
import { PendingChanges } from '@/components/pending-changes';
import { useEffect, useState } from 'react';
import InputError from '@/components/input-error';
import { SiteHeader } from '@/components/site-header';
import { SiteRunAllResult } from '@/components/site-run-all';
import { Button } from '@/components/ui/button';
import { useTranslation } from 'react-i18next';
import { IncidentBanner } from '@/components/incident-banner';
import { RunBar } from '@/components/foundation-primitives';
import { ProductCheckDetail } from '@/components/product-check-detail';
import { NotificationExplanation } from '@/components/notification-explanation';
import { SiteOverviewContent } from '@/components/site-overview-content';
import {
    ContentState,
    EventTimeline,
    MonitoringTime,
} from '@/components/monitoring';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { SiteTabs } from '@/components/site-tabs';
import { checkState, stateCountOrder } from '@/lib/status';
import { useFormat } from '@/hooks/use-format';
import { index as sitesIndex } from '@/routes/sites';
import {
    run as checkRun,
    cancel as cancelRun,
    enable as checkEnable,
} from '@/routes/sites/checks';
import { show as incidentShow } from '@/routes/incidents';

type SitePage =
    | Heptapod.Data.Ui.SiteOverviewPageData
    | Heptapod.Data.Ui.CheckTabPageData;

const noRuns: Heptapod.Data.Ui.CheckRunData[] = [];

export default function SiteShow(page: SitePage) {
    const { t } = useTranslation();
    const format = useFormat();
    const isCheck = 'check' in page;
    const [pending, setPending] = useState(false);
    const [cancelPending, setCancelPending] = useState(false);
    const [enablePending, setEnablePending] = useState(false);
    const live = useRunState(isCheck ? page.check.id : 0);
    const run = isCheck ? page.schedule.run : null;
    const liveRun = currentRunState(live, run);
    const runPhase =
        liveRun?.phase === 'queued'
            ? 'queued'
            : liveRun?.phase === 'started' || liveRun?.phase === 'progress'
              ? 'running'
              : liveRun?.phase === 'finished' &&
                  run &&
                  ['queued', 'running'].includes(run.phase)
                ? (liveRun.outcome ?? 'done')
                : run?.phase;
    const progressDone = liveRun?.progress_n ?? run?.progress_n;
    const progressTotal = liveRun?.progress_total ?? run?.progress_total;
    const progress =
        progressDone !== null &&
        progressDone !== undefined &&
        progressTotal &&
        progressTotal > 0
            ? Math.min(100, Math.max(0, (progressDone / progressTotal) * 100))
            : undefined;
    const { errors } = usePage().props;
    useLiveReload(
        ['state.changed'],
        isCheck
            ? [
                  'site',
                  'header',
                  'client',
                  'maintenance',
                  'check',
                  'schedule',
                  'runs',
                  'metrics',
                  'detail',
                  'history',
                  'response_times',
                  'response_p95_hourly',
                  'performance_noise',
              ]
            : [
                  'site',
                  'header',
                  'client',
                  'maintenance',
                  'history',
                  'events',
                  'incidents',
                  'uptime_ranges',
              ],
        (signal) =>
            signal.ids.site === page.header.id ||
            signal.ids.client === page.header.client_id ||
            isCoalescedStateChange(signal),
        true,
        {
            missingResource: {
                url: sitesIndex.url(),
                message: t('Site deleted'),
            },
        },
    );
    const requestedRun = useRequestedRun(
        page.site.id,
        isCheck ? page.check.id : null,
    );
    const finishedRun = requestedRun.finished;
    // The own run is applied once the live reload after its completion brought its row.
    const ownRun =
        finishedRun &&
        (finishedRun.checkRunId === null ||
            (isCheck &&
                page.runs.some((run) => run.id === finishedRun.checkRunId)))
            ? finishedRun
            : null;
    const bufferedRuns = useBufferedChanges(
        isCheck ? page.runs : noRuns,
        (run) => run.id,
        undefined,
        undefined,
        undefined,
        ownRun && {
            token: ownRun.attemptId,
            accepts: (run) => run.id === ownRun.checkRunId,
        },
    );
    useEffect(() => {
        if (ownRun) requestedRun.consume();
    }, [ownRun]);
    const { site, client, header } = page;
    const enabledChecks = site.checks.filter((check) => check.enabled);
    const tabChecks = site.checks.filter(
        (check) => check.enabled || (isCheck && check.id === page.check.id),
    );
    const attentionSummary = stateCountOrder
        .filter((state) => state !== 'ok')
        .flatMap((state) => {
            const count = enabledChecks.filter(
                (check) => checkState(check) === state,
            ).length;
            return count ? [t(`site.checks.${state}`, { count })] : [];
        })
        .join(', ');
    const incidentStartedAt = enabledChecks
        .flatMap((check) =>
            check.open_incident_started_at
                ? [check.open_incident_started_at]
                : [],
        )
        .sort()[0];
    const canManage = isCheck ? page.can_run : page.can_manage;
    const openUptime = isCheck
        ? undefined
        : page.incidents.find(
              (incident) =>
                  incident.resolved_at === null &&
                  incident.check === 'uptime' &&
                  site.checks.some(
                      (check) => check.open_incident_id === incident.id,
                  ),
          );
    const uptimeCheck = site.checks.find((check) => check.key === 'uptime');
    const runAction =
        isCheck &&
        page.can_run &&
        (!page.check.enabled || header.state !== 'paused') ? (
            <div className="space-y-1">
                <Button
                    variant={page.check.enabled ? 'outline' : 'default'}
                    className="w-full md:w-auto"
                    disabled={
                        pending ||
                        enablePending ||
                        (page.check.enabled &&
                            page.check.unavailable_reason !== null)
                    }
                    onClick={() => {
                        if (page.check.enabled) {
                            setPending(true);
                            requestedRun.request();
                        } else setEnablePending(true);
                        router.post(
                            (page.check.enabled ? checkRun : checkEnable).url({
                                site: site.id,
                                check: page.check.id,
                            }),
                            {},
                            {
                                preserveScroll: true,
                                onSuccess: (response) => {
                                    if (page.check.enabled) {
                                        requestedRun.accept(response);
                                    }
                                },
                                onError: requestedRun.abandon,
                                onHttpException: () => {
                                    requestedRun.abandon();
                                },
                                onFinish: () => {
                                    setPending(false);
                                    setEnablePending(false);
                                },
                            },
                        );
                    }}
                >
                    {t(
                        page.check.enabled
                            ? pending
                                ? 'Running check…'
                                : 'Run check'
                            : enablePending
                              ? 'Turning on…'
                              : 'Turn on',
                    )}
                </Button>
                {Object.values(errors).map((message, index) => (
                    <InputError key={index} message={message} />
                ))}
            </div>
        ) : null;
    return (
        <>
            <Head title={header.name} />
            {openUptime && (
                <IncidentBanner
                    siteName={header.name}
                    cause={openUptime.cause}
                    started_at={openUptime.started_at}
                    duration_seconds={openUptime.duration_seconds}
                    summary={
                        uptimeCheck?.summary
                            ? format.summary(
                                  uptimeCheck.summary,
                                  uptimeCheck.summary_params,
                              )
                            : null
                    }
                    onOpen={() => router.visit(incidentShow.url(openUptime.id))}
                />
            )}
            <PageContainer>
                <SiteHeader
                    showTrail
                    metaDetails={
                        <>
                            <span>
                                {attentionSummary ||
                                    t(
                                        enabledChecks.length
                                            ? 'All checks OK'
                                            : 'No checks enabled',
                                    )}
                            </span>
                            {incidentStartedAt && (
                                <span className="text-xs text-[var(--foreground-faint)]">
                                    {t('Since')}{' '}
                                    <MonitoringTime
                                        value={incidentStartedAt}
                                        mode="since"
                                        className="font-mono text-xs"
                                    />
                                </span>
                            )}
                        </>
                    }
                    site={site}
                    client={client}
                    canManage={canManage}
                    runAllChecks={page.run_all_checks}
                    maintenance={page.maintenance}
                />
                <SiteTabs
                    siteId={site.id}
                    checks={tabChecks}
                    value={isCheck ? String(page.check.id) : 'overview'}
                    canManage={canManage}
                />
                {isCheck &&
                    page.check.enabled &&
                    run &&
                    runPhase &&
                    ['queued', 'running', 'late'].includes(runPhase) && (
                        <RunBar
                            state={runPhase}
                            description={
                                runPhase === 'scheduled' ? (
                                    <>
                                        {page.schedule.next_due_at ? (
                                            <>
                                                {t('Next run')}{' '}
                                                <AbsoluteTime
                                                    value={
                                                        page.schedule
                                                            .next_due_at
                                                    }
                                                />
                                            </>
                                        ) : (
                                            t('No next run scheduled')
                                        )}
                                        {' · '}
                                        {format.intervalPhrase(
                                            page.schedule.interval_seconds,
                                        )}
                                        {page.schedule.last_finished_at && (
                                            <>
                                                {' · '}
                                                {t('Last run')}{' '}
                                                <MonitoringTime
                                                    value={
                                                        page.schedule
                                                            .last_finished_at
                                                    }
                                                    mode="since"
                                                />
                                                {page.schedule
                                                    .last_duration_ms !==
                                                    null &&
                                                    ` ${t('took')} ${format.number(page.schedule.last_duration_ms)} ${t('ms')}`}
                                            </>
                                        )}
                                    </>
                                ) : runPhase === 'queued' &&
                                  (liveRun?.queue_position ??
                                      run.queue_position) ? (
                                    t('Queue position {{count}}', {
                                        count:
                                            liveRun?.queue_position ??
                                            run.queue_position,
                                    })
                                ) : runPhase === 'late' && run.due_at ? (
                                    t('Late by {{duration}}', {
                                        duration: format.duration(
                                            Math.max(
                                                0,
                                                (Date.now() -
                                                    Date.parse(run.due_at)) /
                                                    1000,
                                            ),
                                        ),
                                    })
                                ) : (
                                    (run.phase_label ?? t('Check is running'))
                                )
                            }
                            progress={progress}
                            action={
                                page.can_run &&
                                run.can_cancel &&
                                runPhase !== 'late' &&
                                !cancelPending ? (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => {
                                            setCancelPending(true);
                                            router.post(
                                                cancelRun.url({
                                                    site: site.id,
                                                    check: page.check.id,
                                                }),
                                                {},
                                                {
                                                    preserveScroll: true,
                                                    onFinish: () =>
                                                        setCancelPending(false),
                                                },
                                            );
                                        }}
                                    >
                                        {t('Cancel')}
                                    </Button>
                                ) : undefined
                            }
                        />
                    )}
                {isCheck ? (
                    <>
                        <PendingChanges
                            count={bufferedRuns.pendingCount}
                            apply={bufferedRuns.apply}
                        />
                        <ProductCheckDetail
                            requestedRun={requestedRun}
                            primaryAction={runAction}
                            page={{ ...page, runs: bufferedRuns.rows }}
                        />
                        {page.check.enabled && (
                            <NotificationExplanation
                                checkId={page.check.id}
                                clientId={site.client_id}
                                defaultCategory={
                                    page.check.key === 'uptime'
                                        ? 'outage'
                                        : 'failure'
                                }
                            />
                        )}
                    </>
                ) : (
                    <>
                        <SiteRunAllResult
                            site={site}
                            result={page.run_all_result}
                        />
                        {site.checks.some(
                            (check) =>
                                check.not_fresh &&
                                !check.freshness_suppressed_by,
                        ) && (
                            <Alert tone="warning">
                                <AlertTitle>
                                    {t('freshness.site', {
                                        count: site.checks.filter(
                                            (check) =>
                                                check.not_fresh &&
                                                !check.freshness_suppressed_by,
                                        ).length,
                                    })}
                                </AlertTitle>
                            </Alert>
                        )}
                        <SiteOverviewContent page={page} />
                        {uptimeCheck?.enabled && (
                            <NotificationExplanation
                                checkId={uptimeCheck.id}
                                clientId={site.client_id}
                            />
                        )}
                        <div className="min-w-0">
                            <Card>
                                <CardHeader>
                                    <CardTitle>{t('Recent events')}</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    {page.events.length ? (
                                        <EventTimeline events={page.events} />
                                    ) : (
                                        <ContentState
                                            bare
                                            state="empty"
                                            title="No events yet"
                                            description="Events appear here when a state changes, not after every check run."
                                        />
                                    )}
                                </CardContent>
                            </Card>
                        </div>
                    </>
                )}
            </PageContainer>
        </>
    );
}
