import { listCardPadding, tableRowHeight } from '@/components/ui/table';
import { PresentationChanges } from '@/components/presentation-changes';
import { SiteAvatar } from '@/components/site-avatar';
import { EmptyState } from '@/components/empty-state';
import { historySampleStatus } from '@/components/history-sample-marks';
import {
    AlertCircle,
    ArrowDown,
    ArrowUp,
    ChevronRight,
    Globe,
    Minus,
} from 'lucide-react';
import { useLayoutEffect, useRef, useState, type CSSProperties } from 'react';
import { Link } from '@inertiajs/react';
import { EntityLink } from '@/components/entity-link';
import { useTranslation } from 'react-i18next';
import type { TFunction } from 'i18next';
import { Line, LineChart, ResponsiveContainer } from 'recharts';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardAction,
    CardContent,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { ChartContainer } from '@/components/ui/chart';
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
    checkState,
    eventLabel,
    eventState,
    runState,
    stateCountOrder,
    statusLabels,
} from '@/lib/status';
import { useFormat } from '@/hooks/use-format';
import { useCheckHeadline } from '@/hooks/use-check-headline';
import type { MonitoringTimeMode } from '@/lib/monitor-time';
import { focusRingClass, rowHoverClass } from '@/lib/interaction';
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
import { SectionHeading } from '@/components/section-heading';

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
}: {
    state: SiteState | 'running';
    label?: string;
    color?: string;
    strip?: boolean;
}) {
    const { t } = useTranslation();
    return (
        <span
            role="img"
            aria-label={t(label ?? statusLabels[state])}
            data-state={state}
            data-strip={strip || undefined}
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
}: {
    state: SiteState | 'running';
    label?: string;
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
const bucketColors: Record<Heptapod.Enums.BucketStatus, string> = {
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

export function Sparkline({
    data,
    state = 'ok',
    label = 'Response time',
    unit = 'ms',
    decorative = false,
}: {
    data: ResponsePointDto[];
    state?: SiteState;
    label?: string;
    unit?: 'ms' | 'percent' | 'count';
    decorative?: boolean;
}) {
    const { t } = useTranslation();
    const format = useFormat();
    const [selected, setSelected] = useState<number | null>(null);
    // One sample is no trend: Recharts would draw it as a lone dot. Marks (failures, errors, warnings) still render.
    const hasLine = data.filter((point) => point.value !== null).length >= 2;
    const hasMarks = data.some(
        (point) =>
            (point.failure_count ?? 0) > 0 ||
            point.failure_status != null ||
            (point.error_count ?? 0) > 0 ||
            (point.warning_count ?? 0) > 0,
    );
    if (!hasLine && !hasMarks)
        return (
            <span className="flex h-6 w-20 items-center justify-end text-xs text-muted-foreground">
                {t(statusLabels[state])}
            </span>
        );
    const summary = sparklineSummary(data, t);
    const tooltipSummary =
        unit === 'ms'
            ? responseTimeSummary(data, t, format)
            : [t(label), summary].filter(Boolean).join(' · ');
    const point = selected === null ? undefined : data[selected];
    const values = (point ? [point] : data).flatMap((item) =>
        item.value === null ? [] : [item.value],
    );
    const peak = values.length ? Math.max(...values) : null;
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span
                    className="relative block h-6 w-20 rounded-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    tabIndex={decorative ? undefined : 0}
                    onFocus={() => setSelected(null)}
                    onPointerLeave={() => setSelected(null)}
                    onPointerMove={(event) => {
                        const rect =
                            event.currentTarget.getBoundingClientRect();
                        setSelected(
                            Math.max(
                                0,
                                Math.min(
                                    data.length - 1,
                                    Math.round(
                                        ((event.clientX - rect.left) /
                                            rect.width) *
                                            (data.length - 1),
                                    ),
                                ),
                            ),
                        );
                    }}
                    onKeyDown={(event) => {
                        if (
                            event.key !== 'ArrowLeft' &&
                            event.key !== 'ArrowRight'
                        )
                            return;
                        event.preventDefault();
                        setSelected((index) =>
                            Math.max(
                                0,
                                Math.min(
                                    data.length - 1,
                                    (index ?? -1) +
                                        (event.key === 'ArrowRight' ? 1 : -1),
                                ),
                            ),
                        );
                    }}
                    role={decorative ? undefined : 'img'}
                    aria-label={decorative ? undefined : tooltipSummary}
                    aria-hidden={decorative || undefined}
                >
                    <ResponsiveContainer width={80} height={24}>
                        <ChartContainer
                            config={{
                                value: {
                                    label: t(label),
                                    color:
                                        state === 'ok'
                                            ? 'var(--muted-foreground)'
                                            : statusColor[state],
                                },
                            }}
                            className="h-6 w-20 shrink-0"
                            aria-label={decorative ? undefined : t(label)}
                        >
                            <LineChart
                                data={data}
                                margin={{
                                    top: 2,
                                    right: 0,
                                    bottom: 2,
                                    left: 0,
                                }}
                                accessibilityLayer={false}
                            >
                                {hasLine && (
                                    <Line
                                        type="monotone"
                                        dataKey="value"
                                        stroke="var(--color-value)"
                                        strokeWidth={1.5}
                                        dot={false}
                                        isAnimationActive={false}
                                    />
                                )}
                            </LineChart>
                        </ChartContainer>
                    </ResponsiveContainer>
                    <svg
                        className="pointer-events-none absolute inset-0"
                        width={80}
                        height={24}
                        aria-hidden="true"
                    >
                        {data.map((point, index) => {
                            const x =
                                data.length === 1
                                    ? 39
                                    : (index * 78) / (data.length - 1);
                            return (
                                <g key={point.time}>
                                    {((point.failure_count ?? 0) > 0 ||
                                        point.failure_status != null) && (
                                        <rect
                                            data-sparkline-failure
                                            x={x}
                                            y={16}
                                            width={2}
                                            height={8}
                                            fill={
                                                bucketColors[
                                                    point.failure_status ??
                                                        'failed'
                                                ]
                                            }
                                            pointerEvents="all"
                                        ></rect>
                                    )}
                                    {(point.error_count ?? 0) > 0 && (
                                        <rect
                                            data-sparkline-error
                                            x={x}
                                            y={8}
                                            width={2}
                                            height={8}
                                            fill={statusColor['check-error']}
                                            pointerEvents="all"
                                        ></rect>
                                    )}
                                    {(point.warning_count ?? 0) > 0 && (
                                        <rect
                                            data-sparkline-warning
                                            x={x}
                                            y={0}
                                            width={2}
                                            height={8}
                                            fill={statusColor.warning}
                                            pointerEvents="all"
                                        ></rect>
                                    )}
                                </g>
                            );
                        })}
                    </svg>
                </span>
            </TooltipTrigger>
            <TooltipContent>
                <TooltipDetails
                    subject={t(label)}
                    facts={[
                        point ? format.exact(point.time) : t('24 hours'),
                        peak === null
                            ? t('No data')
                            : `${t('Maximum')}: ${unit === 'ms' ? format.responseTime(peak) : format.number(peak) + (unit === 'percent' ? ' %' : '')}`,
                    ]}
                    status={historySampleStatus(
                        point ? [point] : data,
                        t,
                        unit === 'ms' ? 'down' : 'failed',
                        point ? 'bucket' : 'range',
                    )}
                    hint={
                        decorative
                            ? undefined
                            : t('Use arrow keys to inspect history.')
                    }
                />
            </TooltipContent>
        </Tooltip>
    );
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
            : [statusLabels[state]],
    ),
];

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
    const states = checks.map((check) =>
        check.freshness_suppressed_by
            ? 'No current data'
            : check.not_fresh
              ? 'freshness.not_fresh'
              : !check.pending_recheck && runningCheckIds.includes(check.id)
                ? 'Running'
                : statusLabels[checkState(check)],
    );
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
                const state = check ? checkState(check) : null;
                const isRunning =
                    check !== undefined &&
                    !check.pending_recheck &&
                    runningCheckIds.includes(check.id);
                const description = check?.freshness_suppressed_by
                    ? t('No current data')
                    : check?.not_fresh
                      ? t('freshness.not_fresh')
                      : state === null
                        ? t('Not configured')
                        : isRunning
                          ? t('Running')
                          : t(statusLabels[state]);
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
                                {state === null ? (
                                    <span className="size-[11px] rounded-[2px] border border-dashed border-border-strong bg-card opacity-50" />
                                ) : (
                                    <StatusDot
                                        state={
                                            check?.freshness_suppressed_by
                                                ? 'disabled'
                                                : check?.not_fresh
                                                  ? 'check-error'
                                                  : isRunning
                                                    ? 'running'
                                                    : state
                                        }
                                        label={description}
                                        strip
                                    />
                                )}
                            </span>
                        </TooltipTrigger>
                        <TooltipContent>
                            <TooltipDetails
                                subject={label}
                                facts={
                                    check
                                        ? [
                                              // A suppressed gap shows its reason instead; the stored last result would read as a current status.
                                              ...(check.freshness_suppressed_by
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
                                    state: check?.freshness_suppressed_by
                                        ? 'disabled'
                                        : check?.not_fresh
                                          ? 'check-error'
                                          : isRunning
                                            ? 'running'
                                            : (state ?? 'disabled'),
                                    label: check?.freshness_suppressed_by
                                        ? headline(check)
                                        : `${description}${check ? ` · ${check.unavailable_reason ? t(check.unavailable_reason) : headline(check)}` : ''}`,
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
export function MetricTile({
    label,
    value,
    delta,
    data,
    loading = false,
    state = 'ok',
    unit = 'count',
}: {
    state?: SiteState;
    unit?: 'count' | 'percent' | 'ms';
    label: string;
    value: string;
    delta?: string;
    data: ResponsePointDto[];
    loading?: boolean;
}) {
    const { t } = useTranslation();
    return (
        <Card className="min-w-0">
            <CardHeader>
                <SectionHeading
                    className={
                        loading || delta || data.length ? 'min-h-8' : undefined
                    }
                >
                    {label}
                </SectionHeading>
                <div className="font-mono text-stat tabular-nums">
                    {loading ? <Skeleton className="h-8 w-24" /> : value}
                </div>
            </CardHeader>
            {(loading || delta || data.length > 0) && (
                <CardFooter className="min-h-14 justify-between text-xs text-muted-foreground">
                    {delta && (
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <Badge
                                    variant="outline"
                                    className={
                                        loading ? 'invisible' : undefined
                                    }
                                    aria-hidden={loading}
                                    tabIndex={loading ? -1 : 0}
                                    aria-label={`${delta} · ${t('vs. previous period')}`}
                                >
                                    {delta === '0' ? (
                                        <Minus className="size-3" />
                                    ) : delta.startsWith('-') ? (
                                        <ArrowDown className="size-3" />
                                    ) : (
                                        <ArrowUp className="size-3" />
                                    )}
                                    {delta}
                                </Badge>
                            </TooltipTrigger>
                            <TooltipContent>
                                {t('vs. previous period')}
                            </TooltipContent>
                        </Tooltip>
                    )}
                    {loading ? (
                        <Skeleton className="h-6 w-20" />
                    ) : data.length ? (
                        <Sparkline
                            data={data}
                            state={state}
                            label={label}
                            unit={unit}
                        />
                    ) : (
                        <span className="h-6 w-20" aria-hidden />
                    )}
                </CardFooter>
            )}
        </Card>
    );
}
export function CheckCard({
    check,
    loading = false,
    href,
}: {
    check: CheckDto;
    loading?: boolean;
    href?: string;
}) {
    const { t } = useTranslation();
    const headlineFor = useCheckHeadline();
    const headline = headlineFor(check);
    return (
        <Card
            className={cn(
                'relative min-w-0',
                href && !loading && rowHoverClass,
            )}
        >
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <StatusDot
                        state={check.enabled ? checkState(check) : 'disabled'}
                        label={
                            check.freshness_suppressed_by
                                ? 'No current data'
                                : undefined
                        }
                    />
                    <span>{t(check.label_key)}</span>
                    <span className="ml-auto font-mono text-label font-normal text-muted-foreground">
                        {!check.enabled
                            ? t('Off')
                            : check.checked_at && (
                                  <MonitoringTime value={check.checked_at} />
                              )}
                    </span>
                </CardTitle>
                {href && !loading && (
                    <CardAction>
                        <Link
                            href={href}
                            preserveScroll
                            prefetch
                            aria-label={t(
                                check.open_incident_id !== null
                                    ? 'Open incident: {{check}}'
                                    : 'Open check: {{check}}',
                                {
                                    check: t(check.label_key),
                                },
                            )}
                            className={cn(
                                'rounded-sm after:absolute after:inset-0',
                                focusRingClass,
                            )}
                        >
                            <ChevronRight className="size-4" />
                        </Link>
                    </CardAction>
                )}
            </CardHeader>
            <CardContent>
                {loading ? (
                    <Skeleton className="h-8 w-40 max-w-full" />
                ) : (
                    <p className="text-cell leading-relaxed text-muted-foreground">
                        {headline}
                        {check.presentation &&
                            !check.freshness_suppressed_by &&
                            !check.pending_recheck && (
                                <span className="block">
                                    <PresentationChanges
                                        changes={check.presentation.changes}
                                        details={check.presentation.details}
                                    />
                                </span>
                            )}
                    </p>
                )}
            </CardContent>
        </Card>
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
