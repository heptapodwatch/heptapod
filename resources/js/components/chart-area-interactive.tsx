import {
    HistorySampleMarks,
    historySampleStatus,
    useHistorySampleSummary,
} from '@/components/history-sample-marks';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
    Area,
    ComposedChart,
    Line,
    CartesianGrid,
    XAxis,
    YAxis,
} from 'recharts';
import {
    Card,
    CardAction,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    ChartContainer,
    ChartLegend,
    ChartLegendContent,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { SegmentedControl } from '@/components/segmented-control';
import { Skeleton } from '@/components/ui/skeleton';
import { useFormat } from '@/hooks/use-format';

const rangeLabels: Record<Heptapod.Enums.ResponseTimeRange, string> = {
    '24h': '24 hours',
    '7d': '7 days',
    '30d': '30 days',
    '90d': '90 days',
};
export function ChartAreaInteractive({
    series,
    loading = false,
}: {
    series: Heptapod.Data.Ui.ResponseTimeSeriesData[];
    loading?: boolean;
}) {
    const { t } = useTranslation();
    const format = useFormat();
    const [range, setRange] = useState<Heptapod.Enums.ResponseTimeRange>('24h');
    const active = series.find((period) => period.key === range) ?? series[0];
    if (!active) throw new Error('Response chart requires at least one range');
    const sampleSummary = useHistorySampleSummary(active.points);
    const hasPeaks = active.points.some(
        (point) =>
            point.maximum_ms !== null ||
            point.failure_count > 0 ||
            point.error_count > 0 ||
            point.warning_count > 0,
    );
    const responseKey = hasPeaks ? 'maximum_ms' : 'average_ms';
    const hasResponseValues = active.points.some(
        (point) => point[responseKey] !== null || point.p95_ms !== null,
    );
    const hasP95 = active.points.some((point) => point.p95_ms !== null);
    return (
        <Card className="@container/card min-w-0">
            <CardHeader>
                <CardTitle>{t('Response time')}</CardTitle>
                <CardDescription>
                    {t(
                        hasPeaks
                            ? '5-minute maximum'
                            : active.key === '24h'
                              ? 'Minute average'
                              : 'Daily average',
                    )}
                    {active.recent_average_ms !== null && (
                        <>
                            {' '}
                            · {t('Last 15 minutes')}:{' '}
                            {format.milliseconds(active.recent_average_ms)}{' '}
                            {t('ms')}
                        </>
                    )}
                </CardDescription>
                <CardAction>
                    <div className="hidden @[640px]/card:block">
                        <SegmentedControl
                            label={t('Time range')}
                            value={active.key}
                            onValueChange={(value) => {
                                const selection = series.find(
                                    (series) => series.key === value,
                                );
                                if (!selection)
                                    throw new Error(
                                        'Unknown response-time range',
                                    );
                                setRange(selection.key);
                            }}
                            options={series.map((period) => ({
                                value: period.key,
                                label: t(rangeLabels[period.key]),
                            }))}
                        />
                    </div>
                    <Select
                        value={active.key}
                        onValueChange={(value) => {
                            const selection = series.find(
                                (series) => series.key === value,
                            );
                            if (!selection)
                                throw new Error('Unknown response-time range');
                            setRange(selection.key);
                        }}
                    >
                        <SelectTrigger
                            className="w-32 @[640px]/card:hidden"
                            aria-label={t('Time range')}
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {series.map((period) => (
                                <SelectItem key={period.key} value={period.key}>
                                    {t(rangeLabels[period.key])}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </CardAction>
            </CardHeader>
            <CardContent>
                {loading ? (
                    <Skeleton className="h-64 w-full" />
                ) : !active.points.some(
                      (point) =>
                          point.average_ms !== null ||
                          point.p95_ms !== null ||
                          point.maximum_ms !== null ||
                          point.failure_count > 0 ||
                          point.error_count > 0 ||
                          point.warning_count > 0,
                  ) ? (
                    <p className="flex h-64 items-center justify-center text-center text-sm text-muted-foreground">
                        {t('No measurements in this range yet')}
                    </p>
                ) : (
                    <ChartContainer
                        className="aspect-auto h-64 w-full"
                        config={{
                            maximum_ms: {
                                label: t('Maximum'),
                                color: 'var(--chart-2)',
                            },
                            average_ms: {
                                label: t('Average'),
                                color: 'var(--chart-2)',
                            },
                            p95_ms: {
                                label: t('p95'),
                                color: 'var(--chart-3)',
                            },
                        }}
                        role="group"
                        aria-label={[t('Response time'), sampleSummary]
                            .filter(Boolean)
                            .join(' · ')}
                    >
                        <ComposedChart
                            accessibilityLayer
                            data={active.points}
                            margin={{ left: -20, right: 8, top: 8, bottom: 0 }}
                        >
                            <CartesianGrid vertical={false} />
                            <XAxis
                                dataKey="time"
                                tickLine={false}
                                axisLine={false}
                                minTickGap={40}
                                tickFormatter={(value) =>
                                    format.date(
                                        String(value),
                                        active.key === '24h'
                                            ? ({
                                                  hour: '2-digit',
                                                  minute: '2-digit',
                                              } as const)
                                            : ({
                                                  day: 'numeric',
                                                  month: 'short',
                                              } as const),
                                    )
                                }
                            />
                            <YAxis
                                tickLine={false}
                                axisLine={false}
                                tickFormatter={(value) =>
                                    format.number(Number(value))
                                }
                                tick={hasResponseValues}
                                // With no measured domain, Recharts needs an explicit display extent for error marks.
                                allowDataOverflow={!hasResponseValues}
                                domain={[0, hasResponseValues ? 'auto' : 1]}
                            />
                            <ChartTooltip
                                isAnimationActive={false}
                                content={(tooltip) => (
                                    <ChartTooltipContent
                                        {...tooltip}
                                        status={historySampleStatus(
                                            active.points.filter(
                                                (point) =>
                                                    point.time ===
                                                    tooltip.label,
                                            ),
                                            t,
                                        )}
                                        subject={t('Response time')}
                                        labelFormatter={(value) => {
                                            if (typeof value !== 'string') {
                                                throw new Error(
                                                    'Response chart requires a date label',
                                                );
                                            }
                                            return active.key === '24h'
                                                ? format.exact(value)
                                                : format.calendarDate(value);
                                        }}
                                        formatter={(value, name) => (
                                            <div className="flex w-full justify-between gap-4">
                                                <span className="text-muted-foreground">
                                                    {t(
                                                        name === 'maximum_ms'
                                                            ? 'Maximum'
                                                            : name ===
                                                                'average_ms'
                                                              ? 'Average'
                                                              : 'p95',
                                                    )}
                                                </span>
                                                <span className="font-mono tabular-nums">
                                                    {format.milliseconds(
                                                        Number(value),
                                                    )}{' '}
                                                    {t('ms')}
                                                </span>
                                            </div>
                                        )}
                                    />
                                )}
                            />
                            {hasP95 && (
                                <ChartLegend content={<ChartLegendContent />} />
                            )}
                            <HistorySampleMarks points={active.points} />
                            <Area
                                dataKey={responseKey}
                                type="linear"
                                stroke={`var(--color-${responseKey})`}
                                fill={`var(--color-${responseKey})`}
                                fillOpacity={0.08}
                                strokeWidth={1.5}
                                isAnimationActive={false}
                                dot={
                                    active.points.filter(
                                        (point) => point[responseKey] !== null,
                                    ).length === 1
                                }
                            />
                            {hasP95 && (
                                <Line
                                    dataKey="p95_ms"
                                    type="linear"
                                    stroke="var(--color-p95_ms)"
                                    strokeDasharray="3 3"
                                    dot={false}
                                    isAnimationActive={false}
                                />
                            )}
                        </ComposedChart>
                    </ChartContainer>
                )}
            </CardContent>
        </Card>
    );
}
