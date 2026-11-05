import { TooltipHint, TruncatedText } from '@/components/truncated-text';
import type { ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import {
    AlertCircle,
    Clock3,
    LoaderCircle,
    Check,
    X,
    List,
    Minus,
    ChevronRight,
} from 'lucide-react';
import {
    StatusDot,
    StatusBadge,
    CheckStrip,
    UptimeBar,
    Sparkline,
    MetricTile,
    EventTimeline,
    statusColor,
    ContentState,
    TableSkeleton,
} from '@/components/monitoring';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    CardAction,
} from '@/components/ui/card';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { Textarea } from '@/components/ui/textarea';
import { rowTargetClass } from '@/components/entity-link';
import { cn } from '@/lib/utils';

export {
    StatusDot,
    StatusBadge,
    CheckStrip,
    UptimeBar,
    Sparkline,
    MetricTile,
    EventTimeline,
    ContentState as EmptyState,
    TableSkeleton,
};

export function StateSentence({
    state,
    sentence,
    detail,
    action,
    suffix,
}: {
    state: Heptapod.Enums.SiteStatus;
    sentence: ReactNode;
    detail?: ReactNode;
    action?: ReactNode;
    suffix?: ReactNode;
}) {
    return (
        <div className="flex flex-wrap items-start justify-between gap-3">
            <div className="min-w-0 space-y-1">
                <h2 className="flex flex-wrap items-center gap-3 text-headline">
                    <StatusBadge state={state} />
                    {sentence}
                    {suffix}
                </h2>
                {detail && (
                    <p className="text-sm text-muted-foreground">{detail}</p>
                )}
            </div>
            {action}
        </div>
    );
}

export function ClientMark({
    name,
    initials,
    color,
    size = 'md',
}: {
    name: string;
    initials: string;
    color: string;
    size?: 'sm' | 'md';
}) {
    return (
        <span
            role="img"
            aria-label={name}
            className={`inline-flex shrink-0 items-center justify-center rounded-md font-mono font-semibold text-white ${size === 'sm' ? 'size-4 text-mark' : 'size-7 text-xs'}`}
            style={{ backgroundColor: color }}
        >
            {initials}
        </span>
    );
}

export function HealthIndicator({
    state,
    label,
    updatedAt,
    updatedAtDateTime,
}: {
    state: 'ok' | 'warning' | 'check-error';
    label: string;
    updatedAt: string;
    updatedAtDateTime: string;
}) {
    return (
        <div className="flex min-h-8 items-center gap-2 rounded-md border border-[var(--border-soft)] px-2 text-xs text-muted-foreground">
            <StatusDot state={state} label={label} />
            <TruncatedText content={label}>
                <span className="min-w-0 flex-1 truncate">{label}</span>
            </TruncatedText>
            <time
                dateTime={updatedAtDateTime}
                className="font-mono tabular-nums"
            >
                {updatedAt}
            </time>
        </div>
    );
}

export function Freshness({
    state,
    lastUpdated,
}: {
    state: 'live' | 'reconnecting' | 'offline';
    lastUpdated?: string;
}) {
    const { t } = useTranslation();
    return (
        <StatusBadge
            state={state === 'live' ? 'ok' : 'warning'}
            label={
                state === 'live'
                    ? t('Live')
                    : state === 'reconnecting'
                      ? t('Reconnecting · data from {{time}}', {
                            time: lastUpdated ?? '—',
                        })
                      : t('Offline')
            }
        />
    );
}

export function PeekSheet({
    title,
    description,
    trigger,
    children,
    open,
    onOpenChange,
}: {
    title: string;
    description: string;
    trigger?: ReactNode;
    children: ReactNode;
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
}) {
    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            {trigger && <SheetTrigger asChild>{trigger}</SheetTrigger>}
            <SheetContent className="w-full overflow-y-auto sm:max-w-[520px]">
                <SheetHeader>
                    <SheetTitle>{title}</SheetTitle>
                    <SheetDescription>{description}</SheetDescription>
                </SheetHeader>
                <div className="p-4">{children}</div>
            </SheetContent>
        </Sheet>
    );
}

