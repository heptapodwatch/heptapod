import {
    HistorySampleMarks,
    historySampleStatus,
    useHistorySampleSummary,
} from '@/components/history-sample-marks';
import { router } from '@inertiajs/react';
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
import { InfoTooltip } from '@/components/info-tooltip';
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
import { ResponsePhaseChart } from '@/components/response-phase-chart';
import { SegmentedControl } from '@/components/segmented-control';
import { Skeleton } from '@/components/ui/skeleton';
import { useFormat } from '@/hooks/use-format';
import { cn } from '@/lib/utils';

type Range = Heptapod.Enums.ResponseTimeRange;

const ranges: { key: Range; label: string; caption: string }[] = [
    {
        key: '24h',
        label: '24 hours',
        caption: 'response_time.five_minute_values',
    },
    { key: '7d', label: '7 days', caption: 'response_time.hourly_values' },
    { key: '30d', label: '30 days', caption: 'response_time.daily_values' },
    { key: '90d', label: '90 days', caption: 'response_time.daily_values' },
    { key: '1y', label: '1 year', caption: 'response_time.daily_values' },
    {
        key: 'all',
        label: 'history.all',
        caption: 'response_time.weekly_values',
    },
];
// Past 7 days the buckets are UTC days or weeks, so their dates are UTC dates.
const utcRanges: Range[] = ['30d', '90d', '1y', 'all'];
// With this few measured points, dots show they are single measurements rather than a long, even line.
const fewPoints = 3;

/**
 * The uptime check's response time: the total for one range at a time, loaded by a partial reload of `response_time`,
 * or its phases, which are kept for 24 hours only.
 */
