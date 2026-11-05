import { cn } from '@/lib/utils';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { listRowPadding } from '@/components/ui/table';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { PageHeaderAction } from '@/components/page-header-actions';
import { Checkbox } from '@/components/ui/checkbox';
import { ConfirmDialog, confirmVisit } from '@/components/confirm-dialog';
import { useFormat } from '@/hooks/use-format';
import { runAll } from '@/routes/sites';

type SiteRow = Heptapod.Data.Ui.SiteRowData;
type RunPlan = Heptapod.Data.Ui.RunAllCheckData;
type RunResult = Heptapod.Data.Ui.SiteOverviewPageData['run_all_result'];

function queueLabel(queue: string): string {
    switch (queue) {
        case 'checks-fast':
            return 'Fast queue';
        case 'checks-crawl':
            return 'Crawl queue';
        case 'checks-browser':
            return 'Browser queue';
        default:
            throw new Error(`Unknown run queue: ${queue}`);
    }
}

function resultLabel(status: string, reason: string | null): string {
    if (status === 'queued' && reason === null) return 'Queued';
    if (status !== 'skipped')
        throw new Error(`Unknown run-all status: ${status}`);
    switch (reason) {
        case 'fast_only':
            return 'Skipped: fast checks only';
        case 'unavailable':
            return 'Unavailable';
        case 'busy':
            return 'Not ready to run';
        case 'rate_limited':
            return 'Requested recently';
        default:
            throw new Error(`Unknown run-all skip reason: ${reason}`);
    }
}

export function SiteRunAll({
    site,
    plans,
}: {
    site: SiteRow;
    plans: RunPlan[];
}) {
    const { t } = useTranslation();
    const format = useFormat();
    const [open, setOpen] = useState(false);
    const [fastOnly, setFastOnly] = useState(false);
    if (!plans.length) return null;
    const eligible = plans.filter(
        (plan) => plan.available && (!fastOnly || plan.queue === 'checks-fast'),
    ).length;

    return (
        <>
            <PageHeaderAction onClick={() => setOpen(true)}>
                {t('Run all checks…')}
            </PageHeaderAction>
            <ConfirmDialog
                open={open}
                onOpenChange={setOpen}
                title={t('Run all checks on {{name}}?', {
                    name: site.identity.name,
                })}
                description={t(
                    'This sends real requests to the site. The schedule does not move.',
                )}
                confirmLabel={t('Run {{count}} checks', {
                    count: eligible,
                    formattedCount: format.number(eligible),
                })}
                confirmDisabled={eligible === 0}
                onConfirm={() =>
                    confirmVisit((callbacks) =>
                        router.post(
                            runAll.url(site.identity.id),
                            { confirmed: true, fast_only: fastOnly },
                            { preserveScroll: true, ...callbacks },
                        ),
                    )
                }
            >
                <div className="max-h-72 overflow-y-auto rounded-md border text-sm">
                    {plans.map((plan) => {
                        const check = site.checks.find(
                            (item) => item.id === plan.check_id,
                        );
                        if (!check)
                            throw new Error(
                                `Run-all preview references missing check ${plan.check_id}`,
                            );
                        const skippedForSpeed =
                            fastOnly && plan.queue !== 'checks-fast';
                        return (
                            <div
                                key={plan.check_id}
                                className={cn(
                                    'grid gap-1 border-b last:border-b-0 sm:grid-cols-[minmax(0,1fr)_auto]',
                                    listRowPadding,
                                )}
                            >
                                <span>{t(check.label_key)}</span>
                                <span className="text-xs text-muted-foreground sm:text-right">
                                    {!plan.available ? (
                                        t('Unavailable')
                                    ) : skippedForSpeed ? (
                                        t('Skipped: fast checks only')
                                    ) : (
                                        <>
                                            {t(queueLabel(plan.queue))} ·{' '}
                                            {t('At most {{duration}}', {
                                                duration: format.duration(
                                                    plan.maximum_seconds,
                                                ),
                                            })}
                                        </>
                                    )}
                                </span>
                            </div>
                        );
                    })}
                </div>
                <label className="flex min-h-10 items-center gap-2 text-sm">
                    <Checkbox
                        checked={fastOnly}
                        onCheckedChange={(checked) =>
                            setFastOnly(checked === true)
                        }
                    />
                    {t('Only fast checks')}
                </label>
            </ConfirmDialog>
        </>
    );
}

export function SiteRunAllResult({
    site,
    result,
}: {
    site: SiteRow;
    result: RunResult;
}) {
    const { t } = useTranslation();
    const format = useFormat();
    if (!result) return null;
    return (
        <Alert tone="info">
            <AlertTitle>
                {t(
                    'Request result: {{queued}} queued · {{unavailable}} unavailable',
                    {
                        queued: format.number(result.queued),
                        unavailable: format.number(result.unavailable),
                    },
                )}
            </AlertTitle>
            <AlertDescription>
                <ul className="space-y-1">
                    {result.results.map((item) => {
                        const check = site.checks.find(
                            (row) => row.id === item.check_id,
                        );
                        if (!check)
                            throw new Error(
                                `Run-all result references missing check ${item.check_id}`,
                            );
                        return (
                            <li key={item.check_id}>
                                {t(check.label_key)} ·{' '}
                                {t(resultLabel(item.status, item.reason))}
                            </li>
                        );
                    })}
                </ul>
            </AlertDescription>
        </Alert>
    );
}
