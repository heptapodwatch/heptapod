import { AbsoluteTime } from '@/components/absolute-time';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { StatusDot } from '@/components/monitoring';
import { TruncatedText } from '@/components/truncated-text';
import { Button } from '@/components/ui/button';
import { useFormat } from '@/hooks/use-format';
import { deliveryOutcomeStates } from '@/lib/status';
import { show as incidentShow } from '@/routes/incidents';

type Group = Heptapod.Data.Ui.IncidentNotificationGroupData;
type Page = Heptapod.Data.Ui.IncidentNotificationPageData;
type Delivery = Heptapod.Data.Ui.IncidentDeliveryData;

export function IncidentNotificationGroup({
    group,
    incidentId,
    expanded,
    previewAvailable,
    onPreview,
}: {
    group: Group;
    incidentId: number;
    expanded: Page | null;
    previewAvailable: boolean;
    onPreview: (eventId: number) => void;
}) {
    const { t } = useTranslation();
    const format = useFormat();
    const [open, setOpen] = useState(expanded?.group_id === group.id);
    const [loading, setLoading] = useState(false);
    const page = expanded?.group_id === group.id ? expanded : null;
    const isOpen = open && (page !== null || loading);
    const recordedReasons = Object.entries(group.unrecorded_reasons).filter(
        ([, count]) => count > 0,
    );
    const unknownReasonCount =
        group.unrecorded_count -
        recordedReasons.reduce((total, [, count]) => total + count, 0);
    const outcomes = [
        group.logged_count > 0 &&
            t('Logged notifications, not delivered: :count', {
                count: format.number(group.logged_count),
            }),
        group.accepted_count > 0 &&
            t(
                group.accepted_count === group.count
                    ? 'All accepted notifications: :count'
                    : 'Accepted notifications: :count',
                { count: format.number(group.accepted_count) },
            ),
        group.failed_count > 0 &&
            t('Failed notifications: :count', {
                count: format.number(group.failed_count),
            }),
        group.suppressed_count > 0 &&
            t('Suppressed notifications: :count', {
                count: format.number(group.suppressed_count),
            }),
        group.pending_count > 0 &&
            t('Pending notifications: :count', {
                count: format.number(group.pending_count),
            }),
    ]
        .filter(Boolean)
        .join(' · ');
    const load = (cursor: string | null = null) => {
        const [before, beforeId] = cursor?.split('|') ?? [];
        setOpen(true);
        router.get(
            incidentShow.url(incidentId, {
                query: {
                    notification_group: group.id,
                    ...(cursor
                        ? {
                              notification_before: before,
                              notification_before_id: beforeId,
                          }
                        : {}),
                },
            }),
            {},
            {
                only: ['expanded_notifications'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onStart: () => setLoading(true),
                onFinish: () => setLoading(false),
            },
        );
    };
    return (
        <div className="space-y-2 text-sm" data-notification-group={group.id}>
            <p className="font-medium">
                {t(
                    group.event_type === 'incident.still_down'
                        ? 'Reminders · :count'
                        : 'Notifications · :count',
                    {
                        count: group.count || group.unrecorded_count,
                        formattedCount: format.number(
                            group.count || group.unrecorded_count,
                        ),
                    },
                )}
                {' · '}
                {t(group.event_label)}
            </p>
            {group.first_at !== group.last_at && (
                <p className="text-xs text-muted-foreground">
                    <AbsoluteTime value={group.first_at} />
                    {group.first_at !== group.last_at && (
                        <>
                            {' '}
                            → <AbsoluteTime value={group.last_at} />
                        </>
                    )}
                </p>
            )}
            <p className="text-xs text-muted-foreground">{outcomes}</p>
            {recordedReasons.map(([reason, count]) => (
                <p key={reason} className="text-xs text-muted-foreground">
                    {t('Reminders not sent with reason: :count', {
                        count,
                        formattedCount: format.number(count),
                        reason: t(`reminders.reason.${reason}`),
                    })}
                </p>
            ))}
            {unknownReasonCount > 0 && (
                <p className="text-xs text-muted-foreground">
                    {t('Reminders were not sent: :count', {
                        count: unknownReasonCount,
                        formattedCount: format.number(unknownReasonCount),
                    })}
                </p>
            )}
            <ul className="space-y-0.5 text-xs text-muted-foreground">
                {group.recipients.map((recipient) => (
                    <li key={recipient} className="flex min-w-0">
                        <TruncatedText content={recipient}>
                            <span className="min-w-0 truncate font-mono">
                                {recipient}
                            </span>
                        </TruncatedText>
                    </li>
                ))}
            </ul>
            {group.recipient_count > group.recipients.length && (
                <p className="text-xs text-muted-foreground">
                    {t('More recipients: :count', {
                        count: format.number(
                            group.recipient_count - group.recipients.length,
                        ),
                    })}
                </p>
            )}
            <Button
                size="sm"
                variant="ghost"
                aria-expanded={isOpen}
                disabled={loading}
                onClick={() => (isOpen ? setOpen(false) : load())}
            >
                {t(isOpen ? 'Hide notifications' : 'Show notifications')}
            </Button>
            {isOpen && (
                <div className="space-y-3 border-l pl-3" aria-busy={loading}>
                    {loading ? (
                        <p>{t('Loading')}</p>
                    ) : (
                        page && (
                            <>
                                <ol className="space-y-4">
                                    {page.deliveries.map((delivery) => (
                                        <li key={delivery.id}>
                                            <IncidentDeliveryRow
                                                delivery={delivery}
                                                previewAvailable={
                                                    previewAvailable
                                                }
                                                onPreview={onPreview}
                                            />
                                        </li>
                                    ))}
                                </ol>
                                <div className="flex flex-wrap gap-2">
                                    {page.next_cursor && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                load(page.next_cursor)
                                            }
                                        >
                                            {t('Earlier notifications')}
                                        </Button>
                                    )}
                                    {page.has_previous && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => load()}
                                        >
                                            {t('Latest notifications')}
                                        </Button>
                                    )}
                                </div>
                            </>
                        )
                    )}
                </div>
            )}
        </div>
    );
}

