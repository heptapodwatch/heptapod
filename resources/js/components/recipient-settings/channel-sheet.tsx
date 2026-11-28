import {
    ChevronUp,
    Lock,
    Pause,
    Pencil,
    Play,
    RotateCcw,
    Send,
} from 'lucide-react';
import { useId, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { ActionNoteSlot } from '@/components/action-note-slot';
import { AbsoluteTime } from '@/components/absolute-time';
import { CardToolbarActions } from '@/components/card-toolbar-actions';
import { PageHeaderAction } from '@/components/page-header-actions';
import { TruncatedText } from '@/components/truncated-text';
import {
    Card,
    CardAction,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { SearchMultiCombobox } from '@/components/search-combobox';
import {
    ChannelConnectionFields,
    useChannelForm,
} from '@/components/recipient-settings/channel-form-sheet';
import InputError from '@/components/input-error';
import { ChannelGlyph } from '@/components/recipient-settings/channel-glyph';
import {
    channelDefaults,
    channelGrid,
    channelSentence,
    channelTarget,
    differsFromDefaults,
    siteIdentities,
    modeLabel,
    modes,
    serversLocked,
    type Channel,
    type Grid,
    type Mode,
    type SettingsPage,
} from '@/components/recipient-settings/model';
import { SectionHeading } from '@/components/section-heading';
import { SheetForm } from '@/components/sheet-form';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Sheet } from '@/components/ui/sheet';
import {
    HttpValidationError,
    httpErrorMessage,
    useHttpRequest,
} from '@/hooks/use-http-request';
import { cn } from '@/lib/utils';
import {
    destroy,
    enabled as setEnabled,
    update as saveChannel,
    test as sendTest,
} from '@/routes/notification-channels';

/** The grid's mode columns at sm and above: the header row and every category row share them. */
const modeColumns =
    'sm:grid sm:grid-cols-[5.5rem_8rem_3rem] sm:justify-items-center sm:gap-x-2';

type PauseResult = { id: number; enabled: boolean; suppressed_count: number };

/**
 * One channel in one sheet: identity and actions on top, the live sentence, the grid for this channel and its site
 * exceptions. Test, pause and remove act at once; the grid and the exceptions are a draft until Save.
 */
export function ChannelSheet({
    page,
    channel,
    onChanged,
    onClose,
}: {
    page: SettingsPage;
    channel: Channel;
    /** Re-reads the page props after a change that already happened on the server. */
    onChanged: () => void;
    onClose: () => void;
}) {
    const { t } = useTranslation();
    const id = useId();
    const saved = channelGrid(channel, page.grid);
    const defaults = channelDefaults(channel);
    const [grid, setGrid] = useState<Grid>(saved);
    const [siteIds, setSiteIds] = useState<number[]>(channel.muted_site_ids);
    const [confirmRemoval, setConfirmRemoval] = useState(false);
    const [error, setError] = useState('');
    const [testDiagnosis, setTestDiagnosis] = useState<string | null>(null);
    const {
        form: save,
        changes,
        valid,
        isConnectionError,
    } = useChannelForm(page, channel);
    const editor = useRef<HTMLDivElement>(null);
    const [editing, setEditing] = useState(false);
    const test = useHttpRequest<
        { draft?: typeof changes },
        Heptapod.Contracts.Notifications.DeliveryResult
    >({});
    const pause = useHttpRequest<{ enabled: boolean }, PauseResult>({
        enabled: channel.status === 'paused',
    });
    const removal = useHttpRequest<
        Record<string, never>,
        { suppressed_count: number }
    >({});
    const busy =
        save.processing ||
        test.processing ||
        pause.processing ||
        removal.processing;
    const paused = channel.status === 'paused';
    const labels = siteIdentities(page.muted_sites);
    // The channel sentence names a site picked in this sheet with its stable detail until a save names it among the muted ones.
    const [picked, setPicked] = useState<
        ReadonlyMap<number, Heptapod.Data.Ui.PickerSiteData>
    >(() => new Map());
    const siteName = (siteId: number) => {
        const site = picked.get(siteId);
        return (
            labels.get(siteId)?.label ??
            (site
                ? `${site.name} (${site.detail})`
                : t('recipient_settings.unknown_site'))
        );
    };
    const changedCells = Object.keys(grid).filter(
        (category) => grid[category] !== saved[category],
    );
    const added = siteIds.filter(
        (site) => !channel.muted_site_ids.includes(site),
    );
    const removed = channel.muted_site_ids.filter(
        (site) => !siteIds.includes(site),
    );
    const connectionDirty = Object.keys(changes).length > 0;
    const dirty =
        connectionDirty ||
        changedCells.length + added.length + removed.length > 0;
    const name = channel.label;

    const submit = async () => {
        setError('');
        try {
            save.transform(() => ({
                ...changes,
                ...(changedCells.length > 0
                    ? {
                          preferences: Object.fromEntries(
                              changedCells.map((category) => [
                                  category,
                                  grid[category]!,
                              ]),
                          ),
                      }
                    : {}),
                ...(added.length > 0 ? { mute_site_ids: added } : {}),
                ...(removed.length > 0 ? { unmute_site_ids: removed } : {}),
            }));
            await save.patch(saveChannel.url(channel.id));
            toast.success(t('recipient_settings.channel_saved'));
            onChanged();
            onClose();
        } catch (failure: unknown) {
            if (failure instanceof HttpValidationError) {
                const errors = Object.entries(failure.errors);
                if (errors.some(([key]) => isConnectionError(key))) {
                    setEditing(true);
                    requestAnimationFrame(() => {
                        const field =
                            editor.current?.querySelector<HTMLElement>(
                                '[aria-invalid="true"]',
                            );
                        if (field) field.focus();
                    });
                }
                const other = errors.find(([key]) => !isConnectionError(key));
                if (other) setError(String(other[1]));
            } else {
                setError(
                    httpErrorMessage(
                        failure,
                        t,
                        'recipient_settings.save_failed',
                    ),
                );
            }
            onChanged();
        }
    };

    return (
        <Sheet
            open
            onOpenChange={(open) => {
                if (!open && !busy) onClose();
            }}
        >
            <SheetForm
                title={name}
                description={t(
                    page.recipient_kind === 'client'
                        ? 'recipient_settings.sheet.client_description'
                        : 'recipient_settings.sheet.description',
                )}
                aria-busy={busy}
                onSubmit={() => void submit()}
                actions={{
                    kind: 'edit',
                    dirty,
                    valid: !connectionDirty || valid,
                    blockedReason:
                        connectionDirty && !valid
                            ? t('recipient_settings.form.invalid_connection')
                            : undefined,
                    busy,
                    pendingLabel: save.processing ? t('Saving…') : undefined,
                    onCancel: onClose,
                    remove: channel.can_remove
                        ? {
                              label: t('Remove channel'),
                              confirm: () => setConfirmRemoval(true),
                          }
                        : undefined,
                }}
            >
                <Card>
                    <CardHeader>
                        <CardTitle>
                            <ChannelGlyph
                                channel={channel.channel}
                                paused={paused}
                            />
                            {t(`notifications.channel.${channel.channel}`)}
                        </CardTitle>
                        <CardAction>
                            <CardToolbarActions
                                primary={
                                    <Button
                                        type="button"
                                        aria-describedby={
                                            testDiagnosis
                                                ? `${id}-test-diagnosis`
                                                : undefined
                                        }
                                        disabled={
                                            busy ||
                                            channel.status ===
                                                'needs_attention' ||
                                            (editing &&
                                                connectionDirty &&
                                                !valid)
                                        }
                                        onClick={() => {
                                            setTestDiagnosis(null);
                                            test.transform(() =>
                                                editing && connectionDirty
                                                    ? { draft: changes }
                                                    : {},
                                            );
                                            void test
                                                .post(sendTest.url(channel.id))
                                                .then((result) => {
                                                    setTestDiagnosis(
                                                        result.status === 'sent'
                                                            ? null
                                                            : result.diagnosis,
                                                    );
                                                    if (
                                                        result.status === 'sent'
                                                    )
                                                        toast.success(
                                                            t(
                                                                'recipient_settings.test_sent',
                                                                {
                                                                    channel:
                                                                        name,
                                                                },
                                                            ),
                                                        );
                                                    else
                                                        toast.error(
                                                            result.reason
                                                                ? t(
                                                                      result.reason,
                                                                  )
                                                                : t(
                                                                      'recipient_settings.test_failed',
                                                                  ),
                                                        );
                                                })
                                                .catch((error: unknown) =>
                                                    toast.error(
                                                        httpErrorMessage(
                                                            error,
                                                            t,
                                                            'recipient_settings.test_failed',
                                                        ),
                                                    ),
                                                )
                                                .finally(onChanged);
                                        }}
                                    >
                                        <Send aria-hidden />
                                        {t(
                                            test.processing
                                                ? 'Sending…'
                                                : 'Send test',
                                        )}
                                    </Button>
                                }
                            >
                                <PageHeaderAction
                                    type="button"
                                    variant="outline"
                                    disabled={busy}
                                    onClick={() => {
                                        setEditing(!editing);
                                        if (!editing)
                                            requestAnimationFrame(() =>
                                                editor.current
                                                    ?.querySelector<HTMLElement>(
                                                        'input:not([type=hidden]), [role=combobox]',
                                                    )
                                                    ?.focus(),
                                            );
                                    }}
                                >
                                    {editing ? (
                                        <ChevronUp aria-hidden />
                                    ) : (
                                        <Pencil aria-hidden />
                                    )}
                                    {t(
                                        editing
                                            ? 'recipient_settings.form.collapse'
                                            : 'Edit',
                                    )}
                                </PageHeaderAction>
                                {channel.status !== 'needs_attention' && (
                                    <PageHeaderAction
                                        type="button"
                                        variant="outline"
                                        disabled={busy}
                                        onClick={() => {
                                            pause.transform(() => ({
                                                enabled: paused,
                                            }));
                                            void pause
                                                .patch(
                                                    setEnabled.url(channel.id),
                                                )
                                                .then((result) =>
                                                    toast.success(
                                                        result.enabled
                                                            ? t(
                                                                  'notifications.destination_resumed',
                                                              )
                                                            : t(
                                                                  'notifications.destination_paused_count',
                                                                  {
                                                                      count: result.suppressed_count,
                                                                      formattedCount:
                                                                          result.suppressed_count,
                                                                  },
                                                              ),
                                                    ),
                                                )
                                                .catch((error: unknown) =>
                                                    toast.error(
                                                        httpErrorMessage(
                                                            error,
                                                            t,
                                                            'notifications.pause_rejected',
                                                        ),
                                                    ),
                                                )
                                                .finally(onChanged);
                                        }}
                                    >
                                        {paused ? (
                                            <Play aria-hidden />
                                        ) : (
                                            <Pause aria-hidden />
                                        )}
                                        {t(paused ? 'Resume' : 'Pause')}
                                    </PageHeaderAction>
                                )}
                            </CardToolbarActions>
                        </CardAction>
                    </CardHeader>
                    <CardContent className="space-y-1 text-xs text-muted-foreground">
                        <TruncatedText
                            content={
                                channelTarget(channel) ??
                                t(`notifications.channel.${channel.channel}`)
                            }
                        >
                            <p className="truncate font-mono text-foreground">
                                {channelTarget(channel) ??
                                    t(
                                        `notifications.channel.${channel.channel}`,
                                    )}
                            </p>
                        </TruncatedText>
                        <LastOutcome channel={channel} />
                        {/* The provider's own reason, redacted server-side; the toast only says that the test failed. */}
                        {(channel.status !== 'needs_attention' ||
                            testDiagnosis !== null) && (
                            <ActionNoteSlot id={`${id}-test-diagnosis`}>
                                {testDiagnosis && (
                                    <InputError message={testDiagnosis} />
                                )}
                            </ActionNoteSlot>
                        )}
                        {channel.account_mail && (
                            <p>{t('recipient_settings.account_address')}</p>
                        )}
                    </CardContent>
                    {editing && (
                        <CardContent ref={editor} className="space-y-4">
                            <ChannelConnectionFields
                                page={page}
                                channel={channel}
                                form={save}
                            />
                        </CardContent>
                    )}
                </Card>
                {paused && (
                    <Alert tone="warning">
                        <AlertTitle>
                            {t(
                                channel.system_bypasses_pause
                                    ? 'recipient_settings.paused_notice_system'
                                    : 'recipient_settings.paused_notice',
                            )}
                        </AlertTitle>
                    </Alert>
                )}
                {channel.status === 'needs_attention' && (
                    <Alert tone="warning">
                        <AlertTitle>
                            {t('recipient_settings.attention_title')}
                        </AlertTitle>
                        {channel.attention_reason && (
                            <AlertDescription>
                                {channel.attention_reason}
                            </AlertDescription>
                        )}
                    </Alert>
                )}
                {channel.status === 'unverified' && (
                    <Alert tone="warning">
                        <AlertTitle>
                            {t('recipient_settings.unverified_notice')}
                        </AlertTitle>
                    </Alert>
                )}
                <p
                    aria-live="polite"
                    className="rounded-lg bg-muted px-3 py-2.5 text-sm"
                >
                    {channelSentence({
                        t,
                        kind: page.recipient_kind,
                        grid,
                        digestTime: page.schedule.digest_time,
                        mutedSites: siteIds.map(siteName),
                    })}
                </p>
                <div className="space-y-2">
                    <div className="flex min-h-6 items-center justify-between gap-2">
                        <SectionHeading level={3}>
                            {t(
                                page.recipient_kind === 'client'
                                    ? 'recipient_settings.grid.client_title'
                                    : 'recipient_settings.grid.title',
                            )}
                        </SectionHeading>
                        {defaults !== null &&
                            differsFromDefaults(grid, defaults) && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="xs"
                                    disabled={busy}
                                    onClick={() =>
                                        setGrid((current) =>
                                            Object.fromEntries(
                                                Object.keys(current).map(
                                                    (category) => [
                                                        category,
                                                        (category ===
                                                            'system' &&
                                                            channel.system_locked) ||
                                                        serversLocked(
                                                            channel,
                                                            category,
                                                        )
                                                            ? current[category]!
                                                            : defaults[
                                                                  category
                                                              ]!,
                                                    ],
                                                ),
                                            ),
                                        )
                                    }
                                >
                                    <RotateCcw aria-hidden />
                                    {t('recipient_settings.reset_defaults')}
                                </Button>
                            )}
                    </div>
                    <div className="divide-y overflow-hidden rounded-lg border">
                        <div
                            aria-hidden
                            className="hidden px-3 py-1.5 text-label text-muted-foreground sm:grid sm:grid-cols-[minmax(0,1fr)_auto] sm:gap-4"
                        >
                            <span />
                            <span className={modeColumns}>
                                {modes.map((mode) => (
                                    <span key={mode}>{t(modeLabel[mode])}</span>
                                ))}
                            </span>
                        </div>
                        {page.grid.map((row) => {
                            const locked =
                                (row.category === 'system' &&
                                    channel.system_locked) ||
                                serversLocked(channel, row.category);
                            const differs =
                                defaults !== null &&
                                grid[row.category] !== defaults[row.category];
                            const description = t(
                                `recipient_settings.category.${row.category}.description`,
                            );
                            return (
                                <div
                                    key={row.category}
                                    data-category={row.category}
                                    data-differs={differs || undefined}
                                    className={cn(
                                        'grid gap-1 px-3 py-2 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:gap-4',
                                        // The sheet sits on the popover surface; dark accent equals it, so dark mode shades with the border tone.
                                        differs && 'bg-accent dark:bg-border',
                                    )}
                                >
                                    <div className="min-w-0">
                                        <p
                                            id={`${id}-${row.category}`}
                                            className="flex items-center gap-1.5 text-cell font-medium"
                                        >
                                            {t(
                                                `recipient_settings.category.${row.category}.label`,
                                            )}
                                            {locked && (
                                                <Lock
                                                    aria-hidden
                                                    className="size-3 text-muted-foreground"
                                                />
                                            )}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {locked
                                                ? t(
                                                      row.category === 'servers'
                                                          ? 'recipient_settings.servers_client_limited'
                                                          : 'recipient_settings.system_locked',
                                                  )
                                                : differs
                                                  ? t(
                                                        'recipient_settings.description_with_default',
                                                        {
                                                            description,
                                                            default: t(
                                                                modeLabel[
                                                                    defaults[
                                                                        row
                                                                            .category
                                                                    ]!
                                                                ],
                                                            ),
                                                        },
                                                    )
                                                  : description}
                                        </p>
                                    </div>
                                    <RadioGroup
                                        aria-labelledby={`${id}-${row.category}`}
                                        value={grid[row.category]}
                                        disabled={locked || busy}
                                        onValueChange={(mode) =>
                                            setGrid((current) => ({
                                                ...current,
                                                [row.category]: mode as Mode,
                                            }))
                                        }
                                        className={cn(
                                            'flex-wrap gap-x-4',
                                            modeColumns,
                                        )}
                                    >
                                        {modes.map((mode) =>
                                            row.modes.includes(mode) ? (
                                                <RadioGroupItem
                                                    key={mode}
                                                    value={mode}
                                                    className="bg-secondary"
                                                >
                                                    <span className="text-cell sm:sr-only">
                                                        {t(modeLabel[mode])}
                                                    </span>
                                                </RadioGroupItem>
                                            ) : (
                                                // Keeps Off in its column when this category has no digest.
                                                <span
                                                    key={mode}
                                                    aria-hidden
                                                    className="hidden sm:block"
                                                />
                                            ),
                                        )}
                                    </RadioGroup>
                                </div>
                            );
                        })}
                    </div>
                    <p className="text-xs text-muted-foreground">
                        {t(
                            page.recipient_kind === 'client'
                                ? 'recipient_settings.grid.client_note'
                                : defaults === null
                                  ? 'recipient_settings.grid.quiet_note'
                                  : 'recipient_settings.grid.note',
                        )}
                    </p>
                </div>
                <div className="space-y-2">
                    <SectionHeading level={3}>
                        {t('recipient_settings.mutes.title')}
                    </SectionHeading>
                    <SearchMultiCombobox
                        kind="sites"
                        scope={
                            page.recipient_kind === 'client' &&
                            page.recipient_id !== null
                                ? // A client's own exceptions include its sites while it is archived, as before.
                                  { client: page.recipient_id, archived: true }
                                : {}
                        }
                        ariaLabel={t('recipient_settings.mutes.picker')}
                        values={siteIds.map(String)}
                        disabled={busy}
                        placeholder={t('recipient_settings.mutes.placeholder')}
                        onValuesChange={(values, sites) => {
                            setPicked(
                                (current) =>
                                    new Map([
                                        ...current,
                                        ...sites.map(
                                            (site) => [site.id, site] as const,
                                        ),
                                    ]),
                            );
                            setSiteIds(values.map(Number));
                        }}
                    />
                </div>
                <InputError message={error} />
            </SheetForm>
            <ConfirmDialog
                open={confirmRemoval}
                onOpenChange={setConfirmRemoval}
                destructive
                title={t('recipient_settings.remove.title', { channel: name })}
                description={t('recipient_settings.remove.description', {
                    count: channel.queued_count,
                })}
                confirmLabel={t('Remove channel')}
                onConfirm={async () => {
                    try {
                        await removal.delete(destroy.url(channel.id));
                        toast(
                            t('recipient_settings.removed', { channel: name }),
                        );
                        onChanged();
                        onClose();
                        return true;
                    } catch (error: unknown) {
                        toast.error(
                            httpErrorMessage(
                                error,
                                t,
                                'recipient_settings.remove.failed',
                            ),
                        );
                        return false;
                    }
                }}
            />
        </Sheet>
    );
}

/** "Last delivery accepted 27 Oct, 10:14" or "Nothing sent yet". */
export function LastOutcome({ channel }: { channel: Channel }) {
    const { t } = useTranslation();
    if (channel.last_outcome === null || channel.last_outcome_at === null)
        return <p>{t('recipient_settings.outcome.none')}</p>;
    return (
        <p>
            {t(`recipient_settings.outcome.${channel.last_outcome}`)}{' '}
            <AbsoluteTime value={channel.last_outcome_at} />
        </p>
    );
}
