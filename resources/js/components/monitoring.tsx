import { listCardPadding, tableRowHeight } from '@/components/ui/table';
import { PresentationChanges } from '@/components/presentation-changes';
import { SiteAvatar } from '@/components/site-avatar';
import { EmptyState } from '@/components/empty-state';
import { AlertCircle, Globe } from 'lucide-react';
import { useLayoutEffect, useRef, useState, type CSSProperties } from 'react';
import { EntityLink } from '@/components/entity-link';
import { useTranslation } from 'react-i18next';
import type { TFunction } from 'i18next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Tooltip,
    TooltipContent,
    TooltipDetails,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { show as incidentShow } from '@/routes/incidents';
import { checkTypes } from '@/lib/check-types';
import {
    bucketStates,
    checkMark,
    type CheckMark,
    eventLabel,
    eventState,
    runState,
    stateCountOrder,
    statusLabels,
} from '@/lib/status';
import { useFormat } from '@/hooks/use-format';
import { useCheckHeadline } from '@/hooks/use-check-headline';
import type { MonitoringTimeMode } from '@/lib/monitor-time';
import { cn } from '@/lib/utils';
import type {
    CheckDto,
    CheckKey,
    EventDto,
    ResponsePointDto,
    SiteDto,
    SiteState,
    UptimeBucketDto,
} from '@/types/monitoring';
import { TextLink } from '@/components/text-link';

export const statusColor: Record<SiteState | 'running', string> = {
    running: 'var(--muted-foreground)',
    ok: 'var(--status-ok)',
    warning: 'var(--status-warn)',
    down: 'var(--status-down)',
    paused: 'var(--status-paused)',
    pending: 'var(--status-pending)',
    disabled: 'var(--status-disabled)',
    unavailable: 'var(--status-disabled)',
    maintenance: 'var(--status-info)',
    failed: 'var(--status-down)',
    'check-error': 'var(--status-error)',
};

