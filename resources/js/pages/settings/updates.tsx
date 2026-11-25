import { ActionNoteSlot } from '@/components/action-note-slot';
import { TooltipHint } from '@/components/truncated-text';
import { Disclosure } from '@/components/ui/disclosure';
import InputError from '@/components/input-error';
import { PageContainer } from '@/components/page-container';
import { AbsoluteTime } from '@/components/absolute-time';
import { SectionHeading } from '@/components/section-heading';
import { CooldownReason, useCooldown } from '@/components/cooldown-reason';
import { RunStatus } from '@/components/run-family';
import { FormSection } from '@/components/form-section';
import { PageHeader } from '@/components/page-header';
import { HttpError } from '@inertiajs/core';
import { Head, usePage } from '@inertiajs/react';
import { toast } from 'sonner';
import {
    httpErrorMessage,
    httpErrorRetryAfter,
    httpErrorStatus,
    useHttpRequest,
} from '@/hooks/use-http-request';
import { onLiveSignal } from '@/lib/live-store';
import { Archive, Copy, ExternalLink } from 'lucide-react';
import { useClipboard } from '@/hooks/use-clipboard';
import { EmptyState } from '@/components/empty-state';
import { useEffect, useId, useState, type ComponentProps } from 'react';
import { useTranslation } from 'react-i18next';
import { InfoTooltip } from '@/components/info-tooltip';
import { ListFooter } from '@/components/list-footer';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    ListHeader,
    listRowPadding,
    tableRowStyle,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import { useFormat } from '@/hooks/use-format';
import {
    data as backupData,
    store as requestBackup,
} from '@/routes/settings/backups';
import { status, update } from '@/routes/settings/updates';

type Page = Heptapod.Data.Ui.UpdatesSettingsPageData;
const upgradeDocsUrl = 'https://heptapod.watch/docs/running-it/updating/';
type Backups = NonNullable<Page['backups']>;
const releaseLabels: Record<string, string> = {
    disabled_by_server: 'Release checks disabled by server',
    disabled_by_user: 'Release checks disabled by Owner',
    not_checked: 'No release check recorded',
    unreachable: 'Release source unavailable',
    invalid_response: 'Release response invalid',
    development: 'Installed version cannot be compared',
    available: 'Update available',
    current: 'No newer release at last successful check',
};