export function ChartAreaInteractive({
    series,
    phases,
}: {
    series: Heptapod.Data.Ui.ResponseTimeSeriesData;
    phases: Heptapod.Data.Ui.MetricSeriesData[];
}) {
    const { t } = useTranslation();
    const format = useFormat();
    const [loading, setLoading] = useState<Range | null>(null);
    const [phasesChosen, setPhasesChosen] = useState(false);
    const active = series.key;
    // Derived from the loaded range, so a failed reload can never leave Phases showing past 24 hours.
    const view = phasesChosen && active === '24h' ? 'phases' : 'total';
    const selected = loading ?? active;
    const caption = ranges.find((range) => range.key === active)?.caption;
    if (!caption) throw new Error('Unknown response-time range');
    const timeZone = utcRanges.includes(active) ? 'UTC' : undefined;
    const points = series.points;
    const sampleSummary = useHistorySampleSummary(points);
    const hasValues = points.some(
        (point) => point.average_ms !== null || point.p95_ms !== null,
    );
    const marks = active === '24h';
    // Hourly points: one tick where a new local day starts, so no day repeats on the axis.
    const dayOf = (time: string) =>
        format.date(time, { day: 'numeric', month: 'short' });
    const dayTicks =
        active === '7d'
            ? points
                  .filter(
                      (point, index) =>
                          index > 0 &&
                          dayOf(point.time) !== dayOf(points[index - 1]!.time),
                  )
                  .map((point) => point.time)
            : undefined;
    const isEmpty =
        !hasValues &&
        !(
            marks &&
            points.some(
                (point) =>
                    point.failure_count > 0 ||
                    point.error_count > 0 ||
                    point.warning_count > 0,
            )
        );
    const select = (value: string) => {
        const range = ranges.find((item) => item.key === value);
        if (!range) throw new Error('Unknown response-time range');
        if (range.key === selected) return;
        if (range.key !== '24h') setPhasesChosen(false);
        router.reload({
            only: ['response_time'],
            data: { response_range: range.key },
            onStart: () => setLoading(range.key),
            onFinish: () => setLoading(null),
        });
    };
    const p95Label = (
        <span className="inline-flex items-center gap-0.5">
            {t('p95')}
            <InfoTooltip>
                {[
                    t('p95.hint'),
                    ...(active === 'all' ? [t('p95.weekly_hint')] : []),
                ].join(' ')}
            </InfoTooltip>
        </span>
    );
    const label = (value: unknown) => {
        if (typeof value !== 'string')
            throw new Error('Response chart requires a date label');
        if (active === '24h' || active === '7d') return format.exact(value);
        if (active === 'all')
            return t('response_time.week_of', {
                date: format.calendarDate(value, 'UTC'),
            });
        return format.calendarDate(value, 'UTC');
    };
    return (
        <Card className="@container/card min-w-0" data-response-chart>
            <CardHeader>
                <CardTitle>{t('Response time')}</CardTitle>
                {/* Both captions share one cell, so switching views never changes the header height. */}
                <CardDescription className="grid">
                    {(
                        [
                            ['total', caption],
                            ['phases', 'response_time.phase_values'],
                        ] as const
                    ).map(([key, text]) => (
                        <span
                            key={key}
                            className={cn(
                                '[grid-area:1/1]',
                                key !== view && 'invisible',
                            )}
                            aria-hidden={key !== view || undefined}
                        >
                            {t(text)}
                            {key === 'total' &&
                                series.recent_average_ms !== null && (
                                    <>
                                        {' '}
                                        · {t('Last 15 minutes')}:{' '}
                                        {format.milliseconds(
                                            series.recent_average_ms,
                                        )}{' '}
                                        {t('ms')}
                                    </>
                                )}
                        </span>
                    ))}
                </CardDescription>
                <CardAction>
                    <SegmentedControl
                        label={t('response_time.view')}
                        value={view}
                        onValueChange={(next) =>
                            setPhasesChosen(next === 'phases')
                        }
                        options={[
                            { value: 'total', label: t('response_time.total') },
                            {
                                value: 'phases',
                                label: t('Phases'),
                                disabledReason:
                                    active === '24h'
                                        ? undefined
                                        : t('response_time.phases_kept'),
                            },
                        ]}
                    />
                    <div className="hidden @[640px]/card:block">
                        <SegmentedControl
                            label={t('Time range')}
                            value={selected}
                            onValueChange={select}
                            options={ranges.map((range) => ({
                                value: range.key,
                                label: t(range.label),
                            }))}
                        />
                    </div>
                    <Select value={selected} onValueChange={select}>
                        <SelectTrigger
                            className="w-32 @[640px]/card:hidden"
                            aria-label={t('Time range')}
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {ranges.map((range) => (
                                <SelectItem key={range.key} value={range.key}>
                                    {t(range.label)}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </CardAction>
            </CardHeader>
            <CardContent>
                {loading ? (
                    <Skeleton aria-busy="true" className="h-64 w-full" />
                ) : view === 'phases' ? (
                    <ResponsePhaseChart series={phases} />
                ) : isEmpty ? (
                    <p className="flex h-64 items-center justify-center text-center text-sm text-muted-foreground">
                        {t('No measurements in this range yet')}
                    </p>
                ) : (
                    <ChartContainer
                        className="aspect-auto h-64 w-full"
                        config={{
                            average_ms: {
                                label: t('Average'),
                                color: 'var(--chart-2)',
                            },
                            p95_ms: {
                                label: p95Label,
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
                            data={points}
                            margin={{ left: 0, right: 24, top: 8, bottom: 0 }}
                        >
                            <CartesianGrid vertical={false} />
                            <XAxis
                                dataKey="time"
                                tickLine={false}
                                axisLine={false}
                                minTickGap={40}
                                ticks={dayTicks}
                                tickFormatter={(value) =>
                                    format.date(String(value), {
                                        timeZone,
                                        ...(active === '24h'
                                            ? {
                                                  hour: '2-digit',
                                                  minute: '2-digit',
                                              }
                                            : active === 'all'
                                              ? {
                                                    month: 'short',
                                                    year: '2-digit',
                                                }
                                              : {
                                                    day: 'numeric',
                                                    month: 'short',
                                                }),
                                    })
                                }
                            />
                            <YAxis
                                // Auto width keeps four-digit labels ("1,400") whole.
                                width="auto"
                                tickLine={false}
                                axisLine={false}
                                tickFormatter={(value) =>
                                    format.number(Number(value))
                                }
                                tick={hasValues}
                                // With no measured domain, Recharts needs an explicit display extent for error marks.
                                allowDataOverflow={!hasValues}
                                domain={[0, hasValues ? 'auto' : 1]}
                            />
                            <ChartTooltip
                                isAnimationActive={false}
                                content={(tooltip) => (
                                    <ChartTooltipContent
                                        {...tooltip}
                                        status={
                                            marks
                                                ? historySampleStatus(
                                                      points.filter(
                                                          (point) =>
                                                              point.time ===
                                                              tooltip.label,
                                                      ),
                                                      t,
                                                  )
                                                : undefined
                                        }
                                        subject={t('Response time')}
                                        labelFormatter={label}
                                        formatter={(value, name) => (
                                            <div className="flex w-full justify-between gap-4">
                                                <span className="text-muted-foreground">
                                                    {t(
                                                        name === 'p95_ms'
                                                            ? 'p95'
                                                            : 'Average',
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
                            <ChartLegend content={<ChartLegendContent />} />
                            {marks && <HistorySampleMarks points={points} />}
                            <Area
                                dataKey="average_ms"
                                type="linear"
                                stroke="var(--color-average_ms)"
                                fill="var(--color-average_ms)"
                                fillOpacity={0.08}
                                strokeWidth={1.5}
                                isAnimationActive={false}
                                dot={
                                    points.filter(
                                        (point) => point.average_ms !== null,
                                    ).length <= fewPoints
                                }
                            />
                            <Line
                                dataKey="p95_ms"
                                type="linear"
                                stroke="var(--color-p95_ms)"
                                strokeDasharray="3 3"
                                isAnimationActive={false}
                                dot={
                                    points.filter(
                                        (point) => point.p95_ms !== null,
                                    ).length <= fewPoints
                                }
                            />
                        </ComposedChart>
                    </ChartContainer>
                )}
            </CardContent>
        </Card>
    );
}