export function StatusDot({
    state,
    label,
    color,
    strip = false,
    snoozed = false,
}: {
    state: SiteState | 'running';
    label?: string;
    color?: string;
    strip?: boolean;
    /** Alerts snoozed: the pause bars cut out of a disc in the result's colour. */
    snoozed?: boolean;
}) {
    const { t } = useTranslation();
    return (
        <span
            role="img"
            aria-label={t(label ?? statusLabels[state])}
            data-state={state}
            data-strip={strip || undefined}
            data-snoozed={snoozed || undefined}
            className="status-ring"
            style={{
                color: color ?? statusColor[state],
            }}
        />
    );
}
export function StatusBadge({
    state,
    label,
    snoozed = false,
}: {
    state: SiteState | 'running';
    label?: string;
    snoozed?: boolean;
}) {
    const { t } = useTranslation();
    return (
        <Badge
            variant="outline"
            aria-label={t(label ?? statusLabels[state])}
            data-state={state}
            className="status-badge"
            style={{ '--status-color': statusColor[state] } as CSSProperties}
        >
            <span aria-hidden className="inline-flex">
                <StatusDot
                    state={state}
                    color={state === 'down' ? 'currentColor' : undefined}
                    snoozed={snoozed}
                />
            </span>
            {t(label ?? statusLabels[state])}
        </Badge>
    );
}
const bucketLabels: Record<Heptapod.Enums.BucketStatus, string> = {
    ok: 'OK',
    warning: 'Warning',
    failed: 'Down',
    interrupted: 'Short interruptions',
    errored: 'Check error',
    maintenance: 'Maintenance',
    no_data: 'checks.pending',
};
export const bucketColors: Record<Heptapod.Enums.BucketStatus, string> = {
    ok: 'var(--status-ok)',
    warning: 'var(--status-warn)',
    failed: 'var(--status-down)',
    interrupted: 'var(--status-warn)',
    errored: 'var(--status-error)',
    maintenance: 'var(--status-info)',
    no_data: 'var(--status-pending)',
};
export function UptimeBar({
    buckets,
    mobileDays = 30,
}: {
    buckets: UptimeBucketDto[];
    mobileDays?: number;
}) {
    const { t } = useTranslation();
    const format = useFormat();
    const [selected, setSelected] = useState(buckets.length - 1);
    const [anchorOffset, setAnchorOffset] = useState(0);
    const bar = useRef<HTMLButtonElement>(null);
    const days = useRef<(HTMLSpanElement | null)[]>([]);
    useLayoutEffect(() => {
        const measure = () => {
            const start = matchMedia('(min-width: 640px)').matches
                ? 0
                : Math.max(0, buckets.length - mobileDays);
            if (selected < start) {
                setSelected(start);
                return;
            }
            const trigger = bar.current;
            const day = days.current[Math.min(selected, buckets.length - 1)];
            if (!trigger || !day) return;
            const rect = day.getBoundingClientRect();
            setAnchorOffset(
                rect.left -
                    trigger.getBoundingClientRect().left +
                    rect.width / 2,
            );
        };
        measure();
        const observer = new ResizeObserver(measure);
        if (bar.current) observer.observe(bar.current);
        return () => observer.disconnect();
    }, [selected, buckets.length, mobileDays]);
    if (![7, 30, 90].includes(buckets.length))
        throw new Error('UptimeBar requires 7, 30, or 90 buckets');
    const bucket = buckets[Math.min(selected, buckets.length - 1)];
    if (!bucket) throw new Error('Missing uptime bucket');
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <button
                    ref={bar}
                    type="button"
                    className="flex h-10 w-full items-center gap-0.5 rounded-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    aria-label={`${t('Uptime history')}. ${format.calendarDate(bucket.date)}: ${t(bucketLabels[bucket.status])}`}
                    onPointerMove={(event) => {
                        const box = event.currentTarget.getBoundingClientRect();
                        const start = matchMedia('(min-width: 640px)').matches
                            ? 0
                            : Math.max(0, buckets.length - mobileDays);
                        setSelected(
                            Math.min(
                                buckets.length - 1,
                                Math.max(
                                    start,
                                    start +
                                        Math.floor(
                                            ((event.clientX - box.left) /
                                                box.width) *
                                                (buckets.length - start),
                                        ),
                                ),
                            ),
                        );
                    }}
                    onKeyDown={(event) => {
                        if (
                            event.key === 'ArrowRight' ||
                            event.key === 'ArrowLeft'
                        ) {
                            event.preventDefault();
                            setSelected((value) =>
                                Math.min(
                                    buckets.length - 1,
                                    Math.max(
                                        matchMedia('(min-width: 640px)').matches
                                            ? 0
                                            : Math.max(
                                                  0,
                                                  buckets.length - mobileDays,
                                              ),
                                        value +
                                            (event.key === 'ArrowRight'
                                                ? 1
                                                : -1),
                                    ),
                                ),
                            );
                        }
                    }}
                >
                    {buckets.map((item, index) => (
                        <span
                            key={item.date}
                            ref={(element) => {
                                days.current[index] = element;
                            }}
                            data-uptime-day={item.date}
                            data-selected={index === selected || undefined}
                            aria-hidden
                            className={cn(
                                'h-6 min-w-0 flex-1 rounded-xs',
                                index < buckets.length - mobileDays &&
                                    'hidden sm:block',
                                (item.status === 'ok' ||
                                    item.status === 'no_data') &&
                                    'opacity-35',
                            )}
                            style={{
                                backgroundColor: bucketColors[item.status],
                                backgroundImage:
                                    item.uptime === null
                                        ? 'repeating-linear-gradient(135deg, transparent, transparent 2px, var(--muted-foreground) 2px, var(--muted-foreground) 3px)'
                                        : undefined,
                            }}
                        />
                    ))}
                </button>
            </TooltipTrigger>
            <TooltipContent align="start" alignOffset={anchorOffset}>
                <UptimeTooltipContent bucket={bucket} />
            </TooltipContent>
        </Tooltip>
    );
}
export function UptimeTooltipContent({ bucket }: { bucket: UptimeBucketDto }) {
    const { t } = useTranslation();
    const format = useFormat();
    const noOutage = bucket.failed_count > 0 && bucket.uptime === 100;
    return (
        <TooltipDetails
            subject={format.calendarDate(bucket.date)}
            facts={[
                bucket.uptime === null
                    ? t(
                          bucket.status === 'no_data'
                              ? 'Awaiting first result'
                              : 'No measured uptime',
                      )
                    : t(
                          noOutage
                              ? 'uptime.percentage_no_outage'
                              : 'uptime.percentage',
                          {
                              uptime: format.uptimePercentage(
                                  bucket.uptime,
                                  noOutage ? 0 : 2,
                              ),
                          },
                      ),
                `${t('Incidents')}: ${format.number(bucket.incident_count)}`,
            ]}
            status={{
                state: bucketStates[bucket.status],
                label: `${t(bucketLabels[bucket.status])} · ${t('uptime.failed_checks', { count: bucket.failed_count })}${bucket.failed_seconds > 0 ? ` · ${t('uptime.failed_duration', { duration: format.duration(bucket.failed_seconds) })}` : ''}`,
            }}
            hint={t('Use arrow keys to inspect history.')}
        />
    );
}
export function sparklineSummary(
    data: ResponsePointDto[],
    t: TFunction,
): string {
    const counts = data.reduce(
        (total, point) => ({
            failure: total.failure + (point.failure_count ?? 0),
            error: total.error + (point.error_count ?? 0),
            warning: total.warning + (point.warning_count ?? 0),
        }),
        { failure: 0, error: 0, warning: 0 },
    );
    return [
        counts.failure > 0 &&
            t('sparkline.failures', { count: counts.failure }),
        counts.error > 0 && t('sparkline.errors', { count: counts.error }),
        counts.warning > 0 &&
            t('sparkline.warnings', { count: counts.warning }),
    ]
        .filter(Boolean)
        .join(' · ');
}

