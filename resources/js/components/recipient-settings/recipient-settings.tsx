import { router } from '@inertiajs/react';
import { BellRing, ChevronRight, Plus } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { rowTargetClass, rowTargetClick } from '@/components/entity-link';
import { EmptyState } from '@/components/empty-state';
import { FormSection } from '@/components/form-section';
import { StatusBadge } from '@/components/monitoring';
import { ChannelFormSheet } from '@/components/recipient-settings/channel-form-sheet';
import { ChannelGlyph } from '@/components/recipient-settings/channel-glyph';
import { ChannelSheet } from '@/components/recipient-settings/channel-sheet';
import {
    channelDefaults,
    channelGrid,
    channelSentence,
    channelTarget,
    differsFromDefaults,
    siteIdentities,
    type Channel,
    type SettingsPage,
} from '@/components/recipient-settings/model';
import { ScheduleSection } from '@/components/recipient-settings/schedule-section';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ListRow, rowEmphasisClass } from '@/components/ui/table';
import { focusRingClass } from '@/lib/interaction';
import { cn } from '@/lib/utils';

function ChannelStatus({
    channel,
    custom,
}: {
    channel: Channel;
    custom: boolean;
}) {
    const { t } = useTranslation();
    const state = {
        paused: ['paused', 'Paused'],
        needs_attention: ['failed', 'Needs attention'],
        unverified: ['warning', 'recipient_settings.status.unverified'],
        active: null,
    } as const;
    const shown = state[channel.status];
    return (
        <>
            {shown && <StatusBadge state={shown[0]} label={shown[1]} />}
            {channel.account_mail && (
                <Badge variant="secondary">{t('Account email')}</Badge>
            )}
            {custom && (
                <Badge variant="secondary">
                    {t('recipient_settings.custom')}
                </Badge>
            )}
        </>
    );
}

/**
 * RecipientSettings (design §7.1): the channel list with each channel's summary sentence, the channel sheet with its
 * grid, and the recipient's schedule. One component for the team, a person and a client; only notices differ.
 */
export function RecipientSettings({
    page,
    scopeDescription,
    notice,
}: {
    page: SettingsPage;
    /** One sentence under "Channels": whose alerts these are. */
    scopeDescription: string;
    /** A recipient-specific notice above the channels (the client notice). */
    notice?: ReactNode;
}) {
    const { t } = useTranslation();
    // `?channel=<id>` opens a channel sheet directly (links from repair notices and explanations).
    const [openId, setOpenId] = useState<number | null>(() => {
        const requested = new URLSearchParams(window.location.search).get(
            'channel',
        );
        return requested === null ? null : Number(requested);
    });
    const [editing, setEditing] = useState<Channel | 'new' | null>(null);
    const open = page.channels.find((channel) => channel.id === openId);
    const reload = () => router.reload({ only: ['settings'] });
    const labels = siteIdentities(page.sites);
    const siteName = (siteId: number) =>
        labels.get(siteId)?.label ?? t('recipient_settings.unknown_site');
    return (
        <div>
            {notice}
            <FormSection
                title={t('recipient_settings.channels.title')}
                description={scopeDescription}
            >
                {page.channels.length === 0 ? (
                    <EmptyState
                        icon={BellRing}
                        title={t('recipient_settings.channels.empty_title')}
                        description={t(
                            'recipient_settings.channels.empty_description',
                        )}
                    />
                ) : (
                    <div className="overflow-hidden rounded-lg border">
                        {page.channels.map((channel) => {
                            const grid = channelGrid(channel, page.grid);
                            const paused = channel.status === 'paused';
                            return (
                                <ListRow
                                    key={channel.id}
                                    data-channel={channel.id}
                                    className={cn(
                                        'grid-cols-[minmax(0,1fr)_auto] items-center',
                                        rowTargetClass,
                                        channel.status === 'needs_attention' &&
                                            rowEmphasisClass('failed'),
                                    )}
                                    onClick={rowTargetClick(() =>
                                        setOpenId(channel.id),
                                    )}
                                >
                                    <div className="flex min-w-0 items-start gap-3">
                                        <ChannelGlyph
                                            channel={channel.channel}
                                            paused={paused}
                                        />
                                        <div className="min-w-0 space-y-1">
                                            <p className="flex flex-wrap items-center gap-x-2 gap-y-1">
                                                <button
                                                    type="button"
                                                    className={cn(
                                                        'rounded-sm text-left font-medium',
                                                        focusRingClass,
                                                    )}
                                                    onClick={() =>
                                                        setOpenId(channel.id)
                                                    }
                                                >
                                                    {channel.label}
                                                </button>
                                                <span className="min-w-0 truncate font-mono text-xs text-muted-foreground">
                                                    {channelTarget(channel) ??
                                                        t(
                                                            `notifications.channel.${channel.channel}`,
                                                        )}
                                                </span>
                                                <ChannelStatus
                                                    channel={channel}
                                                    custom={differsFromDefaults(
                                                        grid,
                                                        channelDefaults(
                                                            channel,
                                                        ),
                                                    )}
                                                />
                                            </p>
                                            <p
                                                className={cn(
                                                    'text-sm',
                                                    paused
                                                        ? 'text-muted-foreground'
                                                        : 'text-foreground',
                                                )}
                                            >
                                                {paused &&
                                                    `${t(channel.system_bypasses_pause ? 'recipient_settings.paused_prefix_system' : 'recipient_settings.paused_prefix')} `}
                                                {channelSentence({
                                                    t,
                                                    kind: page.recipient_kind,
                                                    grid,
                                                    digestTime:
                                                        page.schedule
                                                            .digest_time,
                                                    mutedSites:
                                                        channel.muted_site_ids.map(
                                                            siteName,
                                                        ),
                                                })}
                                            </p>
                                            {channel.status ===
                                                'needs_attention' &&
                                                channel.attention_reason && (
                                                    <p className="text-xs text-muted-foreground">
                                                        {
                                                            channel.attention_reason
                                                        }
                                                    </p>
                                                )}
                                        </div>
                                    </div>
                                    <ChevronRight
                                        aria-hidden
                                        className="size-4 text-muted-foreground"
                                    />
                                </ListRow>
                            );
                        })}
                    </div>
                )}
                <Button
                    type="button"
                    variant="outline"
                    data-shortcut-create
                    onClick={() => setEditing('new')}
                >
                    <Plus aria-hidden />
                    {t('Add channel')}
                </Button>
            </FormSection>
            <ScheduleSection
                key={JSON.stringify(page.schedule)}
                page={page}
                onSaved={reload}
            />
            {editing !== null && (
                <ChannelFormSheet
                    page={page}
                    channel={editing === 'new' ? null : editing}
                    onClose={() => setEditing(null)}
                    onSaved={(id) => {
                        setEditing(null);
                        // The channel's sheet opens again (a new channel's for the first time) to set its categories.
                        router.reload({
                            only: ['settings'],
                            onSuccess: () => setOpenId(id),
                        });
                    }}
                />
            )}
            {open && (
                <ChannelSheet
                    key={open.id}
                    page={page}
                    channel={open}
                    onChanged={reload}
                    onEdit={() => {
                        setOpenId(null);
                        setEditing(open);
                    }}
                    onClose={() => setOpenId(null)}
                />
            )}
        </div>
    );
}
