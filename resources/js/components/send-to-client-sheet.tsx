import { router } from '@inertiajs/react';
import { Send } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { FormSecondaryAction } from '@/components/form-section';
import InputError from '@/components/input-error';
import { SectionHeading } from '@/components/section-heading';
import { SheetForm } from '@/components/sheet-form';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { CheckboxField } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Sheet } from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { HttpValidationError, useHttpRequest } from '@/hooks/use-http-request';
import { show as clientShow } from '@/routes/clients';
import {
    preview as previewMessage,
    store as storeMessage,
} from '@/routes/clients/messages';
import { data as clientChannelsData } from '@/routes/clients/notifications';

/** What the message is about: a check's current findings, or an incident. */
export type ClientMessageSubject =
    | { kind: 'findings'; check_id: number }
    | { kind: 'incident'; incident_id: number };

type MailDesign = Heptapod.Contracts.Mail.MailDesign;

/** A previous message to send again: its note and attachment choice, and a failed one-off address. */
export type ClientMessageResend = {
    note: string;
    attach: boolean;
    one_off: string | null;
};

type Channel = Heptapod.Data.Ui.NotificationChannelData;

type Draft = {
    channel_ids: number[];
    one_off: string;
    note: string;
    attach: boolean;
    design: MailDesign;
};

type Preview = {
    subject: string;
    html: string;
    locale: 'en' | 'de';
    total: number | null;
};

/**
 * "Send to client" (design §4): the client's email channels (paused ones still receive this message, archived ones are
 * gone), one more address for this time only, a note, the CSV, and the mail exactly as the client reads it.
 * `initialDesign` is the instance mail design for a new message and the sent message's design for a resend.
 */