export function responseTimeSummary(
    data: ResponsePointDto[],
    t: TFunction,
    format: ReturnType<typeof useFormat>,
): string {
    const values = data.flatMap((point) =>
        point.value === null ? [] : [point.value],
    );
    const peak = values.length === 0 ? null : Math.max(...values);
    return [
        peak === null
            ? t('Response time 24 h: no recorded values.')
            : t('Response time 24 h: peak :peak', {
                  peak: format.responseTime(peak),
              }),
        sparklineSummary(data, t),
    ]
        .filter(Boolean)
        .join(' · ');
}

/** Expands a compact list series into sparkline points. Slots without a value or mark had no bucket and are skipped, as the list always has. */
export function listResponsePoints(
    series: Heptapod.Data.Ui.ListResponseSeriesData,
): ResponsePointDto[] {
    const start = Date.parse(series.start);
    const marks = new Map(series.marks.map((mark) => [mark.slot, mark]));
    return series.values.flatMap((value, slot) => {
        const mark = marks.get(slot);
        if (value === null && mark === undefined) return [];
        return [
            {
                time: new Date(
                    start + slot * series.step_seconds * 1000,
                ).toISOString(),
                value,
                failure_count: mark?.failure_count ?? 0,
                error_count: mark?.error_count ?? 0,
                warning_count: mark?.warning_count ?? 0,
                failure_status: mark?.failure_status ?? null,
            },
        ];
    });
}

const checkStripOrder: CheckKey[] = [
    'uptime',
    'performance',
    'certificate_health',
    'domain',
    'dns',
    'broken_links',
    'mixed_content',
    'sitemap',
    'lighthouse',
    'cron',
    'application_health',
];
const checkSummaryOrder = [
    'No current data',
    'freshness.not_fresh',
    // A running check sits between the attention states and the quiet ones.
    ...stateCountOrder.flatMap((state) =>
        state === 'pending'
            ? ['Running', statusLabels[state]]
            : state === 'disabled'
              ? ['Turned off']
              : [statusLabels[state]],
    ),
];

/** One strip slot: dashed when not set up, empty when turned off, otherwise the status shape (snoozed: bars cut out). */
function CheckMarkGlyph({ mark }: { mark: CheckMark }) {
    switch (mark.kind) {
        case 'unset':
            return (
                <span className="size-[11px] rounded-[2px] border border-dashed border-border-strong bg-card opacity-50" />
            );
        case 'off':
            return <span className="size-[11px]" />;
        case 'snoozed':
            return <StatusDot state={mark.state} snoozed strip />;
        case 'status':
            return <StatusDot state={mark.state} label={mark.label} strip />;
    }
}

