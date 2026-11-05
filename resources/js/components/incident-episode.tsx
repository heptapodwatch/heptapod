import { TooltipHint, TruncatedText } from '@/components/truncated-text';
import InputError, { fieldErrorProps } from '@/components/input-error';
import { AbsoluteTime } from '@/components/absolute-time';
import { PresentationChanges } from '@/components/presentation-changes';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { Fragment, useEffect, useState, type ReactNode } from 'react';
import {
    ArrowRight,
    ExternalLink,
    Mail,
    MessageSquareText,
    Repeat,
    Shuffle,
} from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { CheckEvidence } from '@/components/check-evidence';
import { ApplicationHealthDetail } from '@/components/application-health-detail';
import { CronTasksDetail } from '@/components/cron-tasks-detail';
import { DnsComparison } from '@/components/dns-comparison';
import { EvidenceFacts } from '@/components/evidence-facts';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import {
    IncidentDeliveryRow,
    IncidentNotificationGroup,
} from '@/components/incident-notification-group';
import { StatusBadge, StatusDot } from '@/components/monitoring';
import { NotificationExplanation } from '@/components/notification-explanation';
import { listCardPadding } from '@/components/ui/table';
import { PageHeader } from '@/components/page-header';
import { SectionHeading } from '@/components/section-heading';
import { PageHeaderAction } from '@/components/page-header-actions';
import { PendingChanges } from '@/components/pending-changes';
import { RunBar } from '@/components/foundation-primitives';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    CardAction,
} from '@/components/ui/card';
import { Textarea } from '@/components/ui/textarea';
import {
    DropdownMenu,
    DropdownMenuTrigger,
    DropdownMenuContent,
    DropdownMenuItem,
} from '@/components/ui/dropdown-menu';
import { useBufferedChanges } from '@/hooks/use-buffered-changes';
import { useFormat } from '@/hooks/use-format';
import {
    type OwnRunResult,
    type RequestedRun,
} from '@/hooks/use-requested-run';
import { useRunState } from '@/hooks/use-live';
import { currentRunState } from '@/lib/live-store';
import { checkTypes } from '@/lib/check-types';
import { incidentCauseState, runState, statusLabels } from '@/lib/status';
import { findingsTimestamp } from '@/lib/findings-export';
import { findings as findingsCsv } from '@/routes/sites/checks';
import {
    note as saveNote,
    show as incidentShow,
    unsnooze,
    snooze,
} from '@/routes/incidents';
import { show as checkShow, run as checkRun } from '@/routes/sites/checks';
import { show as siteShow } from '@/routes/sites';
import { show as clientShow } from '@/routes/clients';
import { index as maintenanceIndex } from '@/routes/maintenance';
import {
    EntityLink,
    openRowTarget,
    rowTargetClass,
} from '@/components/entity-link';
import { cn } from '@/lib/utils';
import { TextLink } from '@/components/text-link';