export function SendToClientSheet({
    open,
    onOpenChange,
    client,
    subject,
    resend,
    initialDesign,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    client: { id: number; name: string };
    subject: ClientMessageSubject;
    resend?: ClientMessageResend;
    initialDesign: MailDesign;
}) {
    const { t } = useTranslation();
    const id = useId();
    const [channels, setChannels] = useState<Channel[] | null>(null);
    const [loadFailed, setLoadFailed] = useState(false);
    const [preview, setPreview] = useState<Preview | null>(null);
    const [previewFailed, setPreviewFailed] = useState(false);
    const idempotencyKey = useRef(crypto.randomUUID());
    const form = useHttpRequest<Draft, { event_id: number }>({
        channel_ids: [],
        one_off: resend?.one_off ?? '',
        note: resend?.note ?? '',
        attach: resend?.attach ?? subject.kind === 'findings',
        design: initialDesign,
    });
    const previewRequest = useHttpRequest<{ draft: string }, Preview>({
        draft: '',
    });

    useEffect(() => {
        if (!open) return;
        const controller = new AbortController();
        setLoadFailed(false);
        void fetch(clientChannelsData.url(client.id), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: controller.signal,
        })
            .then(async (response) => {
                if (!response.ok)
                    throw new Error(
                        `Client channels failed: ${response.status}`,
                    );
                const page =
                    (await response.json()) as Heptapod.Data.Ui.NotificationSettingsPageData;
                const mail = page.channels.filter(
                    (channel) => channel.channel === 'mail',
                );
                setChannels(page.channels);
                // A resend of a failed one-off goes only to that address; otherwise start with the first mail channel.
                if (!resend?.one_off && mail[0])
                    form.setData('channel_ids', [mail[0].id]);
            })
            .catch((error: unknown) => {
                if (controller.signal.aborted) return;
                console.error(error);
                setLoadFailed(true);
            });
        return () => controller.abort();
        // The sheet loads the client's channels once per opening.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, client.id]);

    const payload = {
        ...subject,
        channel_ids: form.data.channel_ids,
        one_off: form.data.one_off.trim() || null,
        note: form.data.note,
        attach: subject.kind === 'findings' && form.data.attach,
        design: form.data.design,
    };
    const previewKey = JSON.stringify([
        subject,
        form.data.note,
        payload.attach,
        payload.design,
    ]);
    useEffect(() => {
        if (!open) return;
        const timer = window.setTimeout(() => {
            setPreviewFailed(false);
            previewRequest.transform(() => payload);
            void previewRequest
                .post(previewMessage.url(client.id))
                .then(setPreview)
                .catch(() => setPreviewFailed(true));
        }, 300);
        return () => window.clearTimeout(timer);
        // Re-render the preview only when what the client reads changes, not on recipient choice.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, previewKey]);

    const mailChannels = (channels ?? []).filter(
        (channel) => channel.channel === 'mail',
    );
    const otherChannels = (channels ?? []).filter(
        (channel) => channel.channel !== 'mail',
    );
    const chosenPaused = mailChannels.some(
        (channel) =>
            channel.status === 'paused' &&
            form.data.channel_ids.includes(channel.id),
    );
    // A refused channel choice belongs to the To list; only the one-off address's own failure marks that field.
    const channelError = form.errors.channel_ids;
    const oneOffError = (form.errors as Record<string, string | undefined>)[
        'one_off'
    ];

    return (
        <Sheet
            open={open}
            onOpenChange={(next) => {
                if (!next && form.processing) return;
                onOpenChange(next);
            }}
        >
            <SheetForm
                title={t('client_messages.sheet.title')}
                description={t(
                    subject.kind === 'findings'
                        ? 'client_messages.sheet.description_findings'
                        : 'client_messages.sheet.description_incident',
                    { client: client.name },
                )}
                aria-busy={form.processing}
                noValidate
                onSubmit={(event) => {
                    event.preventDefault();
                    if (form.processing) return;
                    if (
                        form.data.channel_ids.length === 0 &&
                        form.data.one_off.trim() === ''
                    ) {
                        form.setError(
                            'channel_ids',
                            t('client_messages.no_recipient'),
                        );
                        return;
                    }
                    form.clearErrors();
                    form.transform(() => payload);
                    void form
                        .post(storeMessage.url(client.id), {
                            headers: {
                                'Idempotency-Key': idempotencyKey.current,
                            },
                        })
                        .then(() => {
                            idempotencyKey.current = crypto.randomUUID();
                            toast.success(
                                t('client_messages.sent', {
                                    client: client.name,
                                }),
                                {
                                    action: {
                                        label: t(
                                            'client_messages.open_history',
                                        ),
                                        onClick: () =>
                                            router.visit(
                                                clientShow.url(client.id, {
                                                    query: { tab: 'messages' },
                                                }),
                                            ),
                                    },
                                },
                            );
                            onOpenChange(false);
                        })
                        .catch((error: unknown) => {
                            if (!(error instanceof HttpValidationError))
                                form.setError(
                                    'channel_ids',
                                    t('client_messages.send_failed'),
                                );
                        });
                }}
                footer={
                    <>
                        <FormSecondaryAction
                            disabled={form.processing}
                            onClick={() => onOpenChange(false)}
                        >
                            {t('Cancel')}
                        </FormSecondaryAction>
                        <Button
                            type="submit"
                            disabled={form.processing || channels === null}
                        >
                            <Send aria-hidden />
                            {t(
                                form.processing
                                    ? 'client_messages.sending'
                                    : 'client_messages.sheet.submit',
                            )}
                        </Button>
                    </>
                }
            >
                <div className="space-y-2">
                    <Label id={`${id}-to`}>
                        {t('client_messages.sheet.to')}
                    </Label>
                    {loadFailed ? (
                        <InputError
                            message={t('client_messages.sheet.load_failed')}
                        />
                    ) : channels === null ? (
                        <Spinner />
                    ) : mailChannels.length === 0 ? (
                        <p className="text-xs text-muted-foreground">
                            {t('client_messages.sheet.no_channels')}
                        </p>
                    ) : (
                        <div
                            role="group"
                            aria-labelledby={`${id}-to`}
                            aria-describedby={`${id}-to-error`}
                            className="divide-y rounded-lg border px-3"
                        >
                            {mailChannels.map((channel) => (
                                <CheckboxField
                                    key={channel.id}
                                    checked={form.data.channel_ids.includes(
                                        channel.id,
                                    )}
                                    onCheckedChange={(checked) =>
                                        form.setData(
                                            'channel_ids',
                                            checked === true
                                                ? [
                                                      ...form.data.channel_ids,
                                                      channel.id,
                                                  ]
                                                : form.data.channel_ids.filter(
                                                      (value) =>
                                                          value !== channel.id,
                                                  ),
                                        )
                                    }
                                >
                                    <span className="font-mono text-xs break-all">
                                        {channel.address}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {channel.label}
                                    </span>
                                    {channel.status === 'paused' && (
                                        <Badge variant="outline">
                                            {t('client_messages.sheet.paused')}
                                        </Badge>
                                    )}
                                </CheckboxField>
                            ))}
                            {otherChannels.map((channel) => (
                                <CheckboxField
                                    key={channel.id}
                                    checked={false}
                                    disabled
                                    description={t(
                                        'client_messages.sheet.email_only',
                                    )}
                                >
                                    <span className="text-xs">
                                        {channel.label}
                                    </span>
                                </CheckboxField>
                            ))}
                        </div>
                    )}
                    <InputError id={`${id}-to-error`} message={channelError} />
                    {chosenPaused && (
                        <Alert tone="info">
                            <AlertTitle>
                                {t('client_messages.sheet.paused_notice')}
                            </AlertTitle>
                        </Alert>
                    )}
                </div>
                <div className="space-y-2">
                    <Label htmlFor={`${id}-oneoff`}>
                        {t('client_messages.sheet.one_off')}
                    </Label>
                    <Input
                        id={`${id}-oneoff`}
                        type="email"
                        autoComplete="off"
                        value={form.data.one_off}
                        aria-invalid={!!oneOffError}
                        aria-describedby={`${id}-oneoff-error`}
                        onChange={(event) =>
                            form.setData('one_off', event.target.value)
                        }
                    />
                    <InputError
                        id={`${id}-oneoff-error`}
                        message={oneOffError}
                    />
                </div>
                <div className="space-y-2">
                    <Label htmlFor={`${id}-note`}>
                        {t('client_messages.sheet.note')}
                    </Label>
                    <Textarea
                        id={`${id}-note`}
                        value={form.data.note}
                        maxLength={2000}
                        placeholder={t(
                            subject.kind === 'findings'
                                ? 'client_messages.sheet.note_placeholder'
                                : 'client_messages.sheet.note_placeholder_incident',
                        )}
                        aria-describedby={`${id}-note-error`}
                        onChange={(event) =>
                            form.setData('note', event.target.value)
                        }
                    />
                    <InputError
                        id={`${id}-note-error`}
                        message={form.errors.note}
                    />
                </div>
                {subject.kind === 'findings' && (
                    <CheckboxField
                        checked={form.data.attach}
                        onCheckedChange={(checked) =>
                            form.setData('attach', checked === true)
                        }
                        description={
                            preview?.total
                                ? t(
                                      'client_messages.sheet.attach_description',
                                      {
                                          count: preview.total,
                                      },
                                  )
                                : undefined
                        }
                    >
                        {t('client_messages.sheet.attach')}
                    </CheckboxField>
                )}
                <div className="space-y-2">
                    <Label id={`${id}-design`}>
                        {t('client_messages.sheet.design')}
                    </Label>
                    <RadioGroup
                        aria-labelledby={`${id}-design`}
                        value={form.data.design}
                        onValueChange={(value) =>
                            form.setData('design', value as MailDesign)
                        }
                    >
                        <RadioGroupItem value="branded">
                            {t('client_messages.sheet.design_branded')}
                        </RadioGroupItem>
                        <RadioGroupItem value="plain">
                            {t('client_messages.sheet.design_plain')}
                        </RadioGroupItem>
                    </RadioGroup>
                </div>
                <p className="text-xs text-muted-foreground">
                    {t('client_messages.sheet.right_away')}
                </p>
                <div className="space-y-2">
                    <div className="flex flex-wrap items-center gap-2">
                        <SectionHeading level={3}>
                            {t('client_messages.sheet.preview')}
                        </SectionHeading>
                        {preview && (
                            <Badge variant="outline">
                                {t(
                                    preview.locale === 'de'
                                        ? 'client_messages.sheet.language_de'
                                        : 'client_messages.sheet.language_en',
                                )}
                            </Badge>
                        )}
                    </div>
                    {previewFailed ? (
                        <Alert tone="warning">
                            <AlertTitle>
                                {t('client_messages.sheet.preview_failed')}
                            </AlertTitle>
                            {previewRequest.errors &&
                                Object.values(previewRequest.errors)[0] && (
                                    <AlertDescription>
                                        {String(
                                            Object.values(
                                                previewRequest.errors,
                                            )[0],
                                        )}
                                    </AlertDescription>
                                )}
                        </Alert>
                    ) : preview === null ? (
                        <Spinner />
                    ) : (
                        <div className="space-y-2">
                            <p className="text-sm font-medium break-words">
                                {preview.subject}
                            </p>
                            <iframe
                                sandbox=""
                                srcDoc={preview.html}
                                title={t('client_messages.sheet.preview')}
                                className="h-[28rem] w-full rounded-lg border bg-white"
                            />
                        </div>
                    )}
                </div>
            </SheetForm>
        </Sheet>
    );
}
