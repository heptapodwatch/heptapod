import { Mail, Paperclip } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { AbsoluteTime } from '@/components/absolute-time';
import { EmptyState } from '@/components/empty-state';
import { ListFooter } from '@/components/list-footer';
import { StatusBadge } from '@/components/monitoring';
import {
    SendToClientSheet,
    type ClientMessageResend,
    type ClientMessageSubject,
} from '@/components/send-to-client-sheet';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { ListRow } from '@/components/ui/table';
import { Spinner } from '@/components/ui/spinner';
import {
    attachment as attachmentUrl,
    index as messagesIndex,
    show as messageShow,
} from '@/routes/clients/messages';
import { RowActions } from '@/components/row-actions';
import { deliveryOutcomeStates } from '@/lib/status';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

type ClientMessageRow = Heptapod.Data.Ui.ClientMessageRowData;

type Page = {
    rows: ClientMessageRow[];
    next_before: number | null;
    can_send: boolean;
};

const stateLabel: Record<Heptapod.Enums.DeliveryOutcome, string> = {
    accepted: 'Accepted',
    rejected: 'client_messages.list.not_delivered',
    failed: 'client_messages.list.not_delivered',
    logged: 'Logged, not delivered',
    suppressed: 'Not sent',
    pending: 'client_messages.list.pending',
    sending: 'client_messages.list.pending',
};

/** "Messages to this client" (design §7.3): what the client was told, when, by whom, where, with the outcome. */
export function ClientMessagesList({
    client,
}: {
    client: { id: number; name: string };
}) {
    const { t } = useTranslation();
    const [rows, setRows] = useState<ClientMessageRow[] | null>(null);
    const [next, setNext] = useState<number | null>(null);
    const [canSend, setCanSend] = useState(false);
    const [failed, setFailed] = useState(false);
    const [opened, setOpened] = useState<ClientMessageRow | null>(null);
    const [resend, setResend] = useState<{
        subject: ClientMessageSubject;
        draft: ClientMessageResend;
        design: Heptapod.Contracts.Mail.MailDesign;
    } | null>(null);

    const load = useCallback(
        async (before: number | null) => {
            setFailed(false);
            try {
                const response = await fetch(
                    messagesIndex.url(client.id, {
                        query: before === null ? {} : { before },
                    }),
                    {
                        headers: { Accept: 'application/json' },
                        credentials: 'same-origin',
                    },
                );
                if (!response.ok)
                    throw new Error(
                        `Client messages failed: ${response.status}`,
                    );
                const page = (await response.json()) as Page;
                setRows((current) =>
                    before === null
                        ? page.rows
                        : [...(current ?? []), ...page.rows],
                );
                setNext(page.next_before);
                setCanSend(page.can_send);
            } catch (error) {
                console.error(error);
                setFailed(true);
            }
        },
        [client.id],
    );
    useEffect(() => {
        void load(null);
    }, [load]);

    const resendRow = (row: ClientMessageRow) => {
        const source = row.resend;
        if (!source) return;
        setResend({
            subject:
                source.kind === 'incident'
                    ? { kind: 'incident', incident_id: source.incident_id ?? 0 }
                    : { kind: 'findings', check_id: source.check_id ?? 0 },
            draft: {
                note: source.note,
                attach: source.attach,
                one_off: source.one_off,
            },
            design: source.design,
        });
    };

    if (failed && rows === null)
        return <InputError message="client_messages.list.load_failed" />;
    if (rows === null) return <Spinner />;
    if (rows.length === 0)
        return (
            <EmptyState
                icon={Mail}
                title={t('client_messages.list.empty_title')}
                description={t('client_messages.list.empty_description')}
            />
        );
    return (
        <div className="space-y-3">
            <div className="overflow-hidden rounded-lg border">
                {rows.map((row) => (
                    <ListRow
                        key={row.id}
                        className="sm:grid sm:grid-cols-[10rem_minmax(0,1fr)_auto] sm:items-start sm:gap-3"
                    >
                        <AbsoluteTime
                            value={row.at}
                            label={t('client_messages.list.when')}
                            className="text-xs text-muted-foreground"
                        />
                        <div className="min-w-0 space-y-0.5">
                            <p className="text-cell break-words">
                                {row.subject}
                            </p>
                            <p className="flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
                                <span>
                                    {row.origin === 'manual'
                                        ? t('client_messages.list.sent_by', {
                                              name: row.actor ?? '—',
                                          })
                                        : t('client_messages.list.automatic')}
                                </span>
                                <span aria-hidden>·</span>
                                <span>{row.channel}</span>
                                {row.address && (
                                    <span className="font-mono break-all">
                                        {row.address}
                                    </span>
                                )}
                                {row.attachment && (
                                    <span className="inline-flex items-center gap-1">
                                        <Paperclip
                                            aria-hidden
                                            className="size-3"
                                        />
                                        CSV
                                    </span>
                                )}
                            </p>
                            {row.reason && (
                                <p className="text-xs break-words text-muted-foreground">
                                    {row.reason}
                                </p>
                            )}
                        </div>
                        <div className="flex flex-wrap items-center gap-2 sm:justify-end">
                            <StatusBadge
                                state={deliveryOutcomeStates[row.state]}
                                label={stateLabel[row.state]}
                            />
                            {row.kind !== 'alert' && (
                                <RowActions
                                    label={row.subject}
                                    actions={[
                                        {
                                            label: t(
                                                'client_messages.list.open',
                                            ),
                                            onSelect: () => setOpened(row),
                                        },
                                        ...(row.attachment
                                            ? [
                                                  {
                                                      label: t('Download CSV'),
                                                      onSelect: () =>
                                                          window.location.assign(
                                                              attachmentUrl.url(
                                                                  {
                                                                      client: client.id,
                                                                      message:
                                                                          row.event_id,
                                                                  },
                                                              ),
                                                          ),
                                                  },
                                              ]
                                            : []),
                                        ...(canSend && row.resend
                                            ? [
                                                  {
                                                      label: t(
                                                          'client_messages.list.send_again',
                                                      ),
                                                      onSelect: () =>
                                                          resendRow(row),
                                                  },
                                              ]
                                            : []),
                                    ]}
                                />
                            )}
                        </div>
                    </ListRow>
                ))}
            </div>
            <ListFooter
                count={t('client_messages.list.count', {
                    count: rows.length,
                })}
                extra={
                    next !== null ? (
                        <Button
                            variant="outline"
                            onClick={() => void load(next)}
                        >
                            {t('client_messages.list.older')}
                        </Button>
                    ) : undefined
                }
            />
            {opened && (
                <SentMessageDialog
                    clientId={client.id}
                    row={opened}
                    onClose={() => setOpened(null)}
                />
            )}
            {resend && (
                <SendToClientSheet
                    open
                    onOpenChange={(open) => {
                        if (!open) {
                            setResend(null);
                            void load(null);
                        }
                    }}
                    client={client}
                    subject={resend.subject}
                    resend={resend.draft}
                    initialDesign={resend.design}
                />
            )}
        </div>
    );
}

