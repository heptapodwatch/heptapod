import type { ActionNoteDescriptor } from '@/components/action-note-slot';
import { useState, type ReactNode } from 'react';
import { Send } from 'lucide-react';
import { SendToClientSheet } from '@/components/send-to-client-sheet';
import { PageHeaderAction } from '@/components/page-header-actions';
import type { RequestedRun } from '@/hooks/use-requested-run';
import { CheckTabLayout } from '@/components/check-tab-layout';
import { CheckEvidence } from '@/components/check-evidence';
import { DnsComparison } from '@/components/dns-comparison';
import { CronTasksDetail } from '@/components/cron-tasks-detail';
import { ApplicationHealthDetail } from '@/components/application-health-detail';
import { ChartAreaInteractive } from '@/components/chart-area-interactive';
import { MonthlyUptime } from '@/components/monthly-uptime';
import { LighthouseScoreHistory } from '@/components/lighthouse-score-history';
import { PerformanceEvidence } from '@/components/performance-evidence';
import { useCheckHeadline } from '@/hooks/use-check-headline';
import { usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { findings as findingsExport } from '@/routes/sites/checks';

export function CheckDetail({
    page,
    primaryAction,
    actionNote,
    runStatus,
    requestedRun,
}: {
    page: Heptapod.Data.Ui.CheckTabPageData;
    primaryAction?: ReactNode;
    actionNote?: ActionNoteDescriptor;
    /** A run in flight (status row, late note) and the run history's pending changes, below the header. */
    runStatus?: ReactNode;
    requestedRun: RequestedRun;
}) {
    const headlineFor = useCheckHeadline();
    const { auth } = usePage().props;
    const { t } = useTranslation();
    const { check } = page;
    const [sendOpen, setSendOpen] = useState(false);
    // Findings a client's editor can act on: broken links, insecure content, sitemap addresses that fail.
    const sendable =
        auth.canManageChannels &&
        page.client !== null &&
        ['broken_links', 'mixed_content', 'sitemap'].includes(check.key) &&
        (check.state === 'failed' || check.state === 'warning') &&
        page.runs.length > 0;
    const latest = page.runs[0];
    const headline = headlineFor(check);
    const evidence = (
        <>
            {check.key === 'performance' ? (
                <PerformanceEvidence page={page} />
            ) : page.history?.check_type === 'dns' ? (
                <DnsComparison
                    page={page}
                    history={page.history}
                    requestedRun={requestedRun}
                />
            ) : page.history?.check_type === 'cron' ? (
                <CronTasksDetail page={page} history={page.history} />
            ) : page.history?.check_type === 'application_health' ? (
                <ApplicationHealthDetail history={page.history} />
            ) : (
                <>
                    {page.response_time && (
                        <ChartAreaInteractive
                            series={page.response_time}
                            phases={page.phase_series}
                        />
                    )}
                    {page.monthly_uptime && (
                        <MonthlyUptime data={page.monthly_uptime} />
                    )}
                    <CheckEvidence
                        action={
                            sendable ? (
                                <PageHeaderAction
                                    type="button"
                                    variant="outline"
                                    onClick={() => setSendOpen(true)}
                                >
                                    <Send aria-hidden />
                                    {t('client_messages.send_action')}
                                </PageHeaderAction>
                            ) : undefined
                        }
                        type={check.key}
                        detail={page.detail}
                        history={page.history}
                        presentation={check.presentation}
                        latest={latest}
                        since={check.open_incident_started_at}
                        headerTime={check.checked_at}
                        exportContext={
                            [
                                'broken_links',
                                'mixed_content',
                                'sitemap',
                            ].includes(check.key)
                                ? {
                                      url: (format, query) =>
                                          findingsExport.url(
                                              {
                                                  site: page.header.id,
                                                  check: check.id,
                                                  format,
                                              },
                                              { query },
                                          ),
                                  }
                                : undefined
                        }
                        origin={new URL(page.header.url).origin}
                        requestedHost={new URL(page.header.url).hostname}
                    />
                    {page.history?.check_type === 'lighthouse' &&
                        page.history.scores && (
                            <LighthouseScoreHistory
                                weeks={page.history.scores}
                            />
                        )}
                </>
            )}
        </>
    );
    return (
        <>
            <CheckTabLayout
                page={page}
                headline={headline}
                primaryAction={primaryAction}
                actionNote={actionNote}
                runStatus={runStatus}
                evidence={evidence}
            />
            {sendable && page.client && sendOpen && (
                <SendToClientSheet
                    open
                    onOpenChange={setSendOpen}
                    client={page.client}
                    subject={{ kind: 'findings', check_id: check.id }}
                    initialDesign={page.mail_design}
                />
            )}
        </>
    );
}
