import {
    hasActionNote,
    type ActionNoteDescriptor,
} from '@/components/action-note-slot';
import { Alert, AlertTitle } from '@/components/ui/alert';
import { PageContainer } from '@/components/page-container';
import { Head, router } from '@inertiajs/react';
import { toast } from 'sonner';
import { isCoalescedStateChange, useLiveReload } from '@/hooks/use-live';
import { useBufferedChanges } from '@/hooks/use-buffered-changes';
import { useRequestedRun } from '@/hooks/use-requested-run';
import { PendingChanges } from '@/components/pending-changes';
import { useEffect, useId, useState } from 'react';
import { SiteHeader } from '@/components/site-header';
import { SiteRunningStrip } from '@/components/site-running-strip';
import { Button } from '@/components/ui/button';
import { useTranslation } from 'react-i18next';
import { IncidentBanner } from '@/components/incident-banner';
import { CooldownReason, useCooldown } from '@/components/cooldown-reason';
import {
    CheckRunStatusRow,
    RunCheckReason,
    useCheckRun,
} from '@/components/check-run-status';
import { ProductCheckDetail } from '@/components/product-check-detail';
import { AlertChannelsLine } from '@/components/alert-channels-line';
import { SiteOverviewContent } from '@/components/site-overview-content';
import { MonitoringTime } from '@/components/monitoring';
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
    const run = isCheck ? page.schedule.run : null;
    const activeRun = useCheckRun(isCheck ? page.check.id : 0, run);
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
                  'phase_series',
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
                  'events_pagination',
                  'incidents',
                  'uptime_ranges',
                  'record',
                  'upcoming',
                  'lighthouse',
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
    const cooldownUntil = isCheck ? (page.cooldown_until ?? null) : null;
    const { active: inCooldown, remainingSeconds: cooldownSeconds } =
        useCooldown(cooldownUntil);
    const runNoteId = useId();
    const canShowRunAction =
        isCheck &&
        page.can_run &&
        (!page.check.enabled || header.state !== 'paused');
    const canProduceRunNote =
        isCheck &&
        canShowRunAction &&
        (!page.check.enabled || page.check.unavailable_reason === null);
    const runStandingNote =
        isCheck && page.check.enabled && activeRun ? (
            <RunCheckReason run={activeRun} />
        ) : isCheck && page.check.enabled && !activeRun && inCooldown ? (
            <CooldownReason
                until={cooldownUntil}
                remainingSeconds={cooldownSeconds}
            />
        ) : null;
    const runActionNote: ActionNoteDescriptor | undefined = canProduceRunNote
        ? {
              id: runNoteId,
              content: runStandingNote,
          }
        : undefined;
    const runAction = canShowRunAction ? (
        <Button
            variant={page.check.enabled ? 'outline' : 'default'}
            className="w-full md:w-auto"
            aria-describedby={
                hasActionNote(runActionNote) ? runNoteId : undefined
            }
            disabled={
                pending ||
                enablePending ||
                (page.check.enabled && activeRun !== null) ||
                (page.check.enabled && inCooldown) ||
                (page.check.enabled && page.check.unavailable_reason !== null)
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
                        onError: (errors) => {
                            requestedRun.abandon();
                            const messages = Object.entries(errors)
                                .filter(([key]) => key !== '_refusal')
                                .map(([, message]) => message);
                            // `_refusal` already flashed a toast from the server.
                            if (messages.length > 0) {
                                toast.error(messages.join(' · '));
                            }
                        },
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
                    canManage={canManage || page.can_move}
                />
                {isCheck && page.check.enabled && activeRun && (
                    <CheckRunStatusRow
                        run={activeRun}
                        action={
                            page.can_run &&
                            run?.can_cancel &&
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
                {isCheck &&
                    page.check.enabled &&
                    run?.phase === 'late' &&
                    run.due_at && (
                        <p className="text-xs text-[var(--status-warn)]">
                            {t('Late by {{duration}}', {
                                duration: format.duration(
                                    Math.max(
                                        0,
                                        (Date.now() - Date.parse(run.due_at)) /
                                            1000,
                                    ),
                                ),
                            })}
                        </p>
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
                            actionNote={runActionNote}
                            page={{ ...page, runs: bufferedRuns.rows }}
                        />
                        {page.check.enabled && (
                            <AlertChannelsLine
                                siteId={site.id}
                                checkId={page.check.id}
                                category={
                                    page.check.key === 'uptime'
                                        ? 'outage'
                                        : 'failure'
                                }
                                count={page.alert_channels}
                                canOpenSettings={page.can_move}
                            />
                        )}
                    </>
                ) : (
                    <>
                        <SiteRunningStrip
                            site={site}
                            runAllResult={page.run_all_result}
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
                    </>
                )}
            </PageContainer>
        </>
    );
}