/** The message as the client received it: the frozen subject and body, read-only (a centred Dialog is for read-only previews). */
function SentMessageDialog({
    clientId,
    row,
    onClose,
}: {
    clientId: number;
    row: ClientMessageRow;
    onClose: () => void;
}) {
    const { t } = useTranslation();
    const [message, setMessage] = useState<{
        subject: string;
        html: string;
    } | null>(null);
    const [failed, setFailed] = useState(false);
    useEffect(() => {
        const controller = new AbortController();
        void fetch(
            messageShow.url({ client: clientId, message: row.event_id }),
            {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: controller.signal,
            },
        )
            .then(async (response) => {
                if (!response.ok)
                    throw new Error(
                        `Client message failed: ${response.status}`,
                    );
                setMessage(
                    (await response.json()) as {
                        subject: string;
                        html: string;
                    },
                );
            })
            .catch((error: unknown) => {
                if (controller.signal.aborted) return;
                console.error(error);
                setFailed(true);
            });
        return () => controller.abort();
    }, [clientId, row.event_id]);
    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>
                        {t('client_messages.list.sent_title')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('client_messages.list.sent_description')}
                    </DialogDescription>
                </DialogHeader>
                {failed ? (
                    <InputError message="client_messages.list.open_failed" />
                ) : message === null ? (
                    <Spinner />
                ) : (
                    <>
                        <p className="text-sm font-medium break-words">
                            {message.subject}
                        </p>
                        <iframe
                            sandbox=""
                            srcDoc={message.html}
                            title={t('client_messages.list.sent_title')}
                            className="h-[60vh] w-full rounded-lg border bg-white"
                        />
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}
