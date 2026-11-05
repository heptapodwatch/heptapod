import { Disclosure } from '@/components/ui/disclosure';
import { SectionHeading } from '@/components/section-heading';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { ConfirmDialog, confirmVisit } from '@/components/confirm-dialog';
import { useRunState } from '@/hooks/use-live';
import type { RequestedRun } from '@/hooks/use-requested-run';
import { currentRunState } from '@/lib/live-store';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { Alert, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    CardAction,
} from '@/components/ui/card';
import { MonitoringTime } from '@/components/monitoring';
import { EvidenceFacts } from '@/components/evidence-facts';
import { acceptDns } from '@/routes/sites/checks';

type DnsRecords = Record<string, Record<string, string | number>[]>;

function Records({ records }: { records: DnsRecords }) {
    const { t } = useTranslation();
    return Object.keys(records).length ? (
        <EvidenceFacts
            rows={Object.entries(records).map(([group, items]) => ({
                label: group,
                mono: true,
                value: (
                    <div className="space-y-1">
                        {items.map((item, index) => (
                            <p key={index}>
                                {Object.entries(item)
                                    .map(([name, value]) => `${name}: ${value}`)
                                    .join(' · ')}
                            </p>
                        ))}
                    </div>
                ),
            }))}
        />
    ) : (
        <p className="text-sm text-muted-foreground">
            {t('No DNS records observed')}
        </p>
    );
}

