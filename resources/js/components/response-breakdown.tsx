import { useTranslation } from 'react-i18next';
import { Area, AreaChart, CartesianGrid, XAxis, YAxis } from 'recharts';
import {
    HistorySampleMarks,
    historySampleStatus,
    useHistorySampleSummary,
} from '@/components/history-sample-marks';
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
import { Skeleton } from '@/components/ui/skeleton';
import { useFormat } from '@/hooks/use-format';

export function ResponseBreakdown({
    series = [],
    loading = false,
}: {
    series?: Heptapod.Data.Ui.MetricSeriesData[];
    loading?: boolean;
    empty?: boolean;
}) {
    const { t } = useTranslation();
    const format = useFormat();
    const labels: Record<string, string> = {
        dns: 'DNS',
        tcp: 'Connect',
        ssl: 'TLS',
        ttfb: 'TTFB',
        download: 'Transfer',
        dns_time_in_seconds: 'DNS',
        tcp_time_in_seconds: 'Connect',
        ssl_handshake_time_in_seconds: 'TLS',
        remote_server_processing_time_in_seconds: 'TTFB',
        download_time_in_seconds: 'Transfer',
    };
    const rows = new Map<string, Record<string, string | number | null>>();
    for (const metric of series) {
        for (const point of metric.points) {
            const row = rows.get(point.time) ?? { time: point.time };
            row[metric.key] = point.value;
            rows.set(point.time, row);
        }
    }
    const data = [...rows.values()];
    const samples = series[0]?.points ?? [];
    const hasPhaseValues = series.some((metric) =>
        metric.points.some((point) => point.value !== null),
    );
    const sampleSummary = useHistorySampleSummary(samples);
    return (
        <Card className="min-w-0">
            <CardHeader>
                <CardTitle>{t('Response-time breakdown')}</CardTitle>
                <CardDescription>{t('5-minute maximum')}</CardDescription>
            </CardHeader>
            <CardContent>
                {loading ? (
                    <Skeleton className="h-64 w-full" />
                ) : !data.length ? (
                    <p className="flex h-64 items-center justify-center text-sm text-muted-foreground">
                        {t('No data')}
                    </p>
                ) : (
                    <ChartContainer
                        className="h-64 w-full"
                        role="group"
                        aria-label={[
                            t('Response-time breakdown'),
                            sampleSummary,
                        ]
                            .filter(Boolean)
                            .join(' · ')}
                        config={Object.fromEntries(
                            series.map((metric, index) => [
                                metric.key,
                                {
                                    label: t(labels[metric.key] ?? metric.key),
                                    color: `var(--chart-${index + 1})`,
                                },
                            ]),
                        )}
                    >
                        <AreaChart
                            accessibilityLayer
                            data={data}
                            margin={{ left: -12, right: 8 }}
                        >
                            <CartesianGrid vertical={false} />
                            <XAxis
                                dataKey="time"
                                tickLine={false}
                                axisLine={false}
                                minTickGap={50}
                                tickFormatter={(value) =>
                                    format.date(String(value), {
                                        hour: '2-digit',
                                        minute: '2-digit',
                                    })
                                }
                            />
                            <YAxis
                                tickLine={false}
                                axisLine={false}
                                tick={hasPhaseValues}
                                allowDataOverflow={!hasPhaseValues}
                                domain={[0, hasPhaseValues ? 'auto' : 1]}
                                tickFormatter={(value) =>
                                    format.number(Number(value))
                                }
                            />
                            <ChartTooltip
                                isAnimationActive={false}
                                content={(tooltip) => (
                                    <ChartTooltipContent
                                        {...tooltip}
                                        status={historySampleStatus(
                                            samples.filter(
                                                (point) =>
                                                    point.time ===
                                                    tooltip.label,
                                            ),
                                            t,
                                        )}
                                        subject={t('Response-time breakdown')}
                                        formatter={(value, name) => {
                                            const metric = series.find(
                                                (item) => item.key === name,
                                            );
                                            if (
                                                !metric ||
                                                typeof value !== 'number'
                                            )
                                                throw new Error(
                                                    'Timing tooltip needs a known numeric metric',
                                                );
                                            return `${t(labels[metric.key] ?? metric.key)}: ${format.number(value)} ${t(metric.unit)}`;
                                        }}
                                        labelFormatter={(value) => {
                                            if (typeof value !== 'string')
                                                throw new Error(
                                                    'Timing chart requires a timestamp',
                                                );
                                            return format.exact(value);
                                        }}
                                    />
                                )}
                            />
                            <HistorySampleMarks points={samples} />
                            {series.map((metric) => (
                                <Area
                                    key={metric.key}
                                    dataKey={metric.key}
                                    stackId="response"
                                    type="linear"
                                    fill={`var(--color-${metric.key})`}
                                    stroke={`var(--color-${metric.key})`}
                                    fillOpacity={0.9}
                                    isAnimationActive={false}
                                />
                            ))}
                        </AreaChart>
                    </ChartContainer>
                )}
            </CardContent>
        </Card>
    );
}
