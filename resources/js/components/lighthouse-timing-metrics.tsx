import { router } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { CartesianGrid, Line, LineChart, XAxis, YAxis } from 'recharts';
import { ChartRangeControl } from '@/components/chart-range-control';
import { InfoTooltip } from '@/components/info-tooltip';
import { SegmentedControl } from '@/components/segmented-control';
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
import { Skeleton } from '@/components/ui/skeleton';
import { useFormat } from '@/hooks/use-format';

type Metrics = Heptapod.Addons.Lighthouse.LighthouseMetrics;
type Series = Heptapod.Data.Ui.DailyMetricHistoryData;
type Range = Series['range'];

// Google Lighthouse lab scoring: p10 (90 score) and median (50 score), mobile then desktop.
// https://github.com/GoogleChrome/lighthouse/tree/v12.8.2/core/audits/metrics
// TTI uses the archived audit: https://github.com/GoogleChrome/lighthouse/blob/v9.6.8/lighthouse-core/audits/metrics/interactive.js
const metrics = [
    {
        key: 'first_contentful_paint_in_ms',
        name: 'fcp',
        short: 'FCP',
        mobile: [1800, 3000],
        desktop: [934, 1600],
    },
    {
        key: 'speed_index_in_ms',
        name: 'si',
        short: 'SI',
        mobile: [3387, 5800],
        desktop: [1311, 2300],
    },
    {
        key: 'time_to_interactive_in_ms',
        name: 'tti',
        short: 'TTI',
        mobile: [3785, 7300],
        desktop: [2468, 4500],
    },
    {
        key: 'largest_contentful_paint_in_ms',
        name: 'lcp',
        short: 'LCP',
        mobile: [2500, 4000],
        desktop: [1200, 2400],
    },
    {
        key: 'total_blocking_time_in_ms',
        name: 'tbt',
        short: 'TBT',
        mobile: [200, 600],
        desktop: [150, 350],
    },
    {
        key: 'cumulative_layout_shift',
        name: 'cls',
        short: 'CLS',
        mobile: [0.1, 0.25],
        desktop: [0.1, 0.25],
    },
] as const satisfies readonly {
    key: keyof Metrics;
    name: string;
    short: string;
    mobile: readonly [number, number];
    desktop: readonly [number, number];
}[];

type Metric = (typeof metrics)[number];
const ranges: readonly Range[] = ['30d', '90d', '1y', 'all'];
const tones = {
    good: 'text-status-ok',
    needs_improvement: 'text-status-warn',
    poor: 'text-status-down',
};

function useMetricValue() {
    const { t } = useTranslation();
    const format = useFormat();
    return (metric: Metric, value: number) =>
        metric.name === 'cls'
            ? format.number(value, {
                  minimumFractionDigits: 2,
                  maximumFractionDigits: 2,
              })
            : `${format.milliseconds(value)} ${t('ms')}`;
}

export function LighthouseTimingMetrics({
    metrics: values,
    device,
}: {
    metrics: Metrics | null;
    device: string | null;
}) {
    const { t } = useTranslation();
    const format = useMetricValue();
    return (
        <Card className="@container/card" data-lighthouse-metrics>
            <CardHeader>
                <CardTitle>{t('lighthouse.timing_metrics')}</CardTitle>
                <CardDescription>
                    {t('lighthouse.timing_latest')}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <dl className="grid grid-cols-2 gap-x-4 gap-y-5 @[640px]/card:grid-cols-3">
                    {metrics.map((metric) => {
                        const value = values?.[metric.key] ?? null;
                        const [good, fair] =
                            device === 'desktop'
                                ? metric.desktop
                                : metric.mobile;
                        const rating =
                            value === null
                                ? null
                                : value <= good
                                  ? 'good'
                                  : value <= fair
                                    ? 'needs_improvement'
                                    : 'poor';
                        return (
                            <div key={metric.key} className="space-y-1">
                                <dt className="text-sm">
                                    {t(`lighthouse.metric.${metric.name}`)}{' '}
                                    <InfoTooltip>
                                        {t(
                                            `lighthouse.metric.${metric.name}.help`,
                                        )}
                                    </InfoTooltip>
                                </dt>
                                <dd className="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                                    {value === null || rating === null ? (
                                        <span className="text-sm text-muted-foreground">
                                            {t(
                                                'lighthouse.metric.not_measured',
                                            )}
                                        </span>
                                    ) : (
                                        <>
                                            <span
                                                className={`font-mono text-sm tabular-nums ${tones[rating]}`}
                                            >
                                                {format(metric, value)}
                                            </span>
                                            <span className="text-xs text-muted-foreground">
                                                {t(
                                                    `lighthouse.rating.${rating}`,
                                                )}
                                            </span>
                                        </>
                                    )}
                                </dd>
                            </div>
                        );
                    })}
                </dl>
            </CardContent>
        </Card>
    );
}

