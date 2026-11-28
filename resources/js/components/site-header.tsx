import { AbsoluteTime } from '@/components/absolute-time';
import { PageHeader } from '@/components/page-header';
import { PageHeaderAction } from '@/components/page-header-actions';
import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { MonitoringTime, StatusBadge } from '@/components/monitoring';
import { SiteRunAll } from '@/components/site-run-all';
import { Alert, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import {
    Tooltip,
    TooltipContent,
    TooltipDetails,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { ConfirmDialog, confirmVisit } from '@/components/confirm-dialog';
import { useFormat } from '@/hooks/use-format';
import { checkState, siteStateLabel, stateCountOrder } from '@/lib/status';
import { pause, resume } from '@/routes/sites';
import { index as maintenanceIndex } from '@/routes/maintenance';

type Site = Heptapod.Data.Ui.SiteRowData;

function SitePauseAction({
    site,
    resumeAt,
}: {
    site: Site;
    resumeAt: string | null;
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const [until, setUntil] = useState<'manual' | 'hour' | 'tomorrow'>(
        'manual',
    );
    const [busy, setBusy] = useState(false);
    const paused = site.identity.state === 'paused';

    return (
        <>
            <PageHeaderAction
                variant="outline"
                disabled={busy}
                onClick={() => {
                    if (paused) {
                        setBusy(true);
                        router.post(
                            resume.url(site.id),
                            {},
                            {
                                preserveScroll: true,
                                onFinish: () => setBusy(false),
                            },
                        );
                    } else {
                        setOpen(true);
                    }
                }}
            >
                {t(paused ? 'Resume site' : 'Pause site')}
                {paused && resumeAt && (
                    <>
                        {' '}
                        · <AbsoluteTime value={resumeAt} />
                    </>
                )}
            </PageHeaderAction>
            {/* Pausing is reversible (Resume site), so the confirmation is neutral. */}
            <ConfirmDialog
                open={open}
                onOpenChange={setOpen}
                title={t('Pause {{name}}?', { name: site.identity.name })}
                description={t(
                    'Pausing stops checks and alerts. History and open incidents remain.',
                )}
                confirmLabel={t('Pause site')}
                onConfirm={() =>
                    confirmVisit((callbacks) =>
                        router.post(
                            pause.url(site.id),
                            { until },
                            { preserveScroll: true, ...callbacks },
                        ),
                    )
                }
            >
                <div className="space-y-1">
                    <Label id="pause-until-label">{t('Until')}</Label>
                    <RadioGroup
                        aria-labelledby="pause-until-label"
                        orientation="vertical"
                        value={until}
                        onValueChange={(value) =>
                            setUntil(value as typeof until)
                        }
                    >
                        {(['manual', 'hour', 'tomorrow'] as const).map(
                            (option) => (
                                <RadioGroupItem key={option} value={option}>
                                    {t(
                                        option === 'manual'
                                            ? 'I resume it'
                                            : option === 'hour'
                                              ? '1 hour'
                                              : 'Tomorrow 08:00',
                                    )}
                                </RadioGroupItem>
                            ),
                        )}
                    </RadioGroup>
                </div>
                {site.identity.state === 'down' && (
                    <Alert tone="warning">
                        <AlertTitle>
                            {t(
                                'This site is down. Pausing hides it and stops alerts.',
                            )}
                        </AlertTitle>
                    </Alert>
                )}
            </ConfirmDialog>
        </>
    );
}

/**
 * The site Overview header, the one home of the site-wide actions: the site state as h1, Run all checks… as primary,
 * Maintenance and Pause site / Resume site in More actions, the freshness warning as its notice.
 */
export function SiteOverviewHeader({
    site,
    canManage,
    runAllChecks,
    resumeAt,
    maintenance,
}: {
    site: Site;
    canManage: boolean;
    runAllChecks: Heptapod.Data.Ui.RunAllCheckData[];
    resumeAt: string | null;
    maintenance: Heptapod.Data.Ui.MaintenanceSiteHeaderData | null;
}) {
    const { t } = useTranslation();
    const format = useFormat();
    const enabledChecks = site.checks.filter((check) => check.enabled);
    const attentionSummary = stateCountOrder
        .filter((state) => state !== 'ok')
        .flatMap((state) => {
            const count = enabledChecks.filter(
                (check) => checkState(check) === state,
            ).length;
            return count ? [t(`site.checks.${state}`, { count })] : [];
        })
        .join(', ');
    const title = site.identity.deleting
        ? t('site.deleting')
        : site.identity.state === 'paused'
          ? t('site_overview.paused')
          : attentionSummary ||
            t(enabledChecks.length ? 'All checks OK' : 'No checks enabled');
    const incidentStartedAt = enabledChecks
        .flatMap((check) =>
            check.open_incident_started_at
                ? [check.open_incident_started_at]
                : [],
        )
        .sort()[0];
    const staleCount = site.checks.filter(
        (check) => check.not_fresh && !check.freshness_suppressed_by,
    ).length;
    // Without a current window, managers go straight to the new-window sheet for this site; otherwise to its windows.
    const scheduleMaintenance = canManage && maintenance?.id == null;
    const maintenanceLink = (
        <PageHeaderAction variant="outline" asChild>
            <Link
                href={maintenanceIndex.url({
                    query: scheduleMaintenance
                        ? { site_id: site.id, new: 1 }
                        : { site_id: site.id },
                })}
                prefetch
            >
                {t(
                    maintenance?.state === 'active'
                        ? 'Maintenance active'
                        : scheduleMaintenance
                          ? 'Schedule maintenance'
                          : 'Maintenance',
                )}
            </Link>
        </PageHeaderAction>
    );
    const runAllAction =
        canManage &&
        site.identity.state !== 'paused' &&
        runAllChecks.length > 0 ? (
            <SiteRunAll site={site} plans={runAllChecks} />
        ) : null;
    const maintenanceSourceTime =
        maintenance?.startsAt && maintenance.endsAt
            ? format.windowSourceTime(
                  maintenance.startsAt,
                  maintenance.endsAt,
                  maintenance.timezone,
              )
            : null;
    const secondaryActionCount =
        Number(Boolean(canManage || maintenance)) +
        Number(canManage && !site.identity.deleting);
    return (
        <PageHeader
            title={title}
            description=""
            metadata={
                <div className="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                    <span className="text-xs font-medium text-muted-foreground">
                        {t('Overview')}
                    </span>
                    <StatusBadge
                        state={site.identity.state}
                        label={siteStateLabel(site)}
                    />
                    {incidentStartedAt && (
                        <span className="text-xs text-foreground-faint">
                            {t('Since')}{' '}
                            <MonitoringTime
                                value={incidentStartedAt}
                                mode="since"
                                className="font-mono text-xs tabular-nums"
                            />
                        </span>
                    )}
                    {site.tags.map((tag) => (
                        <Badge key={tag} variant="outline">
                            {tag}
                        </Badge>
                    ))}
                </div>
            }
            notices={
                staleCount > 0 && (
                    <Alert tone="warning">
                        <AlertTitle>
                            {t('freshness.site', { count: staleCount })}
                        </AlertTitle>
                    </Alert>
                )
            }
            // One primary per site header: Run all checks. Everything else is in More actions.
            primaryAction={runAllAction}
            // Exactly one primary plus More actions, even for a single secondary. Without a primary
            // (never leave only a More menu) the secondary actions stay buttons.
            overflowSecondary={runAllAction != null ? 'always' : false}
            secondaryActionCount={secondaryActionCount}
            secondaryActions={
                secondaryActionCount > 0 && (
                    <>
                        {(canManage || maintenance) &&
                            (maintenance?.id != null ? (
                                <Tooltip>
                                    <TooltipTrigger asChild>
                                        {maintenanceLink}
                                    </TooltipTrigger>
                                    <TooltipContent>
                                        <TooltipDetails
                                            subject={t('Maintenance')}
                                            facts={[
                                                ...(maintenance.reason
                                                    ? [maintenance.reason]
                                                    : []),
                                                ...(maintenance.startsAt &&
                                                maintenance.endsAt
                                                    ? [
                                                          `${format.exact(maintenance.startsAt)} – ${format.exact(maintenance.endsAt)}`,
                                                          ...(maintenanceSourceTime
                                                              ? [
                                                                    maintenanceSourceTime,
                                                                ]
                                                              : []),
                                                      ]
                                                    : []),
                                            ]}
                                            status={{
                                                state: 'maintenance',
                                                label: t(
                                                    maintenance.state ===
                                                        'active'
                                                        ? 'Active'
                                                        : 'Scheduled',
                                                ),
                                            }}
                                        />
                                    </TooltipContent>
                                </Tooltip>
                            ) : (
                                maintenanceLink
                            ))}
                        {canManage && !site.identity.deleting && (
                            <SitePauseAction site={site} resumeAt={resumeAt} />
                        )}
                    </>
                )
            }
        />
    );
}
