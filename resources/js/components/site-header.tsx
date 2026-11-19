import { TooltipHint } from '@/components/truncated-text';
import { AbsoluteTime } from '@/components/absolute-time';
import { SiteAvatar } from '@/components/site-avatar';
import { PageHeader } from '@/components/page-header';
import { PageHeaderAction } from '@/components/page-header-actions';
import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { ExternalLink } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { StatusBadge } from '@/components/monitoring';
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
import { show as clientShow } from '@/routes/clients';
import { pause, resume } from '@/routes/sites';
import { index as maintenanceIndex } from '@/routes/maintenance';
import { TextLink } from '@/components/text-link';

type Site = Heptapod.Data.Ui.SiteRowData;

export function SitePauseAction({
    site,
    resumeAt,
    onChanged,
}: {
    site: Site;
    resumeAt?: string | null;
    onChanged?: () => void;
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
                                onSuccess: () => onChanged?.(),
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
                onConfirm={async () => {
                    const paused = await confirmVisit((callbacks) =>
                        router.post(
                            pause.url(site.id),
                            { until },
                            { preserveScroll: true, ...callbacks },
                        ),
                    );
                    if (paused) onChanged?.();
                    return paused;
                }}
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
                                              : 'Tomorrow 08:00 (instance time)',
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

export function SiteHeader({
    site,
    client,
    canManage,
    runAllChecks,
    resumeAt,
    maintenance,
    showTrail = false,
    metaDetails,
}: {
    site: Site;
    client: Heptapod.Data.Ui.ClientData | null;
    canManage: boolean;
    runAllChecks?: Heptapod.Data.Ui.RunAllCheckData[];
    resumeAt?: string | null;
    maintenance?: Heptapod.Data.Ui.MaintenanceSiteHeaderData | null;
    showTrail?: boolean;
    metaDetails?: React.ReactNode;
}) {
    const { t } = useTranslation();
    const format = useFormat();
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
        runAllChecks &&
        runAllChecks.length > 0 ? (
            <SiteRunAll site={site} plans={runAllChecks} />
        ) : null;
    const primary = runAllAction;
    const secondaryActionCount =
        Number(Boolean(canManage || maintenance)) + Number(canManage);
    return (
        <PageHeader
            leading={
                <SiteAvatar
                    name={site.identity.name}
                    iconUrl={site.identity.icon_url}
                    iconBacking={site.identity.icon_backing}
                />
            }
            title={site.identity.name}
            titleAction={
                showTrail ? (
                    <TooltipHint content={site.identity.url}>
                        <a
                            href={site.identity.url}
                            target="_blank"
                            rel="noreferrer"
                            aria-label={t('Open site')}
                            className="inline-flex size-6 items-center justify-center rounded-sm text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            <ExternalLink className="size-4" />
                        </a>
                    </TooltipHint>
                ) : undefined
            }
            trail={
                showTrail && client ? (
                    <TextLink
                        variant="secondary"
                        href={clientShow.url(client.id)}
                        prefetch
                    >
                        {client.name}
                    </TextLink>
                ) : undefined
            }
            meta={
                showTrail ? (
                    <>
                        <StatusBadge state={site.identity.state} />
                        {metaDetails ??
                            site.tags.map((tag) => (
                                <Badge key={tag} variant="outline">
                                    {tag}
                                </Badge>
                            ))}
                    </>
                ) : undefined
            }
            badge={
                showTrail ? undefined : (
                    <StatusBadge state={site.identity.state} />
                )
            }
            metadata={
                !showTrail && (
                    <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                        {client && (
                            <TextLink
                                variant="secondary"
                                href={clientShow.url(client.id)}
                                prefetch
                            >
                                {client.name}
                            </TextLink>
                        )}
                        {site.tags.map((tag) => (
                            <Badge key={tag} variant="outline">
                                {tag}
                            </Badge>
                        ))}
                        <span className="font-mono text-xs break-all">
                            {site.identity.url}
                        </span>
                    </div>
                )
            }
            description={t('pages.site.description')}
            // One primary per site header: Run all checks. Everything else is in More actions.
            primaryAction={primary}
            // Exactly one primary plus More actions, even for a single secondary. Without a primary
            // (never leave only a More menu) the secondary actions stay buttons.
            overflowSecondary={primary != null ? 'always' : false}
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
                        {canManage && (
                            <SitePauseAction site={site} resumeAt={resumeAt} />
                        )}
                    </>
                )
            }
        />
    );
}