/** One mail delivery: time, state word, recipient, delay, retries, diagnosis and the Owner/Admin preview. */
export function IncidentDeliveryRow({
    delivery,
    previewAvailable,
    onPreview,
}: {
    delivery: Delivery;
    previewAvailable: boolean;
    onPreview: (eventId: number) => void;
}) {
    const { t } = useTranslation();
    const format = useFormat();
    return (
        <div className="space-y-1">
            {/* A column of past instants: the same absolute time, with seconds, in every row. */}
            <AbsoluteTime
                value={
                    delivery.timeline_time ??
                    delivery.sent_at ??
                    delivery.scheduled_at
                }
                mode="eventTimestamp"
                className="text-xs text-muted-foreground"
            />
            <div className="flex flex-wrap items-center gap-2">
                <StatusDot
                    state={deliveryOutcomeStates[delivery.state]}
                    label={t(delivery.state)}
                />
                <span>
                    {t(delivery.event_label)} · {t(delivery.state)}
                </span>
            </div>
            <p className="flex min-w-0 text-xs text-muted-foreground">
                <TruncatedText content={delivery.address}>
                    <span className="min-w-0 truncate font-mono">
                        {delivery.address}
                    </span>
                </TruncatedText>
            </p>
            {delivery.sent_delay_seconds !== null &&
                delivery.sent_delay_seconds >= 60 && (
                    <p className="text-xs text-muted-foreground">
                        {t('Sent :delay after notification', {
                            delay: format.duration(delivery.sent_delay_seconds),
                        })}
                    </p>
                )}
            {delivery.retry_count > 0 && (
                <p className="text-xs text-muted-foreground">
                    {t('Retried {{count}} times', {
                        count: delivery.retry_count,
                        formattedCount: format.number(delivery.retry_count),
                    })}
                </p>
            )}
            {delivery.diagnosis && (
                <p className="break-words text-muted-foreground">
                    {delivery.diagnosis}
                </p>
            )}
            {previewAvailable && delivery.can_preview && (
                <Button
                    size="sm"
                    variant="ghost"
                    onClick={() => onPreview(delivery.event_id)}
                >
                    {t('Preview email')}
                </Button>
            )}
        </div>
    );
}