export function EvidenceBlock({
    title,
    rows,
    wrap = false,
}: {
    title: string;
    rows: { label: string; value: string; exact?: string }[];
    wrap?: boolean;
}) {
    const valueText = (value: string) => (
        <span
            className={cn(
                'block font-mono text-xs',
                wrap ? 'break-words' : 'truncate',
            )}
        >
            {value}
        </span>
    );
    return (
        <Card>
            <CardHeader>
                <CardTitle>{title}</CardTitle>
            </CardHeader>
            <CardContent>
                <dl className="grid gap-2 text-sm">
                    {rows.map(({ label, value, exact }) => (
                        <div
                            key={label}
                            className="grid grid-cols-[8rem_minmax(0,1fr)] gap-3"
                        >
                            <dt className="text-muted-foreground">{label}</dt>
                            <dd className="min-w-0">
                                {exact ? (
                                    <TooltipHint content={exact}>
                                        {valueText(value)}
                                    </TooltipHint>
                                ) : (
                                    <TruncatedText content={value}>
                                        {valueText(value)}
                                    </TruncatedText>
                                )}
                            </dd>
                        </div>
                    ))}
                </dl>
            </CardContent>
        </Card>
    );
}

export function DiffBlock({
    before,
    after,
}: {
    before: string;
    after: string;
}) {
    const { t } = useTranslation();
    return (
        <div className="grid gap-2 sm:grid-cols-2">
            <EvidenceBlock
                title={t('Before')}
                rows={[{ label: t('Value'), value: before }]}
            />
            <EvidenceBlock
                title={t('After')}
                rows={[{ label: t('Value'), value: after }]}
            />
        </div>
    );
}

export function ExpiryRow({
    label,
    daysLeft,
    windowDays,
    state,
}: {
    label: string;
    daysLeft: number;
    windowDays: number;
    state: Heptapod.Enums.SiteStatus;
}) {
    const { t } = useTranslation();
    return (
        <div className="flex items-center gap-3 text-sm">
            <StatusDot state={state} />
            <TruncatedText content={label}>
                <span className="min-w-0 flex-1 truncate">{label}</span>
            </TruncatedText>
            <div className="h-1 w-28 overflow-hidden rounded-full bg-muted">
                <div
                    className="h-full"
                    style={{
                        backgroundColor: statusColor[state],
                        width: `${Math.min(100, Math.max(0, (daysLeft / windowDays) * 100))}%`,
                    }}
                />
            </div>
            <span className="font-mono text-xs tabular-nums">
                {t('{{count}} d', { count: daysLeft })}
            </span>
        </div>
    );
}

export function ScoreRing({ score, label }: { score: number; label: string }) {
    const color =
        score >= 90
            ? 'var(--status-ok)'
            : score >= 50
              ? 'var(--status-warn)'
              : 'var(--status-down)';
    return (
        <div
            role="img"
            aria-label={`${label}: ${score}/100`}
            className="grid size-14 place-items-center rounded-full font-mono text-sm tabular-nums"
            style={{
                background: `conic-gradient(${color} ${score}%, var(--muted) 0)`,
            }}
        >
            <span
                className="grid size-11 place-items-center rounded-full bg-card"
                style={{ color }}
            >
                {score}
            </span>
        </div>
    );
}

export type RunState = Heptapod.Data.Ui.CheckRunStateData['phase'];
const runIcons = {
    scheduled: Clock3,
    queued: List,
    running: LoaderCircle,
    done: Check,
    failed: X,
    late: AlertCircle,
    skipped: Minus,
    cancelled: Minus,
};
export function RunBar({
    state,
    description,
    progress,
    action,
    compact = false,
}: {
    state: RunState;
    description: ReactNode;
    progress?: number;
    action?: ReactNode;
    compact?: boolean;
}) {
    const { t } = useTranslation();
    const Icon = compact && state === 'late' ? Clock3 : runIcons[state];
    const color = cn(
        state === 'late' && 'text-[var(--status-warn)]',
        state === 'failed' && 'text-[var(--status-down)]',
        state === 'done' && 'text-[var(--status-ok)]',
    );
    const icon =
        state === 'running' ? (
            <StatusDot state="running" />
        ) : (
            <Icon
                className={cn(
                    compact ? 'size-3.5 shrink-0' : 'size-4 shrink-0',
                    color,
                )}
            />
        );
    if (compact) {
        return (
            <span
                className={cn(
                    'inline-flex items-center gap-1.5 text-xs font-medium',
                    !['running', 'late', 'failed', 'done'].includes(state) &&
                        'text-muted-foreground',
                    color,
                )}
            >
                {icon}
                {t(state[0]!.toUpperCase() + state.slice(1))}
            </span>
        );
    }
    return (
        <div className="flex min-h-10 items-center gap-3 rounded-full border bg-card px-3 py-2 text-sm">
            {icon}
            <span className="min-w-0 flex-1 text-[var(--foreground-secondary)]">
                {state === 'late' ? (
                    description
                ) : (
                    <>
                        {t(state[0]!.toUpperCase() + state.slice(1))} ·{' '}
                        {description}
                    </>
                )}
            </span>
            {progress !== undefined && (
                <progress
                    className="h-1.5 w-28 overflow-hidden rounded-full sm:w-48 [&::-moz-progress-bar]:bg-primary [&::-webkit-progress-bar]:bg-muted [&::-webkit-progress-value]:bg-primary"
                    value={progress}
                    max={100}
                    aria-label={t('Run progress')}
                />
            )}
            {action}
        </div>
    );
}

