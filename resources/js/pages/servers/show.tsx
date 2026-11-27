import { Head, router, usePage, usePoll } from '@inertiajs/react';
import { Copy } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ActionNoteSlot } from '@/components/action-note-slot';
import { ConfirmDialog, confirmVisit } from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { MonitoringTime } from '@/components/monitoring';
import { PageContainer } from '@/components/page-container';
import { PageHeader } from '@/components/page-header';
import { PageHeaderAction } from '@/components/page-header-actions';
import { ServerHistoryCard } from '@/components/server-history-card';
import {
    isServerSnoozed,
    ServerFacts,
    ServerGaugeRow,
    ServerStateBadge,
} from '@/components/server-values';
import { SnoozeActions } from '@/components/check-snooze-actions';
import { AbsoluteTime } from '@/components/absolute-time';
import { ShownOnceSecret } from '@/components/shown-once-secret';
import { TextLink, TextLinkButton } from '@/components/text-link';
import {
    LinkedSitesCard,
    LinkSitesSheet,
    ServerSettingsSheet,
} from '@/components/server-manage';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Disclosure } from '@/components/ui/disclosure';
import { useClipboard } from '@/hooks/use-clipboard';
import { useFormat } from '@/hooks/use-format';
import { destroy, index, snooze, unsnooze } from '@/routes/servers';
import { rotate } from '@/routes/servers/token';
import { agentInstallCommands } from '@/lib/server-agent-install';
import { SectionHeading } from '@/components/section-heading';

type Install = Heptapod.Addons.Servers.Data.ServerPageData['install'];
type ServerIssue = Heptapod.Addons.Servers.Data.ServerIssueData;

/**
 * One open issue as a page notice: what is wrong, its threshold and since when; past issues live in Activity. While
 * the server is snoozed the notice says the alerts wait (the issue stays visible).
 */
function IssueNotice({
    issue,
    snoozedUntil,
}: {
    issue: ServerIssue;
    snoozedUntil: string | null;
}) {
    const { t } = useTranslation();
    const format = useFormat();
    const value = (key: string) => Number(issue.values[key]);
    const percent = (key: string) =>
        format.number(value(key) / 100, {
            style: 'percent',
            maximumFractionDigits: 0,
        });
    const minutes = format.number(value('minutes'));
    const detail =
        issue.kind === 'no_report'
            ? t('servers.issue_notice.no_report', { minutes })
            : issue.kind === 'load_high'
              ? t('servers.issue_notice.load_high', {
                    threshold: format.number(value('threshold'), {
                        minimumFractionDigits: 1,
                        maximumFractionDigits: 2,
                    }),
                    minutes,
                })
              : t('servers.issue_notice.limit', {
                    threshold: percent('threshold'),
                    minutes,
                });
    return (
        <Alert tone={issue.kind === 'no_report' ? 'warning' : 'danger'}>
            <AlertTitle>
                {t(`servers.issue.${issue.kind}`)}
                {issue.scope !== '' && (
                    <span className="font-mono"> {issue.scope}</span>
                )}
            </AlertTitle>
            <AlertDescription>
                {detail} {t('servers.issue_notice.since')}{' '}
                <AbsoluteTime value={issue.opened_at} />.
                {snoozedUntil !== null && (
                    <>
                        {' '}
                        {t('servers.issue_notice.snoozed')}{' '}
                        <AbsoluteTime value={snoozedUntil} />.
                    </>
                )}
            </AlertDescription>
        </Alert>
    );
}
type Release = Install & { version: string; release_url: string };