export function LighthouseMetricHistory({ series }: { series: Series }) {
    const { t } = useTranslation();
    const format = useFormat();
    const valueLabel = useMetricValue();
    const [metric, setMetric] = useState<Metric>(metrics[0]);
    const [loading, setLoading] = useState<Range | null>(null);
    const selectMetric = (key: string) => {
        const next = metrics.find((item) => item.key === key);
        if (!next) throw new Error('Unknown Lighthouse metric');
        setMetric(next);
    };
    const selectRange = (value: string) => {
        const next = ranges.find((range) => range === value);
        if (!next) throw new Error('Unknown Lighthouse metric range');
        if (next === (loading ?? series.range)) return;
        router.reload({
            only: ['daily_metric_history'],
            data: { metric_range: next },
            onStart: () => setLoading(next),
            onFinish: () => setLoading(null),
        });
    };
    const points = series.points.map(({ day, averages }) => ({
        day,
        value: averages?.[metric.key] ?? null,
    }));
    const measured = points.filter((point) => point.value !== null).length;
    const name = t(`lighthouse.metric.${metric.name}`);
    return (
        <Card
            className="@container/card min-w-0"
            data-lighthouse-metric-history
        >
            <CardHeader>
                <CardTitle>{t('lighthouse.timing_history')}</CardTitle>
                <CardDescription>
                    {t('lighthouse.timing_history_description')}
                </CardDescription>
                <CardAction>
                    <ChartRangeControl
                        ranges={ranges}
                        value={loading ?? series.range}
                        onValueChange={selectRange}
                    />
                </CardAction>
            </CardHeader>
            <CardContent className="space-y-4">
                <div className="hidden items-center justify-between gap-3 @[640px]/card:flex">
                    <span className="text-sm">
                        {name}
                        {metric.name !== 'cls' && ` (${t('ms')})`}
                    </span>
                    <SegmentedControl
                        value={metric.key}
                        onValueChange={selectMetric}
                        label={t('lighthouse.metric.select')}
                        options={metrics.map((item) => ({
                            value: item.key,
                            label: item.short,
                        }))}
                    />
                </div>
                <Select value={metric.key} onValueChange={selectMetric}>
                    <SelectTrigger
                        className="w-full @[640px]/card:hidden"
                        aria-label={t('lighthouse.metric.select')}
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {metrics.map((item) => (
                            <SelectItem key={item.key} value={item.key}>
                                {t(`lighthouse.metric.${item.name}`)}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                {loading ? (
                    <Skeleton aria-busy="true" className="h-56 w-full" />
                ) : measured === 0 ? (
                    <p className="flex h-56 items-center justify-center text-center text-sm text-muted-foreground">
                        {t('No measurements in this range yet')}
                    </p>
                ) : (
                    <ChartContainer
                        className="aspect-auto h-56 w-full"
                        config={{
                            value: { label: name, color: 'var(--chart-2)' },
                        }}
                        role="group"
                        aria-label={name}
                    >
                        <LineChart
                            accessibilityLayer
                            data={points}
                            margin={{ left: 0, right: 18, top: 6, bottom: 0 }}
                        >
                            <CartesianGrid vertical={false} />
                            <XAxis
                                dataKey="day"
                                tickLine={false}
                                axisLine={false}
                                minTickGap={30}
                                tickFormatter={(day: string) =>
                                    format.date(day, {
                                        timeZone: 'UTC',
                                        month: 'short',
                                        day: 'numeric',
                                    })
                                }
                            />
                            <YAxis
                                tickLine={false}
                                axisLine={false}
                                width="auto"
                                tickFormatter={(value: number) =>
                                    metric.name === 'cls'
                                        ? format.number(value, {
                                              maximumFractionDigits: 2,
                                          })
                                        : valueLabel(metric, value)
                                }
                            />
                            <ChartTooltip
                                isAnimationActive={false}
                                content={(tooltip) => (
                                    <ChartTooltipContent
                                        {...tooltip}
                                        subject={name}
                                        labelFormatter={(day) => {
                                            if (typeof day !== 'string')
                                                throw new Error(
                                                    'Metric history requires a day label',
                                                );
                                            return format.calendarDate(
                                                day,
                                                'UTC',
                                            );
                                        }}
                                        formatter={(value) => (
                                            <span className="font-mono tabular-nums">
                                                {valueLabel(
                                                    metric,
                                                    Number(value),
                                                )}
                                            </span>
                                        )}
                                    />
                                )}
                            />
                            <Line
                                dataKey="value"
                                type="linear"
                                stroke="var(--color-value)"
                                strokeWidth={1.5}
                                isAnimationActive={false}
                                dot={measured <= 3}
                            />
                        </LineChart>
                    </ChartContainer>
                )}
            </CardContent>
        </Card>
    );
}
