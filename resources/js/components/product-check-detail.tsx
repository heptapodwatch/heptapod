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
import { PerformanceEvidence } from '@/components/performance-evidence';
import { ResponseBreakdown } from '@/components/response-breakdown';
import { useCheckHeadline } from '@/hooks/use-check-headline';
import { usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { checkTypes } from '@/lib/check-types';
import { findingsTimestamp } from '@/lib/findings-export';
import { findings as findingsCsv } from '@/routes/sites/checks';

export function ProductCheckDetail({
    page,
    primaryAction,
    requestedRun,
}: {
    page: Heptapod.Data.Ui.CheckTabPageData;
    primaryAction?: ReactNode;
    requestedRun: RequestedRun;
}) {
    const headlineFor = useCheckHeadline();
    const { timezone, auth } = usePage().props;
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
                    {check.key === 'uptime' &&
                        page.response_times.length > 0 && (
                            <ChartAreaInteractive
                                series={page.response_times}
                            />
                        )}
                    {check.key === 'uptime' &&
                        page.phase_series.some((series) =>
                            series.points.some((point) => point.value !== null),
                        ) && <ResponseBreakdown series={page.phase_series} />}
                    <CheckEvidence
                        action={
                            sendable ? (
                                <PageHeaderAction
                                    type="button"
                                    variant="outline"
                                    size="sm"
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
                        exportContext={
                            [
                                'broken_links',
                                'mixed_content',
                                'sitemap',
                            ].includes(check.key)
                                ? {
                                      site: `${page.header.name} (${page.header.url})`,
                                      check: t(checkTypes[check.key].label),
                                      headline,
                                      checkedRunId: latest?.id ?? null,
                                      checkedAt: check.checked_at
                                          ? findingsTimestamp(
                                                check.checked_at,
                                                timezone,
                                            )
                                          : null,
                                      downloadUrl: (run, observedAt) =>
                                          findingsCsv.url(
                                              {
                                                  site: page.header.id,
                                                  check: check.id,
                                              },
                                              {
                                                  query: {
                                                      run,
                                                      observed_at: observedAt,
                                                  },
                                              },
                                          ),
                                  }
                                : undefined
                        }
                        origin={new URL(page.header.url).origin}
                        requestedHost={new URL(page.header.url).hostname}
                    />
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