export function IncidentEpisode({
    page,
    headline,
    requestedRun,
    ownRun,
    onPreview,
    clientAction,
}: {
    page: Heptapod.Data.Ui.IncidentDetailPageData;
    headline: string;
    requestedRun: RequestedRun;
    ownRun: OwnRunResult | null;
    onPreview: (eventId: number) => void;
    /** "Send to client…": the last secondary header action, for those who may message the client. */
    clientAction?: ReactNode;
}) {
    const { t } = useTranslation();
    const format = useFormat();
    const {
        props: { errors, timezone },
    } = usePage();
    const form = useForm({ note: page.internal_note ?? '' });
    // The incident and its current snapshot are single records: after the own run they show the refetched state.
    const ownRunState = ownRun && {
        token: ownRun.attemptId,
        accepts: () => true,
    };
    const incidentState = useBufferedChanges(
        [page.incident],
        (row) => row.id,
        page.incident.id,
        (row) => JSON.stringify({ ...row, duration_seconds: 0 }),
        'incident',
        ownRunState,
    );
    const incident = incidentState.rows[0];
    if (!incident) throw new Error('Incident state missing.');
    // One page, two states: a resolved incident keeps the structure but shows only episode-scoped evidence.
    const resolved = incident.resolved_at !== null;
    const check = page.site.checks.find((item) => item.key === incident.check);
    const canShowRun =
        !resolved &&
        page.can_run &&
        check !== undefined &&
        check.enabled &&
        check.unavailable_reason === null &&
        page.header.state !== 'paused';
    const liveRun = currentRunState(useRunState(check?.id ?? 0), check?.run);
    const runPhase =
        liveRun?.phase === 'queued'
            ? 'queued'
            : liveRun &&
                (liveRun.phase === 'started' || liveRun.phase === 'progress')
              ? 'running'
              : null;
    const [runPending, setRunPending] = useState(false);
    const [now, setNow] = useState(Date.now());
    const [actionsOpen, setActionsOpen] = useState(false);
    const [snoozeOpen, setSnoozeOpen] = useState(false);
    useEffect(() => {
        const openFromHash = () => {
            if (
                window.location.hash === '#snooze' &&
                page.can_snooze &&
                (!incident.snoozed_until ||
                    Date.parse(incident.snoozed_until) <= Date.now())
            ) {
                setActionsOpen(true);
                setSnoozeOpen(true);
            }
        };
        openFromHash();
        window.addEventListener('hashchange', openFromHash);
        return () => window.removeEventListener('hashchange', openFromHash);
    }, [incident.id, incident.snoozed_until, page.can_snooze]);
    useEffect(() => {
        const timer = window.setInterval(() => setNow(Date.now()), 1000);
        return () => window.clearInterval(timer);
    }, []);
    const timeline = useBufferedChanges(
        page.timeline,
        (entry) => entry.id,
        incident.id,
        (entry) => JSON.stringify(entry),
        'timeline',
        ownRun && {
            token: ownRun.attemptId,
            accepts: (entry) =>
                ownRun.checkRunId !== null &&
                entry.event?.run_id === ownRun.checkRunId,
        },
    );
    const snapshot = useBufferedChanges(
        [
            {
                id: incident.id,
                headline,
                cause: page.incident.cause,
                current_evidence: page.current_evidence,
                current_findings: page.current_findings,
                current_presentation: page.current_presentation,
                opening_delivery_explanation: page.opening_delivery_explanation,
                latest_run: page.runs[0],
                current_checked_at: check?.checked_at ?? null,
                related_incidents: page.related_incidents.map((related) => ({
                    id: related.id,
                    site_name: related.site_name,
                    check: related.check,
                    cause: related.cause,
                })),
                held_after_recovery: page.incident.held_after_recovery,
                next_run_at: page.incident.next_run_at,
                uptime_parent_incident_id:
                    page.incident.uptime_parent_incident_id,
                findings_unverified:
                    check?.open_incident_requires_complete_recovery &&
                    (check.result === 'errored-or-timed-out' ||
                        check.summary?.startsWith('crawl.not_crawlable.')),
            },
        ],
        (entry) => entry.id,
        incident.id,
        (entry) => JSON.stringify(entry),
        undefined,
        ownRunState,
    );
    const shown = snapshot.rows[0];
    if (!shown) throw new Error('Incident episode snapshot missing.');
    const otherDeliveries = useBufferedChanges(
        page.other_deliveries,
        (delivery) => delivery.id,
        incident.id,
        (delivery) => JSON.stringify(delivery),
        'other_deliveries',
        ownRun && {
            token: ownRun.attemptId,
            accepts: () => true,
        },
    );
    const duration = resolved
        ? incident.duration_seconds
        : Math.max(
              0,
              Math.floor(
                  (now - new Date(incident.started_at).getTime()) / 1000,
              ),
          );
    // A disabled check or a paused/deleted site closes an incident without a recovery (docs/ui.md).
    const closed =
        resolved &&
        (page.evidence?.recovery_summary?.startsWith('incidents.resolution.') ??
            false);
    const opening = page.opening_presentation
        ? format.presentation(page.opening_presentation)
        : page.evidence?.summary
          ? format.summary(page.evidence.summary, page.evidence.summary_params)
          : null;
    const recipients = Array.from(
        new Set(
            timeline.rows.flatMap(
                (entry) => entry.notification_group?.recipients ?? [],
            ),
        ),
    );
    const day = (time: string) => format.date(time, { dateStyle: 'full' });
    // CardAction holds buttons only (heptapod-ui-rules Cards): Open check is a ghost button beside Copy / Download CSV.
    const openCheck = check && (
        <Button asChild size="sm" variant="ghost">
            <Link
                href={checkShow.url({ site: page.site.id, check: check.id })}
                prefetch
            >
                {t('Open check')}
                <ArrowRight aria-hidden="true" />
            </Link>
        </Button>
    );
    return (
        <>
            <PageHeader
                title={shown.headline}
                description={t('pages.incident.description')}
                titleTooltip={
                    shown.held_after_recovery && shown.next_run_at
                        ? format.exact(shown.next_run_at)
                        : undefined
                }
                titleAction={
                    <Button variant="ghost" size="icon-xs" asChild>
                        <a
                            href={page.site.url}
                            target="_blank"
                            rel="noopener noreferrer"
                            aria-label={t('Open site')}
                        >
                            <ExternalLink aria-hidden="true" />
                        </a>
                    </Button>
                }
                trail={
                    <>
                        <TextLink
                            variant="secondary"
                            href={clientShow.url(page.client.id)}
                        >
                            {page.client.name}
                        </TextLink>
                        <span aria-hidden="true">/</span>
                        <TextLink
                            variant="secondary"
                            href={siteShow.url(page.site.id)}
                        >
                            {page.header.name}
                        </TextLink>
                    </>
                }
                meta={
                    resolved ? (
                        <>
                            <StatusBadge
                                state="ok"
                                label={closed ? 'Closed' : 'Resolved'}
                            />
                            <EpisodeMetaFacts
                                facts={[
                                    <>
                                        {t(
                                            closed
                                                ? 'Closed after'
                                                : 'Resolved after',
                                        )}{' '}
                                        <span className="font-mono tabular-nums">
                                            {format.duration(duration)}
                                        </span>
                                    </>,
                                    <>
                                        <AbsoluteTime
                                            value={incident.started_at}
                                        />{' '}
                                        →{' '}
                                        <AbsoluteTime
                                            value={incident.resolved_at ?? ''}
                                        />
                                    </>,
                                    <span className="font-mono tabular-nums">
                                        #{format.number(incident.id)}
                                    </span>,
                                ]}
                            />
                        </>
                    ) : (
                        <>
                            <StatusBadge
                                state={incidentCauseState({
                                    check: incident.check,
                                    cause: shown.cause,
                                })}
                            />
                            {shown.current_presentation && (
                                <PresentationChanges
                                    changes={shown.current_presentation.changes}
                                    details={shown.current_presentation.details}
                                />
                            )}
                            <EpisodeMetaFacts
                                facts={[
                                    <>
                                        {t('incidents.state.open')}{' '}
                                        <span
                                            className="font-mono tabular-nums"
                                            data-incident-duration
                                        >
                                            {format.duration(duration)}
                                        </span>
                                    </>,
                                    <>
                                        {t('Since').toLocaleLowerCase()}{' '}
                                        <AbsoluteTime
                                            value={incident.started_at}
                                        />
                                    </>,
                                    <span className="font-mono tabular-nums">
                                        #{format.number(incident.id)}
                                    </span>,
                                ]}
                            />
                        </>
                    )
                }
                notices={
                    !resolved &&
                    page.maintenance?.state === 'active' && (
                        <Alert
                            tone="info"
                            action={
                                <TextLink
                                    href={maintenanceIndex.url({
                                        query: { site_id: page.site.id },
                                    })}
                                >
                                    {t('Show maintenance windows')}
                                </TextLink>
                            }
                        >
                            <AlertTitle>{t('Maintenance active')}</AlertTitle>
                            <AlertDescription>
                                <p>
                                    {page.maintenance.endsAt && (
                                        <>
                                            {t('Ends')}{' '}
                                            <AbsoluteTime
                                                value={page.maintenance.endsAt}
                                            />
                                            {' · '}
                                        </>
                                    )}
                                    {t(
                                        page.maintenance.muteAlerts
                                            ? 'Active maintenance mutes alerts.'
                                            : 'Active maintenance does not mute alerts.',
                                    )}
                                </p>
                            </AlertDescription>
                        </Alert>
                    )
                }
                overflowSecondary
                secondaryOpen={actionsOpen}
                onSecondaryOpenChange={setActionsOpen}
                secondaryActionCount={
                    Number(page.can_snooze || page.can_unsnooze) +
                    Number(clientAction !== undefined)
                }
                primaryAction={
                    canShowRun ? (
                        <Button
                            disabled={
                                runPending ||
                                runPhase !== null ||
                                check.unavailable_reason !== null
                            }
                            onClick={() => {
                                setRunPending(true);
                                requestedRun.request();
                                router.post(
                                    checkRun.url({
                                        site: page.site.id,
                                        check: check.id,
                                    }),
                                    { incident: incident.id },
                                    {
                                        preserveScroll: true,
                                        onSuccess: requestedRun.accept,
                                        onError: requestedRun.abandon,
                                        onHttpException: () => {
                                            requestedRun.abandon();
                                        },
                                        onFinish: () => setRunPending(false),
                                    },
                                );
                            }}
                        >
                            {t(runPending ? 'Running check…' : 'Run check')}
                        </Button>
                    ) : undefined
                }
                secondaryActions={
                    <>
                        {page.can_snooze &&
                            (!incident.snoozed_until ||
                                new Date(incident.snoozed_until).getTime() <=
                                    now) && (
                                <DropdownMenu
                                    open={snoozeOpen}
                                    onOpenChange={setSnoozeOpen}
                                    modal={false}
                                >
                                    <DropdownMenuTrigger asChild>
                                        <PageHeaderAction variant="outline">
                                            {t('Snooze reminders')}
                                        </PageHeaderAction>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end">
                                        {(
                                            [
                                                ['1 hour', 60],
                                                ['1 day', 1440],
                                                ['7 days', 10080],
                                            ] as const
                                        ).map(([label, minutes]) => (
                                            <DropdownMenuItem
                                                key={minutes}
                                                onSelect={() =>
                                                    router.post(
                                                        snooze.url(incident.id),
                                                        { minutes },
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                {t(label)}
                                            </DropdownMenuItem>
                                        ))}
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            )}
                        {page.can_unsnooze &&
                            incident.snoozed_until &&
                            new Date(incident.snoozed_until).getTime() >
                                now && (
                                <PageHeaderAction
                                    variant="outline"
                                    onClick={() =>
                                        router.delete(
                                            unsnooze.url(incident.id),
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    {t('Resume reminders')}
                                </PageHeaderAction>
                            )}
                        {clientAction}
                        <InputError message={errors.check} />
                    </>
                }
            />
            {shown.uptime_parent_incident_id !== null && (
                <TextLink
                    className="text-sm"
                    href={incidentShow.url(shown.uptime_parent_incident_id)}
                >
                    {t('View the uptime incident')}
                </TextLink>
            )}
            {incident.affected_checks.length > 0 && (
                <p className="text-sm text-muted-foreground">
                    {t(
                        resolved
                            ? 'notifications.rechecking'
                            : 'notifications.also_affected',
                        {
                            checks: incident.affected_checks
                                .map((key) => t(`checks.${key}.label`))
                                .join(', '),
                        },
                    )}
                </p>
            )}
            <PendingChanges
                count={Math.max(
                    timeline.pendingCount,
                    snapshot.pendingCount,
                    incidentState.pendingCount,
                )}
                apply={() => {
                    timeline.apply();
                    snapshot.apply();
                    incidentState.apply();
                }}
            />
            <div className="grid min-w-0 gap-5 lg:grid-cols-[minmax(0,1fr)_21.25rem] lg:items-start">
                <div className="min-w-0 space-y-5">
                    {!resolved && shown.findings_unverified && (
                        <p className="text-sm text-muted-foreground">
                            {t('crawl.previous_findings_unverified')}
                        </p>
                    )}
                    {resolved ? (
                        <EpisodeEvidence
                            page={page}
                            resolved
                            closed={closed}
                            action={openCheck}
                        />
                    ) : shown.current_findings?.check_type === 'cron' &&
                      check ? (
                        <CronTasksDetail
                            page={{
                                can_run: page.can_run,
                                site: page.site,
                                check,
                            }}
                            history={shown.current_findings}
                        />
                    ) : shown.current_findings?.check_type === 'dns' &&
                      check ? (
                        <DnsComparison
                            page={{
                                can_run: page.can_run,
                                site: page.site,
                                check,
                                schedule: { run: check.run },
                            }}
                            history={shown.current_findings}
                            requestedRun={requestedRun}
                        />
                    ) : shown.current_findings?.check_type ===
                      'application_health' ? (
                        <ApplicationHealthDetail
                            history={shown.current_findings}
                        />
                    ) : incident.check === 'performance' ? (
                        // The response-time chart needs the check page's metric series: link to it.
                        <EpisodeEvidence
                            page={page}
                            resolved={false}
                            closed={false}
                            action={openCheck}
                        />
                    ) : (
                        <CheckEvidence
                            type={incident.check}
                            detail={shown.current_evidence}
                            history={shown.current_findings}
                            presentation={shown.current_presentation}
                            latest={shown.latest_run}
                            since={incident.started_at}
                            origin={new URL(page.site.identity.url).origin}
                            requestedHost={
                                new URL(page.site.identity.url).hostname
                            }
                            action={openCheck}
                            exportContext={
                                check &&
                                [
                                    'broken_links',
                                    'mixed_content',
                                    'sitemap',
                                ].includes(incident.check)
                                    ? {
                                          site: `${page.header.name} (${page.header.url})`,
                                          check: t(
                                              checkTypes[incident.check].label,
                                          ),
                                          headline: shown.current_presentation
                                              ? format.presentation(
                                                    shown.current_presentation,
                                                )
                                              : shown.latest_run?.summary
                                                ? format.summary(
                                                      shown.latest_run.summary,
                                                      shown.latest_run
                                                          .summary_params,
                                                  )
                                                : shown.headline,
                                          checkedRunId:
                                              shown.latest_run?.id ?? null,
                                          checkedAt: shown.current_checked_at
                                              ? findingsTimestamp(
                                                    shown.current_checked_at,
                                                    timezone,
                                                )
                                              : null,
                                          incidentId: incident.id,
                                          downloadUrl: (run, observedAt) =>
                                              findingsCsv.url(
                                                  {
                                                      site: page.header.id,
                                                      check: check.id,
                                                  },
                                                  {
                                                      query: {
                                                          run,
                                                          observed_at:
                                                              observedAt,
                                                          incident: incident.id,
                                                      },
                                                  },
                                              ),
                                      }
                                    : undefined
                            }
                        />
                    )}
                    {!resolved && page.opened_during_maintenance && (
                        <p className="text-sm text-muted-foreground">
                            {t('Opened during maintenance (alerts muted)')}
                        </p>
                    )}
                    <Card>
                        <CardHeader>
                            <CardTitle>{t('Timeline')}</CardTitle>
                            <CardAction>
                                <span className="text-xs text-muted-foreground">
                                    <AbsoluteTime
                                        value={incident.started_at}
                                        mode="calendarDate"
                                    />{' '}
                                    →{' '}
                                    {incident.resolved_at ? (
                                        <AbsoluteTime
                                            value={incident.resolved_at}
                                            mode="calendarDate"
                                        />
                                    ) : (
                                        t('Now')
                                    )}
                                </span>
                            </CardAction>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {shown.held_after_recovery && shown.next_run_at && (
                                <TooltipHint
                                    content={format.exact(shown.next_run_at)}
                                >
                                    <p
                                        role="status"
                                        className="text-sm text-muted-foreground"
                                    >
                                        {t(
                                            'Re-checking after recovery · next run :time',
                                            {
                                                time: format.timestamp(
                                                    shown.next_run_at,
                                                ),
                                            },
                                        )}
                                    </p>
                                </TooltipHint>
                            )}
                            {incident.snoozed_until &&
                                new Date(incident.snoozed_until).getTime() >
                                    now && (
                                    <p
                                        role="status"
                                        className="text-sm text-muted-foreground"
                                    >
                                        {t('Reminders snoozed until')} ·{' '}
                                        <AbsoluteTime
                                            value={incident.snoozed_until}
                                        />
                                    </p>
                                )}
                            <ol>
                                {timeline.rows.map((entry, index) => {
                                    const previous = timeline.rows[index - 1];
                                    const Icon = entry.notification_group
                                        ? entry.notification_group
                                              .event_type ===
                                          'incident.still_down'
                                            ? Repeat
                                            : Mail
                                        : entry.note !== null
                                          ? MessageSquareText
                                          : Shuffle;
                                    return (
                                        <li key={entry.id}>
                                            {(!previous ||
                                                day(previous.time) !==
                                                    day(entry.time)) && (
                                                <h3 className="pt-2 pb-3 text-xs font-medium text-muted-foreground">
                                                    {day(entry.time)}
                                                </h3>
                                            )}
                                            <div className="grid grid-cols-[4rem_1.25rem_minmax(0,1fr)] gap-x-3 pb-5">
                                                <AbsoluteTime
                                                    value={entry.time}
                                                    mode="eventTime"
                                                    className="pt-0.5 text-right font-mono text-xs text-muted-foreground"
                                                />
                                                <div className="relative flex justify-center border-l">
                                                    <span className="absolute -left-2.5 flex size-5 items-center justify-center bg-card">
                                                        {entry.event?.type ===
                                                        'incident.opened' ? (
                                                            <StatusDot
                                                                state={incidentCauseState(
                                                                    {
                                                                        check: incident.check,
                                                                        cause:
                                                                            entry
                                                                                .event
                                                                                .severity ===
                                                                            'error'
                                                                                ? 'errored'
                                                                                : 'failed',
                                                                    },
                                                                )}
                                                                label={t(
                                                                    'incident.opened',
                                                                )}
                                                            />
                                                        ) : entry.event
                                                              ?.type ===
                                                          'incident.resolved' ? (
                                                            <StatusDot
                                                                state="ok"
                                                                label={t(
                                                                    'incident.resolved',
                                                                )}
                                                            />
                                                        ) : (
                                                            <Icon
                                                                className="size-3.5 text-muted-foreground"
                                                                aria-hidden="true"
                                                            />
                                                        )}
                                                    </span>
                                                </div>
                                                {entry.notification_group ? (
                                                    <IncidentNotificationGroup
                                                        group={
                                                            entry.notification_group
                                                        }
                                                        incidentId={incident.id}
                                                        expanded={
                                                            page.expanded_notifications
                                                        }
                                                        previewAvailable={
                                                            page.alert_preview_available
                                                        }
                                                        onPreview={onPreview}
                                                    />
                                                ) : entry.event ? (
                                                    <div className="space-y-1 text-sm">
                                                        <p className="font-medium">
                                                            {t(
                                                                entry.event
                                                                    .type,
                                                            )}
                                                        </p>
                                                        {entry.event.type ===
                                                            'incident.opened' &&
                                                        opening ? (
                                                            <p className="text-xs text-muted-foreground">
                                                                {t(
                                                                    'Observed at opening',
                                                                )}
                                                                : {opening}
                                                            </p>
                                                        ) : (
                                                            <p className="text-xs text-muted-foreground">
                                                                {
                                                                    entry.event
                                                                        .message
                                                                }
                                                            </p>
                                                        )}
                                                    </div>
                                                ) : entry.note !== null ? (
                                                    <div className="space-y-1 text-sm">
                                                        <p className="font-medium">
                                                            {t(
                                                                'Note by {{name}}',
                                                                {
                                                                    name:
                                                                        entry.note_author ??
                                                                        t(
                                                                            'Team',
                                                                        ),
                                                                },
                                                            )}
                                                        </p>
                                                        <p className="border-l-2 pl-3 text-xs break-words whitespace-pre-wrap text-muted-foreground">
                                                            {entry.note}
                                                        </p>
                                                    </div>
                                                ) : null}
                                            </div>
                                        </li>
                                    );
                                })}
                            </ol>
                            {!resolved && liveRun && runPhase && (
                                <RunBar
                                    state={runPhase}
                                    description={
                                        <>
                                            {t('Re-checking now')}
                                            {liveRun.actor &&
                                                ` · ${liveRun.actor}`}
                                        </>
                                    }
                                    progress={
                                        liveRun.progress_total !== null &&
                                        liveRun.progress_total > 0 &&
                                        liveRun.progress_n !== null
                                            ? (100 * liveRun.progress_n) /
                                              liveRun.progress_total
                                            : undefined
                                    }
                                />
                            )}
                            {!resolved && (
                                <div className="flex items-center gap-3 border-t pt-3 text-xs text-muted-foreground">
                                    <AbsoluteTime
                                        value={new Date(now).toISOString()}
                                        mode="time"
                                        className="font-mono"
                                    />
                                    <StatusDot
                                        state="pending"
                                        label={t('Now')}
                                    />
                                    <span>
                                        {t('Now')} · {t('incidents.state.open')}{' '}
                                        {format.duration(duration)}
                                    </span>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                    {!resolved && check && (
                        <NotificationExplanation
                            checkId={check.id}
                            clientId={page.site.client_id}
                            defaultCategory={
                                incident.check === 'uptime'
                                    ? 'outage'
                                    : incident.cause === 'errored'
                                      ? 'check_error'
                                      : 'failure'
                            }
                        />
                    )}
                </div>
                <aside className="min-w-0 space-y-5">
                    <Card>
                        <CardHeader>
                            <CardTitle>{t('Alert delivery')}</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {shown.opening_delivery_explanation && (
                                <p className="mb-4 text-xs text-muted-foreground">
                                    {t(shown.opening_delivery_explanation)}
                                </p>
                            )}
                            <EvidenceFacts
                                stacked
                                rows={[
                                    ...(!resolved && incident.check === 'uptime'
                                        ? [
                                              {
                                                  label: t('Reminders'),
                                                  value: t(
                                                      'Only uptime outages receive reminders: after 1 hour, then 4 hours later, then every 24 hours.',
                                                  ),
                                              },
                                          ]
                                        : []),
                                    {
                                        label: t('Recipients'),
                                        mono: recipients.length > 0,
                                        value: recipients.length ? (
                                            <div className="space-y-1">
                                                {recipients.map((address) => (
                                                    <p key={address}>
                                                        {address}
                                                    </p>
                                                ))}
                                            </div>
                                        ) : (
                                            t('No email recipients recorded')
                                        ),
                                    },
                                ]}
                            />
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>{t('Internal note')}</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <form
                                noValidate
                                className="space-y-3"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    form.put(saveNote.url(incident.id), {
                                        preserveScroll: true,
                                    });
                                }}
                            >
                                <Textarea
                                    id="incident-note"
                                    aria-label={t('Internal note')}
                                    {...fieldErrorProps(
                                        'incident-note',
                                        form.errors.note,
                                    )}
                                    value={form.data.note}
                                    maxLength={10000}
                                    onChange={(event) =>
                                        form.setData('note', event.target.value)
                                    }
                                    placeholder={t(
                                        'What happened, what did you try?',
                                    )}
                                />
                                <InputError
                                    field="incident-note"
                                    message={form.errors.note}
                                />
                                {page.internal_note !== null &&
                                    page.note_updated_at && (
                                        <p className="text-xs text-muted-foreground">
                                            {t('Note by {{name}}', {
                                                name:
                                                    page.note_author ??
                                                    t('Team'),
                                            })}{' '}
                                            ·{' '}
                                            <AbsoluteTime
                                                value={page.note_updated_at}
                                            />
                                        </p>
                                    )}
                                <p className="text-xs text-muted-foreground">
                                    {t('Team only. Never sent to the client.')}
                                </p>
                                <div className="flex justify-end">
                                    <Button
                                        type="submit"
                                        size="sm"
                                        disabled={form.processing}
                                    >
                                        {t('Save note')}
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                    <PendingChanges
                        count={otherDeliveries.pendingCount}
                        apply={otherDeliveries.apply}
                    />
                    {otherDeliveries.rows.length > 0 && (
                        <OtherNotifications
                            deliveries={otherDeliveries.rows}
                            previewAvailable={page.alert_preview_available}
                            onPreview={onPreview}
                        />
                    )}
                    <Card>
                        <CardHeader>
                            <CardTitle>{t('Other open incidents')}</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {shown.related_incidents.length ? (
                                shown.related_incidents.map((related) => (
                                    <div
                                        key={related.id}
                                        className={cn(
                                            'flex items-start gap-3 text-sm',
                                            rowTargetClass,
                                        )}
                                        onClick={openRowTarget(
                                            incidentShow.url(related.id),
                                        )}
                                    >
                                        <StatusDot
                                            state={incidentCauseState(related)}
                                        />
                                        <span>
                                            <EntityLink
                                                name={related.site_name}
                                                href={incidentShow.url(
                                                    related.id,
                                                )}
                                                className="flex"
                                            />
                                            <span className="block text-xs text-muted-foreground">
                                                {t(
                                                    checkTypes[related.check]
                                                        .label,
                                                )}{' '}
                                                · #{format.number(related.id)}
                                            </span>
                                        </span>
                                    </div>
                                ))
                            ) : (
                                <p className="text-xs text-muted-foreground">
                                    {t(
                                        'No other open incidents for this client.',
                                    )}
                                </p>
                            )}
                        </CardContent>
                    </Card>
                </aside>
            </div>
        </>
    );
}

/**
 * Episode-scoped evidence built only from the incident's retained facts: the opening snapshot, the recovery or
 * administrative close, and the check results between first failure and close. Never the check's current state.
 */
/**
 * The episode meta facts on one text line: " · " only between facts. Each separator stays with the fact before it, so a
 * wrapped line never starts with a separator.
 */
function EpisodeMetaFacts({ facts }: { facts: ReactNode[] }) {
    return (
        <span>
            {facts.map((fact, index) => (
                <Fragment key={index}>
                    <span className="whitespace-nowrap">
                        {fact}
                        {index < facts.length - 1 && (
                            <span aria-hidden="true">{'\u00a0·'}</span>
                        )}
                    </span>{' '}
                </Fragment>
            ))}
        </span>
    );
}

function EpisodeEvidence({
    page,
    resolved,
    closed,
    action,
}: {
    page: Heptapod.Data.Ui.IncidentDetailPageData;
    resolved: boolean;
    closed: boolean;
    action: ReactNode;
}) {
    const { t } = useTranslation();
    const format = useFormat();
    const { evidence } = page;
    const opening = page.opening_presentation
        ? format.presentation(page.opening_presentation)
        : evidence?.summary
          ? format.summary(evidence.summary, evidence.summary_params)
          : null;
    const goodResults = evidence?.retained_good_results_since_recovery ?? null;
    const rows = [
        ...(opening
            ? [{ label: t('Observed at opening'), value: opening }]
            : []),
        ...(evidence?.opened_at
            ? [
                  {
                      label: t('Opening run'),
                      mono: true,
                      value: <AbsoluteTime value={evidence.opened_at} />,
                  },
              ]
            : []),
        ...(resolved && evidence?.recovered_at
            ? [
                  {
                      label: t(closed ? 'Closed at' : 'First good result'),
                      mono: true,
                      value: <AbsoluteTime value={evidence.recovered_at} />,
                  },
              ]
            : []),
        ...(resolved && evidence?.recovery_summary
            ? [
                  {
                      label: t(closed ? 'Resolution reason' : 'Observed'),
                      value: format.summary(
                          evidence.recovery_summary,
                          evidence.recovery_summary_params,
                      ),
                  },
              ]
            : []),
        ...(resolved && !closed && goodResults !== null
            ? [
                  {
                      label: t('Retained good results'),
                      mono: true,
                      value: `${format.number(goodResults)}${evidence?.good_results_capped ? '+' : ''}`,
                  },
              ]
            : []),
        ...(resolved && page.opened_during_maintenance
            ? [
                  {
                      label: t('Maintenance'),
                      value: t('Opened during maintenance (alerts muted)'),
                  },
              ]
            : []),
    ];
    return (
        <Card className="overflow-hidden">
            <CardHeader>
                <CardTitle>
                    {t(resolved ? 'Evidence at close' : 'What we observe')}
                </CardTitle>
                <CardAction>{action}</CardAction>
            </CardHeader>
            <CardContent className="space-y-4">
                {rows.length > 0 ? (
                    <EvidenceFacts rows={rows} />
                ) : (
                    <p className="text-sm text-muted-foreground">
                        {t('No evidence retained for this incident.')}
                    </p>
                )}
                {page.runs.length > 0 && (
                    <div className="space-y-2">
                        <SectionHeading level={3}>
                            {t('Results during the incident')}
                        </SectionHeading>
                        <ul className="divide-y rounded-lg border">
                            {page.runs.map((run) => {
                                const text = run.presentation
                                    ? format.presentation(run.presentation)
                                    : run.summary
                                      ? format.summary(
                                            run.summary,
                                            run.summary_params,
                                        )
                                      : t('No result');
                                return (
                                    <li
                                        key={run.id}
                                        className={cn(
                                            'flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1 text-xs',
                                            listCardPadding,
                                        )}
                                    >
                                        <AbsoluteTime
                                            value={run.ended_at}
                                            className="text-muted-foreground"
                                        />
                                        <StatusDot
                                            state={runState(
                                                page.incident.check,
                                                run.result,
                                            )}
                                            label={t(
                                                statusLabels[
                                                    runState(
                                                        page.incident.check,
                                                        run.result,
                                                    )
                                                ],
                                            )}
                                        />
                                        <TruncatedText content={text}>
                                            <span className="min-w-0 flex-1 truncate">
                                                {text}
                                            </span>
                                        </TruncatedText>
                                    </li>
                                );
                            })}
                        </ul>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

/** Mail of incident events outside the timeline (e.g. missed scheduled tasks), newest first. */
function OtherNotifications({
    deliveries,
    previewAvailable,
    onPreview,
}: {
    deliveries: Heptapod.Data.Ui.IncidentDeliveryData[];
    previewAvailable: boolean;
    onPreview: (eventId: number) => void;
}) {
    const { t } = useTranslation();
    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('Other notifications')}</CardTitle>
            </CardHeader>
            <CardContent className="space-y-3 text-sm">
                <ol className="space-y-4">
                    {deliveries.slice(0, 50).map((delivery) => (
                        <li key={delivery.id}>
                            <IncidentDeliveryRow
                                delivery={delivery}
                                previewAvailable={previewAvailable}
                                onPreview={onPreview}
                            />
                        </li>
                    ))}
                </ol>
                {deliveries.length > 50 && (
                    <p className="text-xs text-muted-foreground">
                        {t('More earlier deliveries not shown')}
                    </p>
                )}
            </CardContent>
        </Card>
    );
}