/** Copies a value; a refused clipboard says so next to the button. */
function CopyButton({ value }: { value: string }) {
    const { t } = useTranslation();
    const [copied, copy] = useClipboard();
    const [failed, setFailed] = useState(false);
    const noteId = useId();
    return (
        <div className="space-y-1.5">
            <Button
                variant="outline"
                aria-describedby={failed ? noteId : undefined}
                onClick={async () => setFailed(!(await copy(value)))}
            >
                <Copy className="size-4" />
                {t(copied === value ? 'Copied' : 'Copy')}
            </Button>
            <ActionNoteSlot id={noteId}>
                {failed && (
                    <InputError message={t('servers.install.copy_failed')} />
                )}
            </ActionNoteSlot>
        </div>
    );
}

/** The agent token, shown once, and the install command; the installer asks for the token on a hidden prompt. */
function InstallCommand({
    install,
    token,
}: {
    install: Install;
    token: string;
}) {
    const { t } = useTranslation();
    return (
        <div className="space-y-4">
            <ShownOnceSecret label={t('servers.install.token')} value={token} />
            <CopyButton value={token} />
            {install.version !== null && install.release_url !== null ? (
                <InstallRelease
                    install={{
                        ...install,
                        version: install.version,
                        release_url: install.release_url,
                    }}
                />
            ) : (
                <Alert tone="warning">
                    <AlertDescription>
                        {t('servers.install.no_release', {
                            version: install.installed_version,
                        })}
                    </AlertDescription>
                </Alert>
            )}
        </div>
    );
}

/** The commands for a known agent release; a source build names the release it falls back to. */
function InstallRelease({ install }: { install: Release }) {
    const { t } = useTranslation();
    const commands = agentInstallCommands(install);
    return (
        <>
            {install.installed_version !== install.version && (
                <p className="text-sm text-muted-foreground">
                    {t('servers.install.latest_release', {
                        installed: install.installed_version,
                        version: install.version,
                    })}
                </p>
            )}
            <div className="space-y-2">
                <SectionHeading level={3}>
                    {t('servers.install.command')}
                </SectionHeading>
                <pre className="overflow-x-auto rounded-md border bg-muted p-3 font-mono text-xs break-all whitespace-pre-wrap">
                    {commands.install}
                </pre>
                <p className="text-xs text-muted-foreground">
                    {t('servers.install.asks_token')}
                </p>
                <CopyButton value={commands.install} />
            </div>
            <Disclosure
                summary={t('servers.install.verify_summary')}
                triggerClassName="text-sm font-medium"
                contentClassName="space-y-2 pt-2"
            >
                <pre className="overflow-x-auto rounded-md border bg-muted p-3 font-mono text-xs break-all whitespace-pre-wrap">
                    {commands.verified}
                </pre>
                <p className="text-xs text-muted-foreground">
                    {t('servers.install.attestation')}{' '}
                    <code className="font-mono break-all">
                        {commands.attestation}
                    </code>
                </p>
            </Disclosure>
        </>
    );
}

/** What "Add server" opens: the agent's install command while the server awaits its first report. */
function InstallAgent({
    name,
    install,
    token,
    onRotate,
}: {
    name: string;
    install: Install;
    token: string | null;
    onRotate: () => void;
}) {
    const { t } = useTranslation();
    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('servers.install.title')}</CardTitle>
                <CardDescription>
                    {t('servers.install.description', { name })}
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                {token === null ? (
                    <div className="space-y-3">
                        <p className="text-sm text-muted-foreground">
                            {t('servers.install.token_gone')}
                        </p>
                        <Button variant="outline" onClick={onRotate}>
                            {t('servers.rotate.action')}
                        </Button>
                    </div>
                ) : (
                    <InstallCommand install={install} token={token} />
                )}
                <ul className="list-disc space-y-1 pl-5 text-sm text-muted-foreground">
                    <li>
                        {t('servers.install.source')}{' '}
                        <TextLink
                            external
                            href="https://github.com/heptapodwatch/heptapod/releases"
                        >
                            {t('servers.install.releases')}
                        </TextLink>
                    </li>
                    <li>{t('servers.install.reach')}</li>
                </ul>
            </CardContent>
        </Card>
    );
}

