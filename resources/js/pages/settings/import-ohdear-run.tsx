import { TruncatedText } from '@/components/truncated-text';
import { cn } from '@/lib/utils';
import { ImportChannelSection } from '@/components/import-channel-section';
import { ImportOperationBadge } from '@/components/import-operation-badge';
import { ImportAlertTimingSummary } from '@/components/import-alert-timing-summary';
import { Head, Link, router } from '@inertiajs/react';
import { useState, type ComponentProps } from 'react';
import { useTranslation } from 'react-i18next';
import { FormSection } from '@/components/form-section';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/monitoring';
import { RunStatus } from '@/components/run-family';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { listCardPadding, ListHeader, ListRow } from '@/components/ui/table';
import {
    HttpValidationError,
    httpErrorMessage,
    httpErrorStatus,
    useHttpRequest,
} from '@/hooks/use-http-request';
import { useClipboard } from '@/hooks/use-clipboard';
import { toast } from 'sonner';
import { useLiveReload } from '@/hooks/use-live';
import { useFormat } from '@/hooks/use-format';
import { ListFooter } from '@/components/list-footer';
import { edit as planPage } from '@/routes/settings/import/ohdear';
import { retry as retryRoute } from '@/routes/settings/import/ohdear/runs';

type Row = Heptapod.Data.Import.ImportPlanRowData;
type ImportRun = {
    id: string;
    status: Heptapod.Enums.ImportRunStatus;
    processed: number;
    failed: number;
    failed_remote: number;
    failed_earlier: number;
    expected: number;
    attempts: number;
    rows: Row[];
    rows_pagination: Heptapod.Contracts.PaginationData;
    common_notices: string[];
    alert_timing: { inherited: number; overridden: number };
    channels: Heptapod.Data.Import.ImportChannelRowData[];
    remote_error: string | null;
    error: string | null;
    started_at: string;
    finished_at: string | null;
    can_retry: boolean;
    sections: Heptapod.Enums.ImportSection[];
    cron: {
        site: string;
        site_id: number;
        tasks: { name: string; ping_url: string }[];
    }[];
    cron_pagination: Heptapod.Contracts.PaginationData;
};

/** A planned import has not started yet; from Queued on it is a run (UI rules, Runs). */
const statusRuns = {
    queued: { phase: 'queued' },
    running: { phase: 'running' },
    completed: { phase: 'finished', outcome: 'ok' },
    incomplete: { phase: 'finished', outcome: 'warning' },
    failed: { phase: 'finished', outcome: 'failed' },
} as const satisfies Record<
    Exclude<ImportRun['status'], 'planned'>,
    ComponentProps<typeof RunStatus>
>;

function CopyUrl({ url }: { url: string }) {
    const { t } = useTranslation();
    const [, copy] = useClipboard();
    const [copied, setCopied] = useState(false);
    return (
        <div className="flex min-w-0 items-center gap-2">
            <TruncatedText content={url}>
                <code className="min-w-0 flex-1 truncate font-mono text-xs select-all">
                    {url}
                </code>
            </TruncatedText>
            <Button
                type="button"
                variant="outline"
                onClick={async () => {
                    if (await copy(url)) {
                        setCopied(true);
                    } else {
                        toast.warning(t('errors.clipboard_unavailable'));
                    }
                }}
            >
                {t(copied ? 'Copied' : 'Copy')}
            </Button>
        </div>
    );
}

