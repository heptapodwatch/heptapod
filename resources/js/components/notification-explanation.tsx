import { usePage } from '@inertiajs/react';
import { useEffect, useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { AbsoluteTime } from '@/components/absolute-time';
import InputError from '@/components/input-error';
import { ChannelGlyph } from '@/components/recipient-settings/channel-glyph';
import { SectionHeading } from '@/components/section-heading';
import { TextLink } from '@/components/text-link';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Combobox } from '@/components/ui/combobox';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { useHttpRequest } from '@/hooks/use-http-request';
import { notifications as clientNotifications } from '@/routes/clients';
import { explain } from '@/routes/notifications';
import { team as teamNotifications } from '@/routes/settings/notifications';

type Recipient = {
    channel_id: number;
    recipient_kind: Heptapod.Enums.RecipientKind;
    label: string;
    channel: string;
    address: string | null;
    status: 'now' | 'at' | 'digest' | 'logged' | 'conditional' | 'not_sent';
    reason: string;
    due_at: string | null;
};
type Explanation = {
    status: 'explained' | 'not_applicable';
    category: string;
    recipients: Recipient[];
    timezone: string;
};

/** The categories a check can be asked about; System has no check and is never explained here. */
export const categories = [
    'outage',
    'failure',
    'check_error',
    'warning',
    'expiring',
    'recovery',
    'changes',
] as const;
type Category = (typeof categories)[number];

/** Lanes in reading order: what goes out now, what goes out later, what does not. */
const lanes = [
    { key: 'now', statuses: ['now', 'logged'] },
    { key: 'later', statuses: ['at', 'digest', 'conditional'] },
    { key: 'none', statuses: ['not_sent'] },
] as const;

/** Exclusions and planner reasons that name a known cause; anything else is a translation key from the server. */
const reasonKeys: Record<string, string> = {
    now: 'explanation.reason.now',
    digest: 'explanation.reason.digest',
    quiet_hours: 'explanation.reason.quiet_hours',
    category_off: 'explanation.reason.category_off',
    site_muted: 'explanation.reason.site_muted',
    paused: 'explanation.reason.paused',
    needs_attention: 'explanation.reason.needs_attention',
    no_site_access: 'explanation.reason.no_site_access',
    unverified: 'explanation.reason.unverified',
    mail_log_only: 'explanation.reason.mail_log_only',
    recovery_needs_opener: 'explanation.reason.recovery_needs_opener',
    opener_pending: 'explanation.reason.opener_pending',
    no_opener: 'explanation.reason.no_opener',
    monitoring_stopped: 'notifications.suppression.monitoring_stopped',
    uptime_dependency: 'notifications.suppression.uptime_dependency',
};

/**
 * "Who gets this and why" (design §2.3, §7.2): for one check and one category, every channel that would or would not
 * be told if it happened now, with the reason. Members see other people's addresses masked.
 */