export default function ServerShow({
    server,
    defaults,
    history,
    install,
    linked_sites,
    linked_sites_pagination,
}: Heptapod.Addons.Servers.Data.ServerPageData) {
    const { t } = useTranslation();
    const format = useFormat();
    const { flash } = usePage();
    const flashedToken = (flash as { server_token?: string }).server_token;
    const awaiting = server.state === 'awaiting';
    const snoozed = isServerSnoozed(server, Date.now());
    // Shown once: held in this page's state, so the polls below keep it, and gone after leaving the page. While the
    // server awaits its first report the install card shows it; a token rotated later opens in a dialog.
    const [token, setToken] = useState<string | null>(flashedToken ?? null);
    const [tokenDialog, setTokenDialog] = useState(
        flashedToken !== undefined && !awaiting,
    );
    useEffect(() => {
        if (flashedToken === undefined) return;
        setToken(flashedToken);
        setTokenDialog(!awaiting);
        // Only a newly flashed token opens the dialog, not a later state change.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [flashedToken]);
    const [confirming, setConfirming] = useState<'rotate' | 'remove' | null>(
        null,
    );
    const [sheet, setSheet] = useState<'settings' | 'link' | null>(null);
    // The values refresh in place. The 14-day chart is the expensive read (a scan of every disk sample): it loads with
    // the page, after the first report, and then at most every 5 minutes.
    usePoll(15_000, {
        only: ['server', 'linked_sites', 'linked_sites_pagination'],
    });
    usePoll(300_000, { only: ['history'] });
    const wasAwaiting = useRef(awaiting);
    useEffect(() => {
        if (wasAwaiting.current && !awaiting)
            router.reload({ only: ['history'] });
        wasAwaiting.current = awaiting;
    }, [awaiting]);
    return (
        <>
            <Head title={server.name} />
            <PageContainer>
                <PageHeader
                    title={server.name}
                    description=""
                    trail={
                        <TextLink href={index.url()}>
                            {t('servers.title')}
                        </TextLink>
                    }
                    notices={
                        server.issues.length > 0 ? (
                            <>
                                {server.issues.map((issue) => (
                                    <IssueNotice
                                        key={issue.id}
                                        issue={issue}
                                        snoozedUntil={
                                            snoozed
                                                ? server.snoozed_until
                                                : null
                                        }
                                    />
                                ))}
                            </>
                        ) : undefined
                    }
                    metadata={
                        <div className="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                            <ServerStateBadge server={server} />
                            {snoozed && server.snoozed_until && (
                                <span className="text-xs text-muted-foreground">
                                    {t('Snoozed until')}{' '}
                                    <AbsoluteTime
                                        value={server.snoozed_until}
                                    />
                                </span>
                            )}
                            {server.last_report_at && (
                                <span className="text-xs text-foreground-faint">
                                    {t('servers.last_report')}{' '}
                                    <MonitoringTime
                                        value={server.last_report_at}
                                        className="font-mono text-xs tabular-nums"
                                    />
                                </span>
                            )}
                            {server.agent_version && (
                                <span className="font-mono text-xs text-foreground-faint tabular-nums">
                                    {t('servers.agent_version', {
                                        version: server.agent_version,
                                    })}
                                </span>
                            )}
                            <Badge variant="outline">
                                {t('servers.experimental')}
                            </Badge>
                        </div>
                    }
                    meta={
                        server.values ? (
                            <ServerFacts values={server.values} />
                        ) : undefined
                    }
                    // The site and check header pattern: one primary, the rest in More actions.
                    primaryAction={
                        <>
                            <SnoozeActions
                                snoozed={snoozed}
                                snoozeUrl={snooze.url(server.id)}
                                unsnoozeUrl={unsnooze.url(server.id)}
                                sheetTitle={t('servers.snooze.title')}
                                sheetDescription={t(
                                    'servers.snooze.description',
                                )}
                            />
                            <Button onClick={() => setSheet('link')}>
                                {t('servers.link.action')}
                            </Button>
                        </>
                    }
                    overflowSecondary="always"
                    secondaryActionCount={3}
                    secondaryActions={
                        <>
                            <PageHeaderAction
                                variant="outline"
                                onClick={() => setSheet('settings')}
                            >
                                {t('servers.settings.action')}
                            </PageHeaderAction>
                            <PageHeaderAction
                                variant="outline"
                                onClick={() => setConfirming('rotate')}
                            >
                                {t('servers.rotate.action')}
                            </PageHeaderAction>
                            <PageHeaderAction
                                variant="destructive"
                                onClick={() => setConfirming('remove')}
                            >
                                {t('servers.remove.action')}
                            </PageHeaderAction>
                        </>
                    }
                />
                {awaiting ? (
                    <InstallAgent
                        name={server.name}
                        install={install}
                        token={token}
                        onRotate={() => setConfirming('rotate')}
                    />
                ) : (
                    <>
                        {server.values && (
                            <ServerGaugeRow
                                values={server.values}
                                thresholds={server.thresholds}
                            />
                        )}
                        {server.ignored_mounts.length > 0 && (
                            <p className="text-xs text-muted-foreground">
                                {t('servers.mounts.hidden', {
                                    mounts: server.ignored_mounts.join(', '),
                                })}{' '}
                                <TextLinkButton
                                    onClick={() => setSheet('settings')}
                                >
                                    {t('servers.mounts.change')}
                                </TextLinkButton>
                            </p>
                        )}
                        <ServerHistoryCard
                            history={history}
                            values={server.values}
                            thresholds={server.thresholds}
                        />
                    </>
                )}
                <LinkedSitesCard
                    server={server}
                    sites={linked_sites}
                    pagination={linked_sites_pagination}
                    onLinkSites={() => setSheet('link')}
                />
            </PageContainer>
            <ServerSettingsSheet
                open={sheet === 'settings'}
                onOpenChange={(open) => setSheet(open ? 'settings' : null)}
                server={server}
                defaults={defaults}
            />
            <LinkSitesSheet
                open={sheet === 'link'}
                onOpenChange={(open) => setSheet(open ? 'link' : null)}
                server={server}
            />
            <ConfirmDialog
                open={confirming === 'rotate'}
                onOpenChange={(open) => !open && setConfirming(null)}
                title={t('servers.rotate.title')}
                description={t('servers.rotate.description', {
                    name: server.name,
                })}
                confirmLabel={t('servers.rotate.confirm')}
                onConfirm={() =>
                    confirmVisit((callbacks) =>
                        router.post(
                            rotate.url(server.id),
                            {},
                            {
                                preserveScroll: true,
                                ...callbacks,
                            },
                        ),
                    )
                }
            />
            <ConfirmDialog
                open={confirming === 'remove'}
                onOpenChange={(open) => !open && setConfirming(null)}
                title={t('servers.remove.title')}
                description={[
                    t('servers.remove.description', { name: server.name }),
                    server.site_count > 0 &&
                        t('servers.remove.unlinks', {
                            count: server.site_count,
                            formattedCount: format.number(server.site_count),
                        }),
                ]
                    .filter(Boolean)
                    .join(' ')}
                confirmLabel={t('servers.remove.confirm')}
                destructive
                onConfirm={() =>
                    confirmVisit((callbacks) =>
                        router.delete(destroy.url(server.id), callbacks),
                    )
                }
            />
            <Dialog open={tokenDialog} onOpenChange={setTokenDialog}>
                <DialogContent className="sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>
                            {t('servers.rotate.done_title')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('servers.rotate.done_description', {
                                name: server.name,
                            })}
                        </DialogDescription>
                    </DialogHeader>
                    {token !== null && (
                        <InstallCommand install={install} token={token} />
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}