export function DnsComparison({
    page,
    history,
    requestedRun,
}: {
    page: {
        can_run: boolean;
        check: Pick<
            Heptapod.Data.Ui.CheckStripData,
            'id' | 'result' | 'pending_recheck'
        >;
        site: Pick<Heptapod.Data.Ui.SiteRowData, 'id'>;
        schedule: { run: Heptapod.Data.Ui.CheckRunStateData | null };
    };
    history: Heptapod.Addons.Dns.DnsComparisonData;
    requestedRun: RequestedRun;
}) {
    const { t } = useTranslation();
    const changes = Object.entries(history.changes).filter(
        ([, difference]) =>
            difference.added.length || difference.removed.length,
    );
    const [confirmOpen, setConfirmOpen] = useState(false);
    const live = useRunState(page.check.id);
    const run = currentRunState(live, page.schedule.run);
    const activeRun = run !== undefined && run.phase !== 'finished';
    const canAccept =
        page.can_run &&
        history.current_run_id !== null &&
        history.accept_would_change_baseline &&
        !page.check.pending_recheck;
    const unfixableIssues = [
        ...new Set(
            history.current_issues
                .filter((issue) => issue.type !== 'dns.issue.records_missing')
                .map((issue) => issue.type),
        ),
    ];
    function issueGuidance(type: string): string {
        switch (type) {
            case 'dns.issue.nameservers_out_of_sync':
            case 'dns.issue.nameserver_unreachable':
            case 'dns.issue.nameservers_unreachable':
            case 'dns.issue.nameservers_missing':
            case 'dns.issue.resolves_to_nothing':
                return (
                    t(type) +
                    ' ' +
                    t(type.replace('dns.issue.', 'dns.guidance.'))
                );
            case 'dns.issue.records_missing':
                return t(type, {
                    types: [
                        ...new Set(
                            history.current_issues
                                .filter((issue) => issue.type === type)
                                .map((issue) => issue.record_type),
                        ),
                    ].join(', '),
                });
            default:
                throw new Error(`Unknown DNS issue: ${type}`);
        }
    }
    const stillDiffers =
        !page.check.pending_recheck &&
        history.accepted_by !== null &&
        history.current_run_id !== history.accepted_run_id &&
        ['failed', 'warning'].includes(page.check.result) &&
        (history.accept_would_change_baseline || changes.length > 0);
    const reasons = [
        ...new Set([
            ...changes.map(([type]) => type),
            ...history.current_issues.map((issue) => issueGuidance(issue.type)),
        ]),
    ].join(' · ');

    return (
        <div className="space-y-4">
            <Card className="overflow-hidden">
                <CardHeader>
                    <CardTitle>{t('DNS comparison')}</CardTitle>
                    <CardAction>
                        {canAccept && !activeRun && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setConfirmOpen(true)}
                            >
                                {t('Accept as normal')}
                            </Button>
                        )}
                        <ConfirmDialog
                            open={confirmOpen}
                            onOpenChange={setConfirmOpen}
                            title={t('Accept as normal')}
                            description={t(
                                unfixableIssues.length
                                    ? 'Accepting stores the current records. It does not fix: {{issues}}'
                                    : 'Accept current DNS records as normal? A new check will run.',
                                {
                                    issues: unfixableIssues
                                        .map((type) => t(type))
                                        .join(' · '),
                                },
                            )}
                            confirmLabel={t('Accept as normal')}
                            onConfirm={() =>
                                confirmVisit((callbacks) => {
                                    requestedRun.request();
                                    router.post(
                                        acceptDns.url({
                                            site: page.site.id,
                                            check: page.check.id,
                                        }),
                                        {
                                            accepted_run_id:
                                                history.current_run_id,
                                        },
                                        {
                                            ...callbacks,
                                            onSuccess: (visit) => {
                                                requestedRun.accept(visit);
                                                callbacks.onSuccess();
                                            },
                                            onHttpException: () => {
                                                requestedRun.abandon();
                                                callbacks.onHttpException();
                                            },
                                            onNetworkError: () => {
                                                requestedRun.abandon();
                                                callbacks.onNetworkError();
                                            },
                                            onError: (errors) => {
                                                requestedRun.abandon();
                                                callbacks.onError();
                                                // The server race ("A check is running right now…") arrives here.
                                                toast.error(
                                                    Object.values(errors).join(
                                                        ' · ',
                                                    ),
                                                );
                                                router.reload({
                                                    only: [
                                                        'history',
                                                        'check',
                                                        'detail',
                                                        'runs',
                                                    ],
                                                });
                                            },
                                        },
                                    );
                                })
                            }
                        />
                    </CardAction>
                </CardHeader>
                <CardContent className="space-y-4 text-sm">
                    {page.check.pending_recheck ? (
                        <p role="status" className="text-muted-foreground">
                            {t(
                                activeRun
                                    ? 'Accepted · checking now…'
                                    : 'Accepted; waiting for the new DNS check',
                            )}
                        </p>
                    ) : canAccept && activeRun ? (
                        <p role="status" className="text-muted-foreground">
                            {t(
                                'A check is running. You can accept the records when it has finished.',
                            )}
                        </p>
                    ) : stillDiffers ? (
                        <p role="status" className="text-muted-foreground">
                            {t(
                                'Still differs from the accepted records: {{reasons}}',
                                { reasons },
                            )}
                        </p>
                    ) : !history.accept_would_change_baseline &&
                      unfixableIssues.length > 0 ? (
                        <p role="status" className="text-muted-foreground">
                            {unfixableIssues.map(issueGuidance).join(' ')}
                        </p>
                    ) : history.accepted_by &&
                      page.check.result === 'succeeded' ? (
                        <p role="status" className="text-muted-foreground">
                            {t('Accepted as normal by {{name}}', {
                                name: history.accepted_by,
                            })}
                            {history.accepted_at && (
                                <>
                                    {' '}
                                    ·{' '}
                                    <MonitoringTime
                                        value={history.accepted_at}
                                    />
                                </>
                            )}
                        </p>
                    ) : null}
                    {history.records_truncated && (
                        <Alert tone="warning">
                            <AlertTitle>
                                {t(
                                    'DNS records truncated; comparison is incomplete',
                                )}
                            </AlertTitle>
                        </Alert>
                    )}
                    {history.current_run_id === null ? (
                        <p className="text-muted-foreground">
                            {t('Awaiting first DNS observation')}
                        </p>
                    ) : (
                        <>
                            <SectionHeading level={3}>
                                {t('Changes from accepted records')}
                            </SectionHeading>
                            {changes.length ? (
                                <div className="space-y-3 font-mono text-xs">
                                    {changes.map(([type, difference]) => (
                                        <div
                                            key={type}
                                            className="rounded-md border p-3"
                                        >
                                            <strong>{type}</strong>
                                            {difference.removed.map(
                                                (record, index) => (
                                                    <p
                                                        key={`removed-${index}`}
                                                        className="break-all text-[var(--status-down)]"
                                                    >
                                                        −{' '}
                                                        {Object.entries(record)
                                                            .map(
                                                                ([
                                                                    name,
                                                                    value,
                                                                ]) =>
                                                                    `${name}: ${value}`,
                                                            )
                                                            .join(' · ')}
                                                    </p>
                                                ),
                                            )}
                                            {difference.added.map(
                                                (record, index) => (
                                                    <p
                                                        key={`added-${index}`}
                                                        className="break-all text-[var(--status-ok)]"
                                                    >
                                                        +{' '}
                                                        {Object.entries(record)
                                                            .map(
                                                                ([
                                                                    name,
                                                                    value,
                                                                ]) =>
                                                                    `${name}: ${value}`,
                                                            )
                                                            .join(' · ')}
                                                    </p>
                                                ),
                                            )}
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <p className="text-muted-foreground">
                                    {t(
                                        'No record differences from accepted baseline',
                                    )}
                                </p>
                            )}
                            <Disclosure
                                className="rounded-md border p-3"
                                triggerClassName="font-medium"
                                summary={t(
                                    'Compare accepted and current records',
                                )}
                            >
                                <div className="mt-3 grid gap-4 lg:grid-cols-2">
                                    <div>
                                        <SectionHeading
                                            level={4}
                                            className="mb-2"
                                        >
                                            {t('Accepted records')}
                                        </SectionHeading>
                                        <Records
                                            records={history.accepted_records}
                                        />
                                    </div>
                                    <div>
                                        <SectionHeading
                                            level={4}
                                            className="mb-2"
                                        >
                                            {t('Current records')}
                                        </SectionHeading>
                                        <Records
                                            records={history.current_records}
                                        />
                                    </div>
                                </div>
                            </Disclosure>
                        </>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}