export function NotificationExplanation({
    checkId: defaultCheckId,
    defaultCategory = 'outage',
    clientId,
    checks,
    onExplained,
}: {
    checkId: number;
    defaultCategory?: Category;
    /** The site's client, so a client channel row links to that client's settings. */
    clientId: number;
    /** Site settings: the site's checks behind a preview-only Check picker beside the category. */
    checks?: { id: number; label: string }[];
    /** Runs after an explanation has rendered. */
    onExplained?: () => void;
}) {
    const { t } = useTranslation();
    const id = useId();
    const { auth } = usePage().props;
    const [checkId, setCheckId] = useState(defaultCheckId);
    const [category, setCategory] = useState<Category>(defaultCategory);
    const [failure, setFailure] = useState(false);
    const request = useHttpRequest<Record<string, never>, Explanation>();
    const [result, setResult] = useState<Explanation | null>(null);
    useEffect(() => {
        let active = true;
        setFailure(false);
        void request
            .get(
                explain.url({
                    query: {
                        check_id: checkId,
                        category,
                        // The server reads "now" in its own clock; ISO 8601 with offset as the endpoint expects.
                        at: new Date()
                            .toISOString()
                            .replace(/\.\d{3}Z$/, '+00:00'),
                    },
                }),
            )
            .then((explanation) => {
                if (active) setResult(explanation);
            })
            .catch(() => {
                if (active) setFailure(true);
            });
        return () => {
            active = false;
            request.cancel();
        };
    }, [checkId, category, request.get, request.cancel]);
    useEffect(() => {
        if (result) onExplained?.();
    }, [result]);
    // Managers open team and client channels; a person's channel is only ever changed by that person.
    const settingsHref = (recipient: Recipient) =>
        !auth.canManageChannels
            ? null
            : recipient.recipient_kind === 'team'
              ? teamNotifications.url({
                    query: { channel: recipient.channel_id },
                })
              : recipient.recipient_kind === 'client'
                ? clientNotifications.url(clientId, {
                      query: { channel: recipient.channel_id },
                  })
                : null;
    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('explanation.title')}</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
                <div className="flex flex-wrap gap-x-4 gap-y-3">
                    {checks && (
                        <div className="w-full space-y-2 sm:w-60">
                            <Label htmlFor={`${id}-check`}>{t('Check')}</Label>
                            <Combobox
                                id={`${id}-check`}
                                options={checks.map((check) => ({
                                    value: String(check.id),
                                    label: check.label,
                                }))}
                                value={String(checkId)}
                                onValueChange={(value) =>
                                    setCheckId(Number(value))
                                }
                            />
                        </div>
                    )}
                    <div className="w-full space-y-2 sm:w-80">
                        <Label htmlFor={`${id}-category`}>
                            {t('explanation.if_now')}
                        </Label>
                        <Select
                            value={category}
                            onValueChange={(value) =>
                                setCategory(value as Category)
                            }
                        >
                            <SelectTrigger
                                id={`${id}-category`}
                                className="w-full"
                            >
                                <SelectValue>
                                    {t(
                                        `recipient_settings.category.${category}.label`,
                                    )}
                                </SelectValue>
                            </SelectTrigger>
                            <SelectContent
                                position="popper"
                                align="start"
                                className="w-[var(--radix-select-trigger-width)]"
                            >
                                {categories.map((value) => (
                                    <SelectItem key={value} value={value}>
                                        <span className="block whitespace-normal">
                                            <span className="block">
                                                {t(
                                                    `recipient_settings.category.${value}.label`,
                                                )}
                                            </span>
                                            <span className="block text-xs text-muted-foreground">
                                                {t(
                                                    `recipient_settings.category.${value}.description`,
                                                )}
                                            </span>
                                        </span>
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                </div>
                {failure ? (
                    <InputError message={t('explanation.failed')} />
                ) : result === null || request.processing ? (
                    <Skeleton className="h-24 w-full" />
                ) : result.status === 'not_applicable' ? (
                    <p className="text-sm text-muted-foreground">
                        {t('explanation.not_applicable', {
                            category: t(
                                `recipient_settings.category.${category}.plural`,
                            ),
                        })}
                    </p>
                ) : result.recipients.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('explanation.no_channels')}
                    </p>
                ) : (
                    <div className="grid gap-3 lg:grid-cols-3">
                        {lanes.map((lane) => {
                            const items = result.recipients.filter(
                                (recipient) =>
                                    (
                                        lane.statuses as readonly string[]
                                    ).includes(recipient.status),
                            );
                            return (
                                <section
                                    key={lane.key}
                                    aria-labelledby={`${id}-${lane.key}`}
                                    className="min-w-0 space-y-2 rounded-lg border bg-muted/40 p-3"
                                >
                                    <SectionHeading
                                        level={3}
                                        id={`${id}-${lane.key}`}
                                        className="flex items-center gap-2"
                                    >
                                        {t(`explanation.lane.${lane.key}`)}
                                        <span className="font-mono text-xs text-muted-foreground tabular-nums">
                                            {items.length}
                                        </span>
                                    </SectionHeading>
                                    {items.length === 0 && (
                                        <p className="text-xs text-muted-foreground">
                                            {t('explanation.lane_empty')}
                                        </p>
                                    )}
                                    {items.map((recipient) => {
                                        const href = settingsHref(recipient);
                                        return (
                                            <div
                                                key={recipient.channel_id}
                                                data-explained-channel={
                                                    recipient.channel_id
                                                }
                                                className="flex min-w-0 gap-2 rounded-lg border bg-card p-2.5"
                                            >
                                                <ChannelGlyph
                                                    channel={recipient.channel}
                                                />
                                                <div className="min-w-0 space-y-0.5">
                                                    <p className="truncate text-cell font-medium">
                                                        {href ? (
                                                            <TextLink
                                                                href={href}
                                                            >
                                                                {
                                                                    recipient.label
                                                                }
                                                            </TextLink>
                                                        ) : (
                                                            recipient.label
                                                        )}
                                                    </p>
                                                    <p className="text-xs text-muted-foreground">
                                                        {t(
                                                            `explanation.kind.${recipient.recipient_kind}`,
                                                        )}
                                                        {recipient.address &&
                                                            ` · ${recipient.address}`}
                                                    </p>
                                                    <p className="text-xs">
                                                        {t(
                                                            reasonKeys[
                                                                recipient.reason
                                                            ] ??
                                                                recipient.reason,
                                                        )}
                                                        {recipient.due_at &&
                                                            recipient.status !==
                                                                'now' && (
                                                                <>
                                                                    {' '}
                                                                    <AbsoluteTime
                                                                        value={
                                                                            recipient.due_at
                                                                        }
                                                                        timezone={
                                                                            result.timezone
                                                                        }
                                                                    />
                                                                </>
                                                            )}
                                                    </p>
                                                </div>
                                            </div>
                                        );
                                    })}
                                </section>
                            );
                        })}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