export default function Updates({ updatesPage }: { updatesPage: Page }) {
    const { t } = useTranslation();
    const { auth } = usePage().props;
    const format = useFormat();
    const { number } = format;
    const [page, setPage] = useState(updatesPage);
    const [refreshFailed, setRefreshFailed] = useState(false);
    const [acceptedId, setAcceptedId] = useState<number | null>(null);
    const [cooldownOverrideUntil, setCooldownOverrideUntil] = useState<
        string | null
    >(null);
    const releases = useHttpRequest<Record<string, never>, Page['updates']>();
    const history = useHttpRequest<Record<string, never>, Backups>();
    const backup = useHttpRequest<Record<string, never>, { id: number }>();
    const toggle = useHttpRequest<{ enabled: boolean }, Page['updates']>({
        enabled: page.updates.enabled,
    });
    useEffect(() => {
        setPage(updatesPage);
        setRefreshFailed(false);
    }, [updatesPage]);
    const updates = page.updates;
    const backups = page.backups;
    const acceptedRun =
        backups?.latest_run?.id === acceptedId
            ? backups.latest_run
            : backups?.runs.find((run) => run.id === acceptedId);
    useEffect(() => {
        if (
            acceptedRun?.state === 'complete' ||
            acceptedRun?.state === 'failed'
        ) {
            setAcceptedId(null);
        }
    }, [acceptedRun?.state]);
    const busy =
        releases.processing ||
        history.processing ||
        backup.processing ||
        toggle.processing;
    const active =
        Boolean(backups?.has_active_run) ||
        (acceptedId !== null && acceptedRun === undefined);
    const serverCooldownUntil = backups?.cooldown_until ?? null;
    const backupCooldownUntil =
        cooldownOverrideUntil &&
        (!serverCooldownUntil ||
            Date.parse(cooldownOverrideUntil) > Date.parse(serverCooldownUntil))
            ? cooldownOverrideUntil
            : serverCooldownUntil;
    const {
        active: backupInCooldown,
        remainingSeconds: backupCooldownSeconds,
    } = useCooldown(backupCooldownUntil);
    const backupNoteId = useId();
    const backupStandingNote =
        backupInCooldown && !active ? (
            <CooldownReason
                until={backupCooldownUntil}
                remainingSeconds={backupCooldownSeconds}
                messageKey="errors.backup.cooldown_reason"
            />
        ) : acceptedId !== null ? (
            <p role="status" className="text-foreground">
                {t(
                    'Backup request :id accepted. Read the recorded run state; acceptance does not prove completion.',
                    { id: acceptedId },
                )}
            </p>
        ) : null;
    const timestamp = (value: string | null) =>
        value === null ? t('Unknown') : <AbsoluteTime value={value} />;
    const refresh = async () => {
        try {
            const query = window.location.search;
            const [nextUpdates, nextBackups] = await Promise.all([
                releases.get(status.url()),
                backups === null
                    ? Promise.resolve(null)
                    : history.get(`${backupData.url()}${query}`),
            ]);
            setPage({ updates: nextUpdates, backups: nextBackups });
            setRefreshFailed(false);
        } catch (error) {
            if (!(error instanceof HttpError)) throw error;
            // Failed reads leave the previous observation visible, explicitly unverified.
            setRefreshFailed(true);
        }
    };
    // A backup's state changes (queued, running, finished) arrive live; only the backup list is read again.
    const backupsShown = backups !== null;
    useEffect(() => {
        if (!backupsShown) return;
        const stop = onLiveSignal((signal) => {
            if (signal.topic !== 'backup.changed') return;
            void history.get(backupData.url()).then(
                (next) => setPage((current) => ({ ...current, backups: next })),
                (error: unknown) => {
                    if (!(error instanceof HttpError)) throw error;
                    setRefreshFailed(true);
                },
            );
        });
        return () => {
            stop();
        };
    }, [backupsShown]);
    const backUpNow = async () => {
        try {
            const receipt = await backup.post(requestBackup.url());
            setAcceptedId(receipt.id);
            await refresh();
        } catch (error) {
            if (!(error instanceof HttpError)) throw error;
            const code = httpErrorStatus(error);
            if (code === 429) {
                const retryAfter = httpErrorRetryAfter(error);
                if (retryAfter !== null) {
                    setCooldownOverrideUntil(
                        new Date(Date.now() + retryAfter * 1000).toISOString(),
                    );
                }
            } else {
                setRefreshFailed(true);
            }
            toast.error(httpErrorMessage(error, t, 'errors.backup.unknown'));
        }
    };
    const toggleChecks = async () => {
        toggle.transform(() => ({ enabled: !updates.enabled }));
        try {
            const next = await toggle.patch(update.url());
            setPage((current) => ({ ...current, updates: next }));
        } catch (error) {
            if (!(error instanceof HttpError)) throw error;
            toast.error(
                httpErrorMessage(
                    error,
                    t,
                    'Could not change release checks. Refresh to verify the current setting.',
                ),
            );
        }
    };
    const latestRun = backups?.latest_run ?? null;
    const runStates: Record<string, ComponentProps<typeof RunStatus>> = {
        complete: { phase: 'finished', outcome: 'ok' },
        failed: { phase: 'finished', outcome: 'failed' },
        queued: { phase: 'queued' },
        running: { phase: 'running' },
    };
    const runState = (state: string) => {
        const result = runStates[state];
        if (result === undefined)
            throw new Error('Unknown backup state: ' + state);
        return result;
    };
    const retentionLabels: Record<string, string> = {
        keep_all_backups_for_days: 'Keep all backups for :count days',
        keep_daily_backups_for_days: 'Keep daily backups for :count days',
        keep_weekly_backups_for_weeks: 'Keep weekly backups for :count weeks',
        keep_monthly_backups_for_months:
            'Keep monthly backups for :count months',
        keep_yearly_backups_for_years: 'Keep yearly backups for :count years',
    };
    return (
        <PageContainer aria-busy={busy}>
            <Head title={t('Updates & backups')} />
            <PageHeader
                title={t('Updates & backups')}
                description={t('pages.updates.description')}
                notices={
                    <>
                        {refreshFailed && (
                            <Alert tone="warning">
                                <AlertTitle>
                                    {t(
                                        'Could not refresh updates and backups.',
                                    )}
                                </AlertTitle>
                                <AlertDescription>
                                    {t(
                                        'Previous observations are shown; refresh before requesting a backup.',
                                    )}
                                </AlertDescription>
                            </Alert>
                        )}
                        {latestRun?.state === 'failed' && (
                            <Alert tone="danger">
                                <AlertTitle>
                                    {t('Last recorded backup failed.')}
                                </AlertTitle>
                                <TooltipHint
                                    content={
                                        latestRun.completed_at === null
                                            ? undefined
                                            : format.exact(
                                                  latestRun.completed_at,
                                              )
                                    }
                                >
                                    <AlertDescription className="font-mono">
                                        {t('Failure recorded at :time', {
                                            time:
                                                latestRun.completed_at === null
                                                    ? t('Unknown')
                                                    : format.timestamp(
                                                          latestRun.completed_at,
                                                      ),
                                        })}
                                        {latestRun.http_status !== null &&
                                            ` · ${t('HTTP :status', { status: latestRun.http_status })}`}
                                        <p>
                                            {t(
                                                'Verify a fresh backup and recovery plan before upgrading.',
                                            )}
                                        </p>
                                    </AlertDescription>
                                </TooltipHint>
                            </Alert>
                        )}
                    </>
                }
                refresh={{
                    onRefresh: () => void refresh(),
                    refreshing: busy,
                }}
            />
            <FormSection title={t('Version')} description="">
                <div className="flex flex-wrap items-center gap-3">
                    <span className="font-mono text-stat break-all tabular-nums">
                        {updates.installed_version === 'unknown'
                            ? t('Unknown')
                            : updates.installed_version}
                    </span>
                    <span className="text-cell">
                        {t(releaseLabels[updates.update_status] ?? 'Unknown')}
                        {updates.update_status === 'available' &&
                            updates.latest_version !== null &&
                            ` · ${updates.latest_version}`}
                        {updates.update_status === 'available' &&
                            updates.published_at !== null && (
                                <>
                                    {' · '}
                                    {t('Published')}{' '}
                                    <AbsoluteTime
                                        value={updates.published_at}
                                    />
                                </>
                            )}
                    </span>
                </div>
                {updates.update_status === 'available' &&
                    updates.latest_version !== null && (
                        <div className="space-y-2">
                            <p className="text-cell">
                                {t(
                                    'Back up first, then run the command for your installation in its directory.',
                                )}
                            </p>
                            <UpgradeCommand
                                label={t('One-line installer')}
                                command={`sudo ./scripts/upgrade.sh --to ${updates.latest_version}`}
                            />
                            <UpgradeCommand
                                label={t('Git checkout')}
                                command={`git fetch --tags && git checkout ${updates.latest_version} && ./scripts/upgrade.sh`}
                            />
                        </div>
                    )}
                {updates.update_status === 'disabled_by_user' && (
                    <p className="text-cell">
                        {t(
                            'Turn on release checks to see new versions here: once a day Heptapod asks GitHub for the latest release, sending only its version in the user agent.',
                        )}
                    </p>
                )}
                <dl className="grid gap-2 text-xs text-muted-foreground sm:grid-cols-2">
                    <div>
                        <dt>{t('Status evaluated at')}</dt>
                        <dd>{timestamp(updates.evaluated_at)}</dd>
                    </div>
                    <div>
                        <dt>{t('Last release check')}</dt>
                        <dd>
                            {timestamp(updates.checked_at)}
                            {updates.http_status !== null &&
                                ` · ${t('HTTP :status', { status: updates.http_status })}`}
                        </dd>
                    </div>
                    <div>
                        <dt>{t('Last successful release check')}</dt>
                        <dd>{timestamp(updates.last_success_at)}</dd>
                    </div>
                    <div>
                        <dt>{t('Last observed release')}</dt>
                        <dd>
                            {updates.latest_version ?? t('Unknown')}
                            {updates.published_at !== null && (
                                <> · {timestamp(updates.published_at)}</>
                            )}
                        </dd>
                    </div>
                </dl>
                <p className="text-xs leading-relaxed text-muted-foreground">
                    {t(
                        'Refresh reads cached release checks; it does not contact the release source or install an update.',
                    )}
                </p>
                <div className="flex flex-wrap gap-2">
                    {auth.canManageInstance && (
                        <Button
                            variant="outline"
                            disabled={busy || !updates.enabled_by_server}
                            onClick={() => void toggleChecks()}
                        >
                            {t(
                                updates.enabled
                                    ? 'Disable release checks'
                                    : 'Enable release checks',
                            )}
                        </Button>
                    )}
                    {updates.release_url !== null && (
                        <Button variant="outline" asChild>
                            <a
                                href={updates.release_url}
                                target="_blank"
                                rel="noreferrer"
                            >
                                <ExternalLink className="size-3.5" />
                                {updates.update_status === 'available'
                                    ? t("What's new in :version", {
                                          version: updates.latest_version,
                                      })
                                    : t('Last observed release notes')}
                            </a>
                        </Button>
                    )}
                    {updates.update_status === 'available' && (
                        <Button variant="outline" asChild>
                            <a
                                href={upgradeDocsUrl}
                                target="_blank"
                                rel="noreferrer"
                            >
                                <ExternalLink className="size-3.5" />
                                {t('How to upgrade')}
                            </a>
                        </Button>
                    )}
                </div>
            </FormSection>
            <FormSection
                title={t('Backups')}
                description={t('Server configuration is read-only.')}
                detail={t('A backup is only proven once you have restored it.')}
            >
                {backups === null ? (
                    <p className="text-cell text-muted-foreground">
                        {t(
                            'Only Owners can read backup history or request a backup.',
                        )}
                    </p>
                ) : (
                    <>
                        <div className="flex flex-wrap items-center gap-3 text-cell">
                            <span>
                                {t('Configured disk')}:{' '}
                                <span className="font-mono break-all">
                                    {backups.disk}
                                </span>
                            </span>
                            <span>
                                {t(
                                    backups.reachable
                                        ? 'Archive disk reachable'
                                        : 'Archive disk unreachable',
                                )}
                            </span>
                        </div>
                        <TooltipHint
                            content={format.exact(backups.observed_at)}
                        >
                            <p className="font-mono text-xs text-muted-foreground">
                                {t('Observed at :time', {
                                    time: format.timestamp(backups.observed_at),
                                })}{' '}
                                ·{' '}
                                {t('Scheduled daily at :time UTC', {
                                    time: backups.schedule_utc,
                                })}
                            </p>
                        </TooltipHint>
                        <InputError
                            message={
                                !backups.reachable
                                    ? 'Archive inventory unavailable. This does not mean there are no backups.'
                                    : null
                            }
                        />
                        <section
                            aria-label={t('Recorded backup runs')}
                            className="@container/table overflow-hidden rounded-lg border"
                        >
                            {backups.runs.length > 0 && (
                                <ListHeader
                                    aria-hidden="true"
                                    className="grid-cols-[minmax(0,1fr)_8rem_8rem] sm:grid"
                                >
                                    <span>{t('Run requested / finished')}</span>
                                    <span>{t('Disk')}</span>
                                    <span>{t('State')}</span>
                                </ListHeader>
                            )}
                            <ul>
                                {backups.runs.map((run) => (
                                    <li
                                        key={run.id}
                                        className={cn(
                                            listRowPadding,
                                            tableRowStyle,
                                            'grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-2 last:border-b-0 sm:grid-cols-[minmax(0,1fr)_8rem_8rem]',
                                        )}
                                    >
                                        <div className="min-w-0 space-y-1">
                                            <p className="font-mono break-words">
                                                {timestamp(run.requested_at)}
                                            </p>
                                            <p className="text-muted-foreground">
                                                {t(
                                                    run.state === 'failed'
                                                        ? 'Finished'
                                                        : 'Completed',
                                                )}
                                                :{' '}
                                                {run.completed_at === null &&
                                                (run.state === 'queued' ||
                                                    run.state === 'running')
                                                    ? t('Not completed')
                                                    : timestamp(
                                                          run.completed_at,
                                                      )}
                                                {run.http_status !== null &&
                                                    ` · ${t('HTTP :status', { status: run.http_status })}`}
                                            </p>
                                        </div>
                                        <span className="font-mono break-all">
                                            {run.disk ?? t('Unknown')}
                                        </span>
                                        <span className="col-span-2 sm:col-span-1">
                                            <RunStatus
                                                {...runState(run.state)}
                                            />
                                        </span>
                                    </li>
                                ))}
                            </ul>
                            {backups.runs.length === 0 && (
                                <EmptyState
                                    bare
                                    icon={Archive}
                                    title={t('No backup runs recorded.')}
                                    description={t(
                                        'Runs appear here after the first backup is requested.',
                                    )}
                                />
                            )}
                        </section>
                        {backups.runs.length > 0 && (
                            <ListFooter
                                count={t('backups.runs.count', {
                                    count: backups.runs_pagination.total,
                                    formattedCount: number(
                                        backups.runs_pagination.total,
                                    ),
                                })}
                                pagination={backups.runs_pagination}
                                only={['updatesPage']}
                            />
                        )}
                        <p className="text-xs text-muted-foreground">
                            {t(
                                'Queued or running is not complete. One request per five minutes.',
                            )}
                        </p>
                        <div className="space-y-1.5">
                            <div className="flex flex-wrap items-center gap-2">
                                <Button
                                    variant="outline"
                                    aria-describedby={
                                        backupStandingNote
                                            ? backupNoteId
                                            : undefined
                                    }
                                    disabled={
                                        busy ||
                                        !!active ||
                                        backupInCooldown ||
                                        refreshFailed
                                    }
                                    onClick={() => void backUpNow()}
                                >
                                    {t(
                                        backup.processing
                                            ? 'Requesting backup…'
                                            : 'Back up now',
                                    )}
                                </Button>
                            </div>
                            <ActionNoteSlot id={backupNoteId}>
                                {backupStandingNote}
                            </ActionNoteSlot>
                        </div>
                        <section
                            aria-label={t('Archive inventory')}
                            className="space-y-2"
                        >
                            <SectionHeading level={3}>
                                {t('Archive inventory')}
                            </SectionHeading>
                            <p className="text-xs text-muted-foreground">
                                {t(
                                    'Recent archives on the configured disk. Archives are not matched to recorded runs.',
                                )}
                            </p>
                            {backups.reachable &&
                                (backups.archives.length === 0 ? (
                                    <p className="text-cell text-muted-foreground">
                                        {t(
                                            'No archives found on the configured disk.',
                                        )}
                                    </p>
                                ) : (
                                    <ul className="divide-y divide-border-soft">
                                        {backups.archives.map(
                                            (archive, index) => (
                                                <li
                                                    key={`${archive.created_at}-${index}`}
                                                    className={cn(
                                                        'flex flex-wrap justify-between gap-2 font-mono',
                                                        listRowPadding,
                                                        'text-xs',
                                                    )}
                                                >
                                                    <span>
                                                        {timestamp(
                                                            archive.created_at,
                                                        )}
                                                    </span>
                                                    <span>
                                                        {t(':size MB', {
                                                            size: number(
                                                                archive.size_bytes /
                                                                    1024 /
                                                                    1024,
                                                                {
                                                                    maximumFractionDigits: 2,
                                                                },
                                                            ),
                                                        })}
                                                    </span>
                                                </li>
                                            ),
                                        )}
                                    </ul>
                                ))}
                            {backups.reachable &&
                                backups.archives.length > 0 && (
                                    <ListFooter
                                        count={t('backups.archives.count', {
                                            count: backups.archives_pagination
                                                .total,
                                            formattedCount: number(
                                                backups.archives_pagination
                                                    .total,
                                            ),
                                        })}
                                        pagination={backups.archives_pagination}
                                        only={['updatesPage']}
                                    />
                                )}
                        </section>
                        <Disclosure
                            className="text-xs"
                            triggerClassName="text-cell font-medium"
                            summary={t('Retention configuration')}
                        >
                            {backups.uses_default_retention ? (
                                <ul className="mt-2 space-y-1 text-muted-foreground">
                                    {Object.entries(retentionLabels).map(
                                        ([key, label]) => {
                                            const count =
                                                backups.retention[key];
                                            return (
                                                <li key={key}>
                                                    {count == null
                                                        ? t('Unknown')
                                                        : t(label, {
                                                              count,
                                                              formattedCount:
                                                                  number(count),
                                                          })}
                                                </li>
                                            );
                                        },
                                    )}
                                    <li>
                                        {backups.retention
                                            .delete_oldest_backups_when_using_more_megabytes_than ==
                                        null
                                            ? t('No configured storage cap')
                                            : t(
                                                  'Cleanup storage cap: :size MB',
                                                  {
                                                      size: number(
                                                          backups.retention
                                                              .delete_oldest_backups_when_using_more_megabytes_than,
                                                      ),
                                                  },
                                              )}
                                    </li>
                                </ul>
                            ) : (
                                <p className="mt-2 text-muted-foreground">
                                    {t(
                                        'Custom retention strategy. See server configuration.',
                                    )}
                                </p>
                            )}
                        </Disclosure>
                        <p className="text-xs leading-relaxed text-muted-foreground">
                            {t(
                                'Keep encryption keys and archive passwords separately and privately.',
                            )}{' '}
                            <InfoTooltip>
                                {t(
                                    'Offsite copy and restore-test status are not recorded. Disk reachability is not proof of recoverability.',
                                )}
                            </InfoTooltip>
                        </p>
                    </>
                )}
            </FormSection>
            <FormSection
                title={t('Operate and recover')}
                description={t('No upgrade or restore runs in the browser.')}
            >
                <p className="text-cell leading-relaxed text-muted-foreground">
                    {t(
                        'Use the operations runbook from your installed revision.',
                    )}{' '}
                    <InfoTooltip>
                        {t(
                            'Preserve an off-host backup and the required keys before upgrading. Restore on a fresh instance with the same revision.',
                        )}
                    </InfoTooltip>
                </p>
                <code className="block rounded-lg border bg-muted/30 px-4 py-3 text-xs break-all">
                    docs/deploy/operations.md
                </code>
                <p className="text-xs text-muted-foreground">
                    {t(
                        'Read the runbook first, then run sudo ./scripts/upgrade.sh in the installation directory.',
                    )}{' '}
                    <InfoTooltip>
                        {t(
                            'This applies to installations from the one-line installer. In a Git checkout, run git fetch --tags && git checkout <tag> first, then ./scripts/upgrade.sh (add --build only for source-built installs).',
                        )}
                    </InfoTooltip>
                </p>
            </FormSection>
        </PageContainer>
    );
}

/** One upgrade command, selectable and copyable; a failed copy leaves the visible text to select by hand. */
function UpgradeCommand({
    label,
    command,
}: {
    label: string;
    command: string;
}) {
    const { t } = useTranslation();
    const [copied, copy] = useClipboard();
    return (
        <div className="space-y-1">
            <p className="text-xs text-muted-foreground">{label}</p>
            <div className="flex items-start gap-2">
                <code className="block min-w-0 flex-1 rounded-lg border bg-muted/30 px-3 py-2 text-xs break-all">
                    {command}
                </code>
                <Button
                    variant="outline"
                    aria-label={`${t(copied ? 'Copied' : 'Copy')}: ${label}`}
                    onClick={async () => {
                        if (!(await copy(command))) {
                            toast.error(
                                t(
                                    'Copy failed. Select the command and copy it manually.',
                                ),
                            );
                        }
                    }}
                >
                    <Copy className="size-3.5" />
                    {t(copied ? 'Copied' : 'Copy')}
                </Button>
            </div>
        </div>
    );
}
