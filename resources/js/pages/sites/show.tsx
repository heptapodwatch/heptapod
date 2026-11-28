import {
    hasActionNote,
    type ActionNoteDescriptor,
} from '@/components/action-note-slot';
import { Head, router } from '@inertiajs/react';
import { toast } from 'sonner';
import { isCoalescedStateChange, useLiveReload } from '@/hooks/use-live';
import { useBufferedChanges } from '@/hooks/use-buffered-changes';
import { useRequestedRun } from '@/hooks/use-requested-run';
import { PendingChanges } from '@/components/pending-changes';
import { useEffect, useId, useState } from 'react';
import { SiteOverviewHeader } from '@/components/site-header';
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
import { CheckDetail } from '@/components/check-detail';
import { AlertChannelsLine } from '@/components/alert-channels-line';
import { SiteOverviewContent } from '@/components/site-overview-content';
import { SiteSectionsLayout } from '@/layouts/site-sections-layout';
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
                  'response_time',
                  'monthly_uptime',
                  'response_p95_hourly',
                  'performance_noise',
                  // A deletion request takes these actions away together with the badge.
                  'can_run',
                  'can_move',
                  'check_settings',
              ]
            : [
                  'site',
                  'header',
                  'client',
                  'maintenance',
                  'history',
                  'open_uptime_incident',
                  'uptime_ranges',
                  'response_ms',
                  'lighthouse',
                  'resume_at',
                  'repositories',
                  'can_manage',
                  'can_move',
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
    const listedChecks = site.checks.filter(
        (check) => check.enabled || (isCheck && check.id === page.check.id),
    );
    const canManage = isCheck ? page.can_run : page.can_manage;
    const openUptime = isCheck ? null : page.open_uptime_incident;
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
            variant="default"
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
    // A run in flight and the history's pending changes sit below the check header.
    const runStatus = isCheck ? (
        <>
            {page.check.enabled && activeRun && (
                <CheckRunStatusRow
                    run={activeRun}
                    action={
                        page.can_run && run?.can_cancel && !cancelPending ? (
                            <Button
                                variant="outline"
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
            {page.check.enabled && run?.phase === 'late' && run.due_at && (
                <p className="text-xs text-status-warn">
                    {t('Late by {{duration}}', {
                        duration: format.duration(
                            Math.max(
                                0,
                                (Date.now() - Date.parse(run.due_at)) / 1000,
                            ),
                        ),
                    })}
                </p>
            )}
            <PendingChanges
                count={bufferedRuns.pendingCount}
                apply={bufferedRuns.apply}
            />
        </>
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
            <SiteSectionsLayout
                site={site}
                client={client}
                checks={listedChecks}
                value={isCheck ? String(page.check.id) : 'overview'}
                canOpenSettings={canManage || page.can_move}
                maintenance={page.maintenance}
            >
                {isCheck ? (
                    <>
                        <CheckDetail
                            requestedRun={requestedRun}
                            primaryAction={runAction}
                            actionNote={runActionNote}
                            runStatus={runStatus}
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
                        <SiteOverviewHeader
                            site={site}
                            canManage={canManage}
                            runAllChecks={page.run_all_checks}
                            resumeAt={page.resume_at}
                            maintenance={page.maintenance}
                        />
                        <SiteRunningStrip
                            site={site}
                            runAllResult={page.run_all_result}
                        />
                        <SiteOverviewContent page={page} />
                    </>
                )}
            </SiteSectionsLayout>
        </>
    );
}
