import { ArrowDown, ArrowUp, Minus } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Line, LineChart, ResponsiveContainer } from 'recharts';
import { historySampleStatus } from '@/components/history-sample-marks';
import { SectionHeading } from '@/components/section-heading';
import {
    bucketColors,
    responseTimeSummary,
    sparklineSummary,
    statusColor,
} from '@/components/monitoring';
import { Badge } from '@/components/ui/badge';
import { Card, CardFooter, CardHeader } from '@/components/ui/card';
import { ChartContainer } from '@/components/ui/chart';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Tooltip,
    TooltipContent,
    TooltipDetails,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useFormat } from '@/hooks/use-format';
import { statusLabels } from '@/lib/status';
import type { ResponsePointDto, SiteState } from '@/types/monitoring';

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