export function CheckStrip({
    checks,
    compact = false,
    runningCheckIds = [],
}: {
    checks: CheckDto[];
    compact?: boolean;
    runningCheckIds?: readonly number[];
}) {
    const { t } = useTranslation();
    const format = useFormat();
    const headline = useCheckHeadline();
    const [focusedKey, setFocusedKey] = useState<CheckKey>(
        () =>
            checkStripOrder.find((key) =>
                checks.some((check) => check.key === key),
            ) ?? 'uptime',
    );
    const triggers = useRef<(HTMLSpanElement | null)[]>([]);
    const now = Date.now();
    const markOf = (check: CheckDto | undefined): CheckMark =>
        checkMark(check, {
            running:
                check !== undefined &&
                !check.pending_recheck &&
                runningCheckIds.includes(check.id),
            now,
        });
    // A snoozed check counts under its result, so the summary never hides a failure.
    const states = checks.map((check) => {
        const mark = markOf(check);
        return mark.kind === 'snoozed' ? statusLabels[mark.state] : mark.label;
    });
    const summary = checkSummaryOrder
        .map((state) => ({
            state,
            count: states.filter((value) => value === state).length,
        }))
        .filter(({ count }) => count > 0)
        .map(({ state, count }) =>
            t('checks.strip.state', { count, state: t(state) }),
        )
        .join(', ');
    return (
        <div
            role="group"
            aria-label={
                checks.length > 0
                    ? t('checks.strip.summary', {
                          count: checks.length,
                          states: summary,
                      })
                    : t('No checks configured')
            }
            className={cn(
                'flex flex-wrap items-center gap-1',
                compact && 'flex-nowrap gap-px',
            )}
        >
            {checkStripOrder.map((key, index) => {
                const check = checks.find((item) => item.key === key);
                const label = t(
                    check ? check.label_key : checkTypes[key].label,
                );
                const mark = markOf(check);
                const description =
                    mark.kind === 'snoozed'
                        ? `${t('Snoozed until {{time}}', { time: format.timestamp(mark.until) })} · ${t(statusLabels[mark.state])}`
                        : t(mark.label);
                const statusLine =
                    check === undefined || mark.kind === 'off'
                        ? description
                        : check.freshness_suppressed_by &&
                            mark.kind === 'status'
                          ? headline(check)
                          : `${description} · ${check.unavailable_reason ? t(check.unavailable_reason) : headline(check)}`;
                return (
                    <Tooltip key={key}>
                        <TooltipTrigger asChild>
                            <span
                                ref={(element) => {
                                    triggers.current[index] = element;
                                }}
                                role="img"
                                tabIndex={key === focusedKey ? 0 : -1}
                                aria-label={`${label} · ${description}`}
                                data-check-mark={mark.kind}
                                onFocus={() => setFocusedKey(key)}
                                onKeyDown={(event) => {
                                    if (
                                        event.key !== 'ArrowLeft' &&
                                        event.key !== 'ArrowRight'
                                    )
                                        return;
                                    event.preventDefault();
                                    const next = Math.max(
                                        0,
                                        Math.min(
                                            checkStripOrder.length - 1,
                                            index +
                                                (event.key === 'ArrowRight'
                                                    ? 1
                                                    : -1),
                                        ),
                                    );
                                    triggers.current[next]?.focus();
                                }}
                                className={cn(
                                    'flex size-10 items-center justify-center focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:size-[11px]',
                                    compact && 'sm:shrink-0',
                                )}
                            >
                                <CheckMarkGlyph mark={mark} />
                            </span>
                        </TooltipTrigger>
                        <TooltipContent>
                            <TooltipDetails
                                subject={label}
                                facts={
                                    check && mark.kind !== 'off'
                                        ? [
                                              // A suppressed gap shows its reason instead; the stored last result would read as a current status.
                                              ...(check.freshness_suppressed_by &&
                                              mark.kind === 'status'
                                                  ? []
                                                  : [
                                                        `${t('Last result')}: ${t(statusLabels[runState(check.key, check.result)])}`,
                                                    ]),
                                              ...(check.checked_at
                                                  ? [
                                                        format.exact(
                                                            check.checked_at,
                                                        ),
                                                    ]
                                                  : []),
                                          ]
                                        : []
                                }
                                status={{
                                    state:
                                        mark.kind === 'unset' ||
                                        mark.kind === 'off'
                                            ? 'disabled'
                                            : mark.state,
                                    label: statusLine,
                                }}
                                hint={t('tooltip.check_navigation')}
                            />
                        </TooltipContent>
                    </Tooltip>
                );
            })}
        </div>
    );
}
export function MonitoringTime({
    value,
    className,
    prefix,
    mode,
    label: suppliedLabel,
}: {
    value: string;
    className?: string;
    prefix?: string;
    mode?: MonitoringTimeMode;
    label?: string;
}) {
    const { monitorTime } = useFormat();
    const { label, exact } = monitorTime(value, mode);
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <time dateTime={value} tabIndex={0} className={className}>
                    {prefix && `${prefix} `}
                    {suppliedLabel ?? label}
                </time>
            </TooltipTrigger>
            <TooltipContent className="font-mono tabular-nums">
                {exact}
            </TooltipContent>
        </Tooltip>
    );
}

