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
    ServerFacts,
    ServerGaugeRow,
    ServerStateBadge,
} from '@/components/server-values';
import { ShownOnceSecret } from '@/components/shown-once-secret';
import { TextLink } from '@/components/text-link';
import { Alert, AlertDescription } from '@/components/ui/alert';
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
import { destroy, index } from '@/routes/servers';
import { rotate } from '@/routes/servers/token';

type Install = Heptapod.Addons.Servers.Data.ServerPageData['install'];

/** The one-line install command with the token, shown once, and the step-by-step variant that checks the download first. */
function InstallCommand({
    install,
    token,
}: {
    install: Install;
    token: string;
}) {
    const { t } = useTranslation();
    const [copied, copy] = useClipboard();
    const [failed, setFailed] = useState(false);
    const copyNoteId = useId();
    const options = `--endpoint ${install.endpoint} --version ${install.version} --token ${token}`;
    const command = `curl -fsSL ${install.release_url}/install.sh | sudo sh -s -- ${options}`;
    const verified = [
        `curl -fsSLO ${install.release_url}/install.sh`,
        `curl -fsSLO ${install.release_url}/SHA256SUMS`,
        'sha256sum --check --ignore-missing SHA256SUMS',
        `sudo sh install.sh ${options}`,
    ].join('\n');
    return (
        <div className="space-y-4">
            <ShownOnceSecret
                label={t('servers.install.command')}
                value={command}
            />
            <div className="space-y-1.5">
                <Button
                    variant="outline"
                    aria-describedby={failed ? copyNoteId : undefined}
                    onClick={async () => setFailed(!(await copy(command)))}
                >
                    <Copy className="size-4" />
                    {t(copied ? 'Copied' : 'Copy')}
                </Button>
                <ActionNoteSlot id={copyNoteId}>
                    {failed && (
                        <InputError
                            message={t('servers.install.copy_failed')}
                        />
                    )}
                </ActionNoteSlot>
            </div>
            <Disclosure
                summary={t('servers.install.verify_summary')}
                triggerClassName="text-sm font-medium"
                contentClassName="pt-2"
            >
                <pre className="overflow-x-auto rounded-md border bg-muted p-3 font-mono text-xs break-all whitespace-pre-wrap">
                    {verified}
                </pre>
            </Disclosure>
        </div>
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
                {!install.is_release && (
                    <Alert tone="warning">
                        <AlertDescription>
                            {t('servers.install.development_build', {
                                version: install.version,
                            })}
                        </AlertDescription>
                    </Alert>
                )}
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
    thresholds,
    history,
    install,
}: Heptapod.Addons.Servers.Data.ServerPageData) {
    const { t } = useTranslation();
    const { flash } = usePage();
    const flashedToken = (flash as { server_token?: string }).server_token;
    const awaiting = server.state === 'awaiting';
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
    // The values refresh in place; the 14-day chart reloads only after a new report arrived.
    usePoll(15_000, { only: ['server'] });
    const lastReportAt = useRef(server.last_report_at);
    useEffect(() => {
        if (lastReportAt.current === server.last_report_at) return;
        lastReportAt.current = server.last_report_at;
        router.reload({ only: ['history'] });
    }, [server.last_report_at]);
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
                    metadata={
                        <div className="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                            <ServerStateBadge server={server} />
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
                    secondaryActionCount={2}
                    secondaryActions={
                        <>
                            <PageHeaderAction
                                variant="outline"
                                onClick={() => setConfirming('rotate')}
                            >
                                {t('servers.rotate.action')}
                            </PageHeaderAction>
                            <PageHeaderAction
                                variant="outline"
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
                                thresholds={thresholds}
                            />
                        )}
                        <ServerHistoryCard
                            history={history}
                            values={server.values}
                            thresholds={thresholds}
                        />
                    </>
                )}
            </PageContainer>
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
                description={t('servers.remove.description', {
                    name: server.name,
                })}
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