export function ScheduleCard({
    next,
    last,
    duration,
    interval,
    state,
    showRunsLink = true,
}: {
    next: ReactNode;
    last: ReactNode;
    duration: string;
    interval?: string;
    state?: RunState;
    showRunsLink?: boolean;
}) {
    const { t } = useTranslation();
    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('Schedule')}</CardTitle>
                {showRunsLink && (
                    <CardAction>
                        <Button variant="ghost" size="sm" disabled>
                            {t('Runs')} <ChevronRight className="size-4" />
                        </Button>
                    </CardAction>
                )}
            </CardHeader>
            <CardContent className="space-y-2">
                <dl className="grid grid-cols-[6rem_1fr] gap-y-2 text-xs">
                    {interval && (
                        <>
                            <dt className="text-muted-foreground">
                                {t('Every')}
                            </dt>
                            <dd className="font-mono tabular-nums">
                                {interval}
                            </dd>
                        </>
                    )}
                    <dt className="text-muted-foreground">{t('Next run')}</dt>
                    <dd className="font-mono tabular-nums">{next}</dd>
                    <dt className="text-muted-foreground">{t('Last run')}</dt>
                    <dd className="font-mono tabular-nums">{last}</dd>
                    <dt className="text-muted-foreground">
                        {t('Usually takes')}
                    </dt>
                    <dd className="font-mono tabular-nums">{duration}</dd>
                </dl>
                {state && <RunBar state={state} description={t('Run check')} />}
                {showRunsLink && (
                    <p className="text-xs text-[var(--foreground-faint)]">
                        {t('Every run is listed in Runs')}
                    </p>
                )}
            </CardContent>
        </Card>
    );
}

export function AttentionRow({
    site,
    summary,
    state,
    href,
}: {
    site: Heptapod.Data.Ui.SiteRowData;
    summary: string;
    state: Heptapod.Enums.SiteStatus;
    href: string;
}) {
    return (
        <Link
            href={href}
            prefetch
            className={cn(
                'flex min-h-10 items-center gap-3 border-b px-3 text-sm',
                rowTargetClass,
            )}
        >
            <StatusDot state={state} />
            <TruncatedText content={site.name}>
                <span className="min-w-0 flex-1 truncate">{site.name}</span>
            </TruncatedText>
            <TruncatedText content={summary}>
                <span className="hidden min-w-0 flex-1 truncate text-muted-foreground sm:block">
                    {summary}
                </span>
            </TruncatedText>
            <StatusBadge state={state} />
        </Link>
    );
}

export function RuleRow({
    name,
    detail,
    enabled,
}: {
    name: string;
    detail: string;
    enabled: boolean;
}) {
    return (
        <div className="flex min-h-10 items-center gap-3 border-b px-3 text-sm">
            <TruncatedText content={name}>
                <span className="min-w-0 flex-1 truncate">{name}</span>
            </TruncatedText>
            <span className="text-xs text-muted-foreground">{detail}</span>
            <StatusBadge
                state={enabled ? 'ok' : 'disabled'}
                label={enabled ? 'Enabled' : 'Disabled'}
            />
        </div>
    );
}

export function ShownOnceSecret({
    label,
    value,
}: {
    label: string;
    value: string;
}) {
    const { t } = useTranslation();
    return (
        <div className="space-y-2">
            <label htmlFor="one-time-secret" className="text-sm font-medium">
                {label}
            </label>
            <Textarea
                id="one-time-secret"
                readOnly
                value={value}
                spellCheck={false}
                autoComplete="off"
                className="min-h-20 resize-none border-primary bg-primary/10 font-mono break-all"
                onFocus={(event) => event.target.select()}
                aria-describedby="one-time-secret-help"
            />
            <p
                id="one-time-secret-help"
                className="text-xs text-muted-foreground"
            >
                {t('Copy now. You will not see this again.')}
            </p>
        </div>
    );
}

export function FoundationSkeleton() {
    return (
        <div className="space-y-3" aria-busy="true">
            <Skeleton className="h-6 w-1/2" />
            <Skeleton className="h-10 w-full" />
            <Skeleton className="h-10 w-full" />
        </div>
    );
}