export function EventTimeline({
    events,
    showSiteName = false,
}: {
    events: EventDto[];
    showSiteName?: boolean;
}) {
    const { t } = useTranslation();
    const format = useFormat();
    return (
        <ol className="space-y-0">
            {events.map((event) => {
                let message = t(event.message);
                if (
                    event.severity === 'recovered' &&
                    event.duration_seconds !== null &&
                    event.message_params.result_change !== true &&
                    event.message_params.presentation !== 'direct'
                ) {
                    message = t('events.recovered_after', {
                        duration: format.duration(event.duration_seconds),
                    });
                    if (typeof event.message_params.component === 'string') {
                        message = t('events.component_status', {
                            component: event.message_params.component,
                            message,
                        });
                    }
                }
                const incidentId = event.incident_id;
                return (
                    <li
                        key={event.id}
                        className="relative flex gap-4 border-l pb-6 pl-4 last:pb-0"
                    >
                        <span className="absolute top-1 -left-1">
                            <StatusDot
                                state={eventState(event)}
                                label={eventLabel(event)}
                            />
                        </span>
                        <div className="min-w-0 space-y-1">
                            <div className="grid grid-cols-[6rem_1fr] items-baseline gap-4">
                                <MonitoringTime
                                    value={event.time}
                                    className="font-mono text-xs text-muted-foreground tabular-nums"
                                />

                                <span className="text-sm">
                                    {showSiteName && (
                                        <>
                                            <EntityLink
                                                name={event.site_name}
                                                href={
                                                    event.site_id === null
                                                        ? undefined
                                                        : `/sites/${event.site_id}`
                                                }
                                                className="align-middle"
                                                icon={
                                                    event.site_id ===
                                                    null ? undefined : (
                                                        <SiteAvatar
                                                            name={
                                                                event.site_name
                                                            }
                                                            iconUrl={
                                                                event.icon_url
                                                            }
                                                            iconBacking={
                                                                event.icon_backing
                                                            }
                                                        />
                                                    )
                                                }
                                            />{' '}
                                            ·{' '}
                                        </>
                                    )}
                                    {incidentId != null ? (
                                        <TextLink
                                            href={incidentShow.url(incidentId)}
                                            prefetch
                                        >
                                            {message}
                                        </TextLink>
                                    ) : (
                                        message
                                    )}
                                </span>
                            </div>
                            <p className="text-xs text-muted-foreground">
                                <PresentationChanges changes={event.changes} />
                                {event.changes.length > 0 && ' · '}
                                {event.check !== null &&
                                    t(checkTypes[event.check].label)}
                                {event.duration_seconds !== null &&
                                    event.duration_seconds > 0 && (
                                        <>
                                            {' '}
                                            ·{' '}
                                            <span className="font-mono tabular-nums">
                                                {format.duration(
                                                    event.duration_seconds,
                                                )}
                                            </span>
                                        </>
                                    )}
                            </p>
                        </div>
                    </li>
                );
            })}
        </ol>
    );
}
export function SiteNameCell({
    site,
    compact = false,
    href,
}: {
    href?: string;
    site: Pick<SiteDto, 'name' | 'url'> & {
        identity?: Heptapod.Data.Ui.SiteIdentityData;
    };
    compact?: boolean;
}) {
    return (
        <div
            className={cn(
                'flex min-w-0 gap-x-3',
                compact ? 'flex-col gap-y-1' : 'flex-nowrap items-baseline',
            )}
        >
            <EntityLink
                name={site.name}
                href={href}
                icon={
                    <SiteAvatar
                        name={site.name}
                        iconUrl={site.identity?.icon_url}
                        iconBacking={site.identity?.icon_backing}
                    />
                }
                tooltip={
                    compact ? (
                        <TooltipDetails
                            subject={site.name}
                            facts={[site.url]}
                        />
                    ) : (
                        site.name
                    )
                }
            />
            <Tooltip>
                <TooltipTrigger asChild>
                    <span
                        tabIndex={href ? -1 : 0}
                        className={cn(
                            'hidden max-w-full min-w-0 truncate font-mono text-xs text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                            compact ? 'block' : 'xl:inline',
                        )}
                    >
                        {site.url}
                    </span>
                </TooltipTrigger>
                <TooltipContent>{site.url}</TooltipContent>
            </Tooltip>
        </div>
    );
}
export function ContentState({
    state,
    onAction,
    title,
    description,
    actionLabel,
    bare,
}: {
    state: 'empty' | 'error';
    onAction?: () => void;
    title?: string;
    description?: string;
    actionLabel?: string;
    bare?: boolean;
}) {
    const { t } = useTranslation();
    return (
        <EmptyState
            bare={bare}
            icon={state === 'error' ? AlertCircle : Globe}
            title={t(
                title ||
                    (state === 'error'
                        ? 'Checks could not be loaded'
                        : 'Start monitoring your first site'),
            )}
            description={t(
                description ||
                    (state === 'error'
                        ? 'Try again. Your configuration is safe.'
                        : 'Add a URL to begin collecting results.'),
            )}
            action={
                onAction && (
                    <Button onClick={onAction}>
                        {t(
                            actionLabel ||
                                (state === 'error' ? 'Retry' : 'Add site'),
                        )}
                    </Button>
                )
            }
        />
    );
}
export function TableSkeleton({
    rows = 3,
    full = false,
}: {
    rows?: number;
    full?: boolean;
}) {
    const { t } = useTranslation();
    const grid = full
        ? 'grid-cols-[50%_25%_25%] @[1000px]/table:grid-cols-[30%_12%_26%_10%_10%_12%]'
        : 'grid-cols-[45%_25%_30%]';
    return (
        <div
            role="status"
            aria-label={t('Loading sites')}
            className="@container/table rounded-lg border"
        >
            <span className="sr-only">{t('Loading sites')}</span>
            <div className="hidden sm:block">
                <div
                    className={cn(
                        'grid h-10 items-center border-b text-xs text-muted-foreground',
                        grid,
                    )}
                >
                    <span className="px-4">{t('Site')}</span>
                    {full ? (
                        ['Client', 'Checks', '24h response'].map((label) => (
                            <span
                                key={label}
                                className="hidden px-4 @[1000px]/table:block"
                            >
                                {t(label)}
                            </span>
                        ))
                    ) : (
                        <span className="px-4">{t('Status')}</span>
                    )}
                    <span className="px-4 text-right">
                        {t('30-day uptime')}
                    </span>
                    {full && (
                        <span className="px-4 text-right">
                            {t('Last checked')}
                        </span>
                    )}
                </div>
                {Array.from({ length: rows }, (_, index) => (
                    <div
                        key={index}
                        className={cn(
                            'grid items-center border-b last:border-0',
                            tableRowHeight,
                            grid,
                        )}
                    >
                        <div className="flex items-center gap-3 px-4">
                            <Skeleton className="size-4 shrink-0" />
                            <Skeleton className="size-2 shrink-0" />
                            <Skeleton className="h-4 w-48 max-w-[60%]" />
                        </div>
                        {full ? (
                            ['client', 'checks', 'response'].map((column) => (
                                <div
                                    key={column}
                                    className="hidden px-4 @[1000px]/table:block"
                                >
                                    <Skeleton className="h-5 w-full" />
                                </div>
                            ))
                        ) : (
                            <div className="px-4">
                                <Skeleton className="h-5 w-24" />
                            </div>
                        )}
                        <div className="px-4">
                            <Skeleton className="ml-auto h-4 w-16" />
                        </div>
                        {full && (
                            <div className="px-4">
                                <Skeleton className="ml-auto h-4 w-16" />
                            </div>
                        )}
                    </div>
                ))}
            </div>
            <ul className="divide-y sm:hidden">
                {Array.from({ length: rows }, (_, index) => (
                    <li key={index} className="relative">
                        <Skeleton className="absolute top-3 right-3 size-4" />
                        <div
                            className={cn(
                                'flex flex-col gap-3 pr-12',
                                listCardPadding,
                            )}
                        >
                            <div className="flex h-5 w-full items-center gap-3">
                                <Skeleton className="size-2 shrink-0" />
                                <Skeleton className="h-4 w-48 max-w-[60%]" />
                            </div>
                            <div className="flex h-[22px] w-full items-center justify-between gap-2">
                                <Skeleton className="h-[22px] w-24" />
                                <Skeleton className="h-4 w-16" />
                            </div>
                        </div>
                    </li>
                ))}
            </ul>
        </div>
    );
}