export default function ImportOhDearRun({
    importRun,
}: {
    importRun: ImportRun;
}) {
    const { t } = useTranslation();
    const format = useFormat();
    const includesSites = importRun.sections.includes('sites');
    const active = ['queued', 'running'].includes(importRun.status);
    useLiveReload(
        ['import.progress', 'import.changed'],
        ['importRun'],
        undefined,
        active,
    );
    const retry = useHttpRequest<Record<string, never>, { url: string }>();
    const retryImport = async () => {
        try {
            await retry.post(retryRoute.url(importRun.id));
        } catch (error: unknown) {
            if (error instanceof HttpValidationError) return;
            toast.warning(httpErrorMessage(error, t));
            if (httpErrorStatus(error) !== 409) return;
        }
        router.reload({ only: ['importRun'] });
    };
    const done = importRun.processed;
    const unread = Math.max(0, importRun.expected - done);
    const finished = !active;
    const common = importRun.common_notices;
    const rowNotices = (row: Row) => [
        ...row.notices
            .filter((key) => !common.includes(key))
            .map((key) => t(key))
            .sort((a, b) => a.localeCompare(b)),
        // A failed row keeps its server-written reason (results stored before it was kept have none).
        ...(row.operation === 'failed' && row.details ? [row.details] : []),
    ];

    return (
        <>
            <Head title={t(finished ? 'Import result' : 'Import progress')} />
            <PageHeader
                title={t(finished ? 'Import result' : 'Import progress')}
                description={t('pages.import_run.description')}
                badge={
                    importRun.status === 'planned' ? (
                        <StatusBadge state="pending" label={t('Plan')} />
                    ) : (
                        <RunStatus {...statusRuns[importRun.status]} />
                    )
                }
                notices={
                    (importRun.status === 'incomplete' ||
                        importRun.status === 'failed') && (
                        <Alert
                            tone="danger"
                            action={
                                <>
                                    {importRun.can_retry &&
                                        importRun.error !== 'token_expired' &&
                                        importRun.error !== 'plan_changed' &&
                                        importRun.error !==
                                            'access_revoked' && (
                                            <Button
                                                type="button"
                                                disabled={retry.processing}
                                                onClick={() =>
                                                    void retryImport()
                                                }
                                            >
                                                {t('Run again')}
                                            </Button>
                                        )}
                                    <Button asChild variant="outline">
                                        <Link href={planPage()}>
                                            {t('Back to the plan')}
                                        </Link>
                                    </Button>
                                </>
                            }
                        >
                            <AlertDescription>
                                {importRun.error === 'token_expired'
                                    ? t(
                                          'The Oh Dear token expired before the import started. Start again from the plan.',
                                      )
                                    : importRun.error === 'plan_changed'
                                      ? t(
                                            'The checks this server can run changed since the plan, so this attempt stopped before importing anything. Show the plan again.',
                                        )
                                      : importRun.error === 'access_revoked'
                                        ? t(
                                              'The import stopped because the person who started it no longer has access to imports. Sites already imported were kept. Start again from a new plan.',
                                          )
                                        : importRun.error === 'local_error'
                                          ? t(
                                                'The import stopped because of an error on this server, not at Oh Dear. Sites already imported were kept. The server log has the details (import run :run).',
                                                { run: importRun.id },
                                            )
                                          : importRun.status === 'incomplete'
                                            ? [
                                                  ...(importRun.failed_remote >
                                                  0
                                                      ? [
                                                            t(
                                                                ':count sites failed: Oh Dear did not answer or answered with an error, so they were not fully imported.',
                                                                {
                                                                    count: importRun.failed_remote,
                                                                },
                                                            ),
                                                        ]
                                                      : []),
                                                  ...(importRun.failed >
                                                  importRun.failed_remote
                                                      ? [
                                                            t(
                                                                ':count sites could not be imported. The reason is listed next to each site.',
                                                                {
                                                                    count:
                                                                        importRun.failed -
                                                                        importRun.failed_remote,
                                                                },
                                                            ),
                                                        ]
                                                      : []),
                                                  ...(importRun.failed_earlier >
                                                  0
                                                      ? [
                                                            t(
                                                                ':count sites failed in an earlier attempt and were not read again.',
                                                                {
                                                                    count: importRun.failed_earlier,
                                                                },
                                                            ),
                                                        ]
                                                      : []),
                                                  ...(unread > 0
                                                      ? [
                                                            t(
                                                                ':count sites were not read because Oh Dear stopped answering or answered with an error.',
                                                                {
                                                                    count: unread,
                                                                },
                                                            ),
                                                        ]
                                                      : []),
                                                  t(
                                                      'The other sites below were committed. Run again to continue: sites already imported are updated, not duplicated.',
                                                  ),
                                              ].join(' ')
                                            : t(
                                                  'The import could not finish. Nothing after the last listed site was imported.',
                                              )}
                            </AlertDescription>
                            {importRun.remote_error && (
                                <AlertDescription className="font-mono text-xs">
                                    {importRun.remote_error}
                                </AlertDescription>
                            )}
                            <InputError
                                message={Object.values(retry.errors)[0]}
                            />
                        </Alert>
                    )
                }
            />
            <section className="space-y-3 py-6" aria-live="polite">
                <p className="text-sm text-muted-foreground">
                    {t('import.sections')}
                    {': '}
                    {importRun.sections
                        .map((section) => t(`import.section.${section}`))
                        .join(', ')}
                </p>
                <p className="text-sm text-muted-foreground">
                    {[
                        ...(importRun.expected > 0
                            ? [
                                  t(':done of :total sites processed', {
                                      done,
                                      total: Math.max(importRun.expected, done),
                                  }),
                              ]
                            : []),
                        ...(importRun.attempts > 1
                            ? [
                                  t('Attempt :count', {
                                      count: importRun.attempts,
                                  }),
                              ]
                            : []),
                        ...(importRun.status === 'completed'
                            ? [t('The token was deleted after the import.')]
                            : []),
                    ].join(' · ')}
                </p>
                {importRun.expected > 0 && (
                    <div
                        role="progressbar"
                        aria-valuemin={0}
                        aria-valuemax={Math.max(importRun.expected, done)}
                        aria-valuenow={done}
                        className="h-1.5 overflow-hidden rounded-full bg-muted"
                    >
                        <div
                            className="h-full w-full origin-left bg-primary transition-transform duration-(--motion-enter) ease-out"
                            style={{
                                transform: `scaleX(${Math.min(1, done / Math.max(importRun.expected, 1))})`,
                            }}
                        />
                    </div>
                )}
                {common.length > 0 && (
                    <Alert tone="info">
                        <AlertTitle>
                            {t('Not imported for any site')}
                        </AlertTitle>
                        <AlertDescription>
                            <ul className="list-disc space-y-1 pl-4 text-xs">
                                {common
                                    .map((key) => t(key))
                                    .sort((a, b) => a.localeCompare(b))
                                    .map((label) => (
                                        <li key={label}>{label}</li>
                                    ))}
                            </ul>
                        </AlertDescription>
                    </Alert>
                )}
                <ImportAlertTimingSummary counts={importRun.alert_timing} />
                {importRun.rows.length > 0 && (
                    <div className="@container/table overflow-hidden rounded-[10px] border">
                        <ListHeader className="@3xl/table:grid @3xl/table:grid-cols-[minmax(0,16rem)_minmax(0,1fr)]">
                            <span>{t('Site')}</span>
                            <span>{t('Not imported')}</span>
                        </ListHeader>
                        {importRun.rows.map((row) => (
                            <ListRow
                                key={row.source_id}
                                className="items-start @3xl/table:grid-cols-[minmax(0,16rem)_minmax(0,1fr)]"
                            >
                                <div className="min-w-0 space-y-1">
                                    <TruncatedText
                                        label={t('Site')}
                                        content={row.label ?? row.url}
                                    >
                                        <p className="truncate font-medium">
                                            {row.label ?? row.url}
                                        </p>
                                    </TruncatedText>
                                    <TruncatedText
                                        label={t('URL')}
                                        content={row.url}
                                    >
                                        <p className="truncate font-mono text-xs text-muted-foreground">
                                            {row.url}
                                        </p>
                                    </TruncatedText>
                                    {includesSites && row.client_name && (
                                        <p className="text-xs text-muted-foreground">
                                            {row.operation === 'update'
                                                ? t(
                                                      'Already exists, client unchanged',
                                                  )
                                                : t('Client')}
                                            :{' '}
                                            <span className="text-foreground">
                                                {row.client_name}
                                            </span>
                                        </p>
                                    )}
                                    <ImportOperationBadge
                                        operation={row.operation}
                                        label={
                                            row.operation === 'skipped'
                                                ? t(
                                                      row.notices[0] ??
                                                          'import.notice.other',
                                                  )
                                                : t(
                                                      row.operation ===
                                                          'update' &&
                                                          !includesSites
                                                          ? 'import.update_sections'
                                                          : `import.${row.operation}`,
                                                  )
                                        }
                                    />
                                </div>
                                {row.operation === 'skipped' ||
                                rowNotices(row).length === 0 ? (
                                    <span className="text-muted-foreground">
                                        —
                                    </span>
                                ) : (
                                    <ul className="list-disc space-y-1 pl-4 text-xs">
                                        {rowNotices(row).map((label) => (
                                            <li key={label}>{label}</li>
                                        ))}
                                    </ul>
                                )}
                            </ListRow>
                        ))}
                    </div>
                )}
                {importRun.rows.length > 0 && (
                    <ListFooter
                        count={t('import.result.count', {
                            count: importRun.rows_pagination.total,
                            formattedCount: format.number(
                                importRun.rows_pagination.total,
                            ),
                        })}
                        pagination={importRun.rows_pagination}
                        only={['importRun']}
                    />
                )}
            </section>
            {finished &&
                importRun.status !== 'failed' &&
                !importRun.remote_error &&
                importRun.sections.includes('channels') && (
                    <ImportChannelSection rows={importRun.channels} />
                )}
            {finished && importRun.status !== 'failed' && (
                <FormSection
                    title={t('Follow-up checklist')}
                    description={t(
                        'These parts of an Oh Dear account need your action after the import.',
                    )}
                >
                    <ol className="list-decimal space-y-4 pl-4 text-sm">
                        {importRun.sections.includes('cron') && (
                            <li className="space-y-2">
                                <p>
                                    {t(
                                        'Cron tasks: update each task to ping its new Heptapod URL.',
                                    )}
                                </p>
                                {importRun.cron.length === 0 ? (
                                    <p className="text-muted-foreground">
                                        {t('No cron tasks were imported.')}
                                    </p>
                                ) : (
                                    <div className="space-y-3">
                                        {importRun.cron.map((site) => (
                                            <div
                                                key={site.site_id}
                                                className={cn(
                                                    'space-y-2 rounded-[10px] border',
                                                    listCardPadding,
                                                )}
                                            >
                                                <p className="font-medium">
                                                    {site.site}
                                                </p>
                                                {site.tasks.map((task) => (
                                                    <div
                                                        key={task.ping_url}
                                                        className="grid gap-1 @md:grid-cols-[10rem_minmax(0,1fr)]"
                                                    >
                                                        <span className="text-xs text-muted-foreground">
                                                            {task.name}
                                                        </span>
                                                        <CopyUrl
                                                            url={task.ping_url}
                                                        />
                                                    </div>
                                                ))}
                                            </div>
                                        ))}
                                        <ListFooter
                                            count={t(
                                                'import.cron_sites.count',
                                                {
                                                    count: importRun
                                                        .cron_pagination.total,
                                                    formattedCount:
                                                        format.number(
                                                            importRun
                                                                .cron_pagination
                                                                .total,
                                                        ),
                                                },
                                            )}
                                            pagination={
                                                importRun.cron_pagination
                                            }
                                            only={['importRun']}
                                        />
                                    </div>
                                )}
                            </li>
                        )}
                        <li>
                            {t(
                                'Status pages and check history are not imported.',
                            )}
                        </li>
                    </ol>
                </FormSection>
            )}
        </>
    );
}
