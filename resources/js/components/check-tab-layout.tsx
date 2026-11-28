import type { ActionNoteDescriptor } from '@/components/action-note-slot';
import { TooltipHint } from '@/components/truncated-text';
import { PresentationChanges } from '@/components/presentation-changes';
import { useState, type ReactNode } from 'react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { useFormat } from '@/hooks/use-format';
import { Power } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { PageHeader } from '@/components/page-header';
import { MonitoringTime, StatusBadge } from '@/components/monitoring';
import { EmptyState } from '@/components/empty-state';
import { Card, CardContent } from '@/components/ui/card';
import {
    CheckScheduleSummary,
    CheckSettingsFacts,
} from '@/components/check-settings-facts';
import { CheckSettingsSheet } from '@/components/check-settings-sheet';
import { PageHeaderAction } from '@/components/page-header-actions';
import { CheckHistory } from '@/components/check-history';
import { checkState, isSnoozed, runState, statusLabels } from '@/lib/status';
import { AbsoluteTime } from '@/components/absolute-time';
import {
    CheckSnoozeActions,
    CheckTurnOffAction,
} from '@/components/check-snooze-actions';
import { TextLink } from '@/components/text-link';
import { show as incidentShow } from '@/routes/incidents';

export function CheckTabLayout({
    page,
    headline,
    primaryAction,
    actionNote,
    runStatus,
    evidence,
    settingsVisibility,
}: {
    page: Heptapod.Data.Ui.CheckTabPageData;
    headline: string;
    primaryAction?: ReactNode;
    actionNote?: ActionNoteDescriptor;
    runStatus?: ReactNode;
    evidence: ReactNode;
    settingsVisibility?: { open: boolean; setOpen: (open: boolean) => void };
}) {
    const { t } = useTranslation();
    const format = useFormat();
    const [settingsOpen, setSettingsOpen] = useState(false);
    const sheet = settingsVisibility ?? {
        open: settingsOpen,
        setOpen: setSettingsOpen,
    };
    const snoozed = isSnoozed(page.check, Date.now());
    const snoozable = page.check.enabled && page.header.state !== 'paused';
    const turnOffAvailable = snoozable && page.can_run;
    const freshness = page.schedule.freshness;
    if (freshness.lost_attempts > 0 && page.schedule.last_loss === null) {
        throw new Error(
            'A check with lost attempts must retain its last loss cause.',
        );
    }
    let freshnessTimes: {
        since: string;
        awaiting: string;
        exact: string;
    } | null = null;
    if (
        freshness.state === 'not_fresh' &&
        page.freshness_suppressed_by === null
    ) {
        if (
            freshness.not_fresh_since === null ||
            freshness.awaiting_result_since === null
        ) {
            throw new Error(
                'A not-fresh check must have its episode and threshold timestamps.',
            );
        }
        freshnessTimes = {
            since: format.timestamp(freshness.not_fresh_since),
            awaiting: format.timestamp(freshness.awaiting_result_since),
            exact: `${format.exact(freshness.not_fresh_since)}\n${format.exact(freshness.awaiting_result_since)}`,
        };
    }

    const presentationDetails =
        page.check.enabled &&
        page.freshness_suppressed_by === null &&
        !page.check.pending_recheck &&
        page.check.presentation &&
        (page.check.presentation.changes.length > 0 ||
            page.check.presentation.details.length > 0) ? (
            <PresentationChanges
                changes={page.check.presentation.changes}
                details={page.check.presentation.details}
            />
        ) : undefined;

    return (
        <section className="space-y-6" data-check-detail={page.check.key}>
            <PageHeader
                titleTooltip={format.presentationTime(
                    page.freshness_suppression
                        ? [page.freshness_suppression]
                        : page.performance_unavailable
                          ? [page.performance_unavailable]
                          : (page.check.presentation?.parts ?? []),
                )}
                title={
                    page.check.enabled
                        ? page.freshness_suppression
                            ? format.presentationPart(
                                  page.freshness_suppression,
                              )
                            : page.performance_unavailable
                              ? format.presentationPart(
                                    page.performance_unavailable,
                                )
                              : headline
                        : t('This check is turned off.')
                }
                description=""
                metadata={
                    <div className="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                        <span className="text-xs font-medium text-muted-foreground">
                            {t(page.check.label_key)}
                        </span>
                        <StatusBadge
                            state={checkState({
                                ...page.check,
                                freshness_suppressed_by:
                                    page.freshness_suppressed_by,
                            })}
                            label={
                                !snoozed &&
                                page.check.enabled &&
                                page.freshness_suppressed_by !== null
                                    ? 'No current data'
                                    : undefined
                            }
                            snoozed={snoozed}
                        />
                        {snoozed && (
                            <span className="text-xs text-muted-foreground">
                                {t('Snoozed until')}{' '}
                                <AbsoluteTime
                                    value={page.check.snoozed_until!}
                                />
                            </span>
                        )}
                        {page.check.enabled && page.schedule.last_loss && (
                            <span className="text-xs text-status-warn">
                                {t(
                                    `freshness.loss.${page.schedule.last_loss.cause}`,
                                )}
                            </span>
                        )}
                        {page.check.enabled && page.check.checked_at && (
                            <span className="text-xs text-foreground-faint">
                                {page.check.pending_recheck
                                    ? t('Last result: {{result}}', {
                                          result: t(
                                              statusLabels[
                                                  runState(
                                                      page.check.key,
                                                      page.check.result,
                                                  )
                                              ],
                                          ),
                                      })
                                    : t('Last checked')}
                                {page.check.pending_recheck ? ' · ' : ' '}
                                <MonitoringTime
                                    value={page.check.checked_at}
                                    className="font-mono text-xs tabular-nums"
                                />
                            </span>
                        )}
                        <CheckScheduleSummary page={page} />
                        {page.check.open_incident_id !== null && (
                            <span className="text-xs">
                                <TextLink
                                    variant="secondary"
                                    href={incidentShow.url(
                                        page.check.open_incident_id,
                                    )}
                                    prefetch
                                >
                                    {t('Open incident')}
                                </TextLink>
                            </span>
                        )}
                    </div>
                }
                meta={presentationDetails}
                primaryAction={
                    snoozable || primaryAction ? (
                        <>
                            {snoozable && (
                                <CheckSnoozeActions
                                    siteId={page.site.id}
                                    check={page.check}
                                />
                            )}
                            {primaryAction}
                        </>
                    ) : undefined
                }
                actionNote={actionNote}
                // Run check plus More actions; without Run check (read-only roles) the one settings item stays a button.
                overflowSecondary={primaryAction ? 'always' : false}
                secondaryActionCount={turnOffAvailable ? 2 : 1}
                secondaryActions={
                    <>
                        <PageHeaderAction
                            variant="outline"
                            onClick={() => sheet.setOpen(true)}
                        >
                            {t(
                                page.check_settings.can_update
                                    ? 'Configure check…'
                                    : 'Check settings',
                            )}
                        </PageHeaderAction>
                        {turnOffAvailable && (
                            <CheckTurnOffAction
                                siteId={page.site.id}
                                checkId={page.check.id}
                                checkName={t(page.check.label_key)}
                            />
                        )}
                    </>
                }
                notices={
                    <>
                        {page.check_settings.attention_reason && (
                            <Alert tone="warning">
                                <AlertTitle>{t('Needs attention')}</AlertTitle>
                                <AlertDescription>
                                    {t(page.check_settings.attention_reason)}
                                </AlertDescription>
                            </Alert>
                        )}
                        {freshnessTimes !== null && (
                            <Alert tone="warning">
                                <AlertTitle>
                                    {t('freshness.not_fresh')}
                                </AlertTitle>
                                <AlertDescription>
                                    <TooltipHint content={freshnessTimes.exact}>
                                        <p className="font-mono">
                                            {t(
                                                'freshness.notice',
                                                freshnessTimes,
                                            )}
                                        </p>
                                    </TooltipHint>
                                    {freshness.lost_attempts > 0 &&
                                        page.schedule.last_loss && (
                                            <p>
                                                {t('freshness.lost', {
                                                    count: freshness.lost_attempts,
                                                })}{' '}
                                                ·{' '}
                                                {t(
                                                    `freshness.loss.${page.schedule.last_loss.cause}`,
                                                )}
                                            </p>
                                        )}
                                </AlertDescription>
                            </Alert>
                        )}
                    </>
                }
            />
            <CheckSettingsSheet
                check={page.check_settings}
                visibility={sheet}
                discoveredUrls={
                    page.detail?.check_type === 'sitemap'
                        ? page.detail.totalUrlCount
                        : null
                }
                facts={<CheckSettingsFacts page={page} />}
                trigger={() => null}
            />
            {runStatus}
            <div className="min-w-0 space-y-4">
                {page.check.enabled ? (
                    evidence
                ) : (
                    <Card>
                        <CardContent>
                            <EmptyState
                                bare
                                icon={Power}
                                title={t('Check off')}
                                description={t(
                                    page.check.key === 'application_health'
                                        ? 'Application health reads a JSON endpoint on your site and reports each of its checks.'
                                        : 'Turn this check on to collect new results. Earlier results remain in the history below.',
                                )}
                            />
                        </CardContent>
                    </Card>
                )}
            </div>
            <CheckHistory page={page} />
        </section>
    );
}
