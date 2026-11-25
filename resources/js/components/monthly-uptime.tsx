import { useTranslation } from 'react-i18next';
import { Bar, BarChart, CartesianGrid, XAxis, YAxis } from 'recharts';
import { StatTile } from '@/components/stat-tile';
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
import { cn } from '@/lib/utils';

/**
 * Long-term uptime on the uptime check tab: uptime over 90 days, a year and since the first result, and per calendar
 * month the interrupted minutes (bars) and the uptime. Both come from the same incident intervals, so they agree.
 */
export function MonthlyUptime({
    data,
}: {
    data: Heptapod.Data.Ui.MonthlyUptimeData;
}) {
    const { t } = useTranslation();
    const format = useFormat();
    const now = Date.now();
    const since = Date.parse(data.since);
    const percentage = (value: number | null) =>
        value === null ? '—' : format.uptimePercentage(value);
    // A range longer than the check's history covers only its history; the tile says since when.
    const sinceMeta = (days: number) =>
        now - since < days * 86400000
            ? t('history.since', { date: format.calendarDate(data.since) })
            : undefined;
    const months = data.months.map((month) => ({
        ...month,
        minutes: month.interrupted_seconds / 60,
    }));
    const monthLabel = (value: unknown, long = false) => {
        if (typeof value !== 'string')
            throw new Error('Monthly uptime requires a month label');
        return format.date(
            value,
            long
                ? { month: 'long', year: 'numeric' }
                : { month: 'short', year: '2-digit' },
        );
    };
    return (
        <Card className="@container/card min-w-0" data-monthly-uptime>
            <CardHeader>
                <CardTitle>{t('history.monthly_uptime')}</CardTitle>
                <CardDescription>
                    {t('history.monthly_uptime_description')}
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                <div className="grid grid-cols-3 gap-4">
                    <StatTile
                        label={t('90 days')}
                        value={percentage(data.days90)}
                        meta={sinceMeta(90)}
                    />
                    <StatTile
                        label={t('1 year')}
                        value={percentage(data.days365)}
                        meta={sinceMeta(365)}
                    />
                    <StatTile
                        label={t('history.all')}
                        value={percentage(data.all)}
                        meta={t('history.since', {
                            date: format.calendarDate(data.since),
                        })}
                    />
                </div>
                <ChartContainer
                    className="aspect-auto h-40 w-full"
                    config={{
                        minutes: {
                            label: t('history.interrupted_minutes'),
                            color: 'var(--status-down)',
                        },
                    }}
                    role="group"
                    aria-label={t('history.monthly_uptime')}
                >
                    <BarChart
                        accessibilityLayer
                        data={months}
                        margin={{ left: 0, right: 8, top: 8, bottom: 0 }}
                    >
                        <CartesianGrid vertical={false} />
                        <XAxis
                            dataKey="month"
                            tickLine={false}
                            axisLine={false}
                            minTickGap={16}
                            tickFormatter={(value) => monthLabel(value)}
                        />
                        <YAxis
                            width="auto"
                            tickLine={false}
                            axisLine={false}
                            allowDecimals={false}
                            domain={[0, (max: number) => Math.max(5, max)]}
                            tickFormatter={(value) =>
                                format.number(Number(value))
                            }
                        />
                        <ChartTooltip
                            isAnimationActive={false}
                            content={(tooltip) => (
                                <ChartTooltipContent
                                    {...tooltip}
                                    subject={t('Uptime')}
                                    labelFormatter={(value) =>
                                        monthLabel(value, true)
                                    }
                                    formatter={(_value, _name, item) => {
                                        const month =
                                            item.payload as (typeof months)[number];
                                        return (
                                            <div className="flex w-full justify-between gap-4">
                                                <span className="text-muted-foreground">
                                                    {t(
                                                        'history.interrupted_minutes',
                                                    )}
                                                </span>
                                                <span className="font-mono tabular-nums">
                                                    {month.interrupted_seconds >
                                                    0
                                                        ? format.duration(
                                                              month.interrupted_seconds,
                                                          )
                                                        : '0'}{' '}
                                                    · {percentage(month.uptime)}
                                                </span>
                                            </div>
                                        );
                                    }}
                                />
                            )}
                        />
                        <Bar
                            dataKey="minutes"
                            fill="var(--color-minutes)"
                            radius={2}
                            isAnimationActive={false}
                        />
                    </BarChart>
                </ChartContainer>
                <dl className="grid grid-cols-3 gap-x-4 gap-y-2 @[480px]/card:grid-cols-4 @[640px]/card:grid-cols-6 @[900px]/card:grid-cols-12">
                    {months.slice(-12).map((month) => (
                        <div key={month.month} className="min-w-0">
                            <dt className="text-xs text-muted-foreground">
                                {monthLabel(month.month)}
                            </dt>
                            <dd
                                className={cn(
                                    'font-mono text-sm tabular-nums',
                                    month.interrupted_seconds > 0 &&
                                        'text-status-down',
                                )}
                            >
                                {percentage(month.uptime)}
                            </dd>
                        </div>
                    ))}
                </dl>
            </CardContent>
        </Card>
    );
}
