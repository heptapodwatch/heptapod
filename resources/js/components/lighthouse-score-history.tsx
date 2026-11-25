import { useTranslation } from 'react-i18next';
import { CartesianGrid, Line, LineChart, XAxis, YAxis } from 'recharts';
import {
    Card,
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
import { useFormat } from '@/hooks/use-format';

type Week = NonNullable<
    Heptapod.Addons.Lighthouse.LighthouseHistoryData['scores']
>[number];
const categories = [
    'performance',
    'accessibility',
    'best_practices',
    'seo',
] as const;
const labels: Record<(typeof categories)[number], string> = {
    performance: 'lighthouse.performance',
    accessibility: 'lighthouse.accessibility',
    best_practices: 'lighthouse.best_practices',
    seo: 'lighthouse.seo',
};
const yearInWeeks = 52;

/** One small weekly chart per Lighthouse category: the latest weekly score and its change against the same week a year ago. */
export function LighthouseScoreHistory({ weeks }: { weeks: Week[] }) {
    const { t } = useTranslation();
    const format = useFormat();
    if (!weeks.some((week) => categories.some((key) => week[key] !== null)))
        return null;
    const weekLabel = (value: unknown) => {
        if (typeof value !== 'string')
            throw new Error('Score history requires a week label');
        return t('response_time.week_of', {
            date: format.calendarDate(value, 'UTC'),
        });
    };
    return (
        <Card className="@container/card min-w-0" data-lighthouse-history>
            <CardHeader>
                <CardTitle>{t('history.score_history')}</CardTitle>
                <CardDescription>
                    {t('history.score_history_description')}
                </CardDescription>
            </CardHeader>
            <CardContent className="grid gap-6 @[640px]/card:grid-cols-2">
                {categories.map((category) => {
                    const lastIndex = weeks.findLastIndex(
                        (week) => week[category] !== null,
                    );
                    const last = weeks[lastIndex]?.[category] ?? null;
                    const latest = last === null ? null : Math.round(last);
                    const yearAgo =
                        weeks[lastIndex - yearInWeeks]?.[category] ?? null;
                    const change =
                        latest === null || yearAgo === null
                            ? null
                            : Math.round(latest - yearAgo) || 0;
                    return (
                        <div key={category} className="min-w-0 space-y-1">
                            <div className="flex items-baseline justify-between gap-2">
                                <span className="text-sm">
                                    {t(labels[category])}
                                </span>
                                <span className="font-mono text-sm tabular-nums">
                                    {latest === null
                                        ? '—'
                                        : format.number(latest)}
                                    {change !== null && (
                                        <span className="ml-2 text-xs text-muted-foreground">
                                            {t('history.year_change', {
                                                change: `${change > 0 ? '+' : ''}${format.number(change)}`,
                                            })}
                                        </span>
                                    )}
                                </span>
                            </div>
                            <ChartContainer
                                className="aspect-auto h-28 w-full"
                                config={{
                                    [category]: {
                                        label: t(labels[category]),
                                        color: 'var(--chart-2)',
                                    },
                                }}
                                role="group"
                                aria-label={t(labels[category])}
                            >
                                <LineChart
                                    accessibilityLayer
                                    data={weeks}
                                    margin={{
                                        left: 0,
                                        right: 24,
                                        top: 4,
                                        bottom: 0,
                                    }}
                                >
                                    <CartesianGrid vertical={false} />
                                    <XAxis
                                        dataKey="week"
                                        tickLine={false}
                                        axisLine={false}
                                        minTickGap={32}
                                        tickFormatter={(value) =>
                                            format.date(String(value), {
                                                timeZone: 'UTC',
                                                month: 'short',
                                                year: '2-digit',
                                            })
                                        }
                                    />
                                    <YAxis
                                        width="auto"
                                        tickLine={false}
                                        axisLine={false}
                                        domain={[0, 100]}
                                        ticks={[0, 50, 90, 100]}
                                    />
                                    <ChartTooltip
                                        isAnimationActive={false}
                                        content={(tooltip) => (
                                            <ChartTooltipContent
                                                {...tooltip}
                                                subject={t(labels[category])}
                                                labelFormatter={weekLabel}
                                                formatter={(value) => (
                                                    <span className="font-mono tabular-nums">
                                                        {format.number(
                                                            Math.round(
                                                                Number(value),
                                                            ),
                                                        )}
                                                    </span>
                                                )}
                                            />
                                        )}
                                    />
                                    <Line
                                        dataKey={category}
                                        type="linear"
                                        stroke={`var(--color-${category})`}
                                        strokeWidth={1.5}
                                        isAnimationActive={false}
                                        dot={
                                            weeks.filter(
                                                (week) =>
                                                    week[category] !== null,
                                            ).length <= 3
                                        }
                                    />
                                </LineChart>
                            </ChartContainer>
                        </div>
                    );
                })}
            </CardContent>
        </Card>
    );
}
