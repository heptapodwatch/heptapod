import { useTranslation } from 'react-i18next';
import {
    Area,
    CartesianGrid,
    ComposedChart,
    Line,
    ReferenceDot,
    XAxis,
    YAxis,
} from 'recharts';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import { EvidenceFacts } from '@/components/evidence-facts';
import { useFormat } from '@/hooks/use-format';

export function PerformanceEvidence({
    page,
}: {
    page: Heptapod.Data.Ui.CheckTabPageData;
}) {
    const { t } = useTranslation();
    const format = useFormat();
    const averages = page.metrics.find(
        (series) => series.key === 'performance.hourly_average',
    );
    const baseline = page.metrics.find(
        (series) => series.key === 'previous_average_response_time_in_ms',
    );
    const usualByHour = new Map<string, number | null>();
    for (const point of baseline?.points ?? [])
        usualByHour.set(
            new Date(point.time).toISOString().slice(0, 13),
            point.value,
        );
    const p95ByTime = new Map(
        page.response_p95_hourly?.points.map((point) => [
            point.time,
            point.value,
        ]),
    );
    const noise = page.performance_noise;
    if (!noise)
        throw new Error('Performance needs its server-provided noise rules.');
    const limitsByTime = new Map(
        noise.points.map((point) => [point.time, point]),
    );
    const data = (averages?.points ?? []).slice(-24).map((point) => {
        const usual =
            usualByHour.get(new Date(point.time).toISOString().slice(0, 13)) ??
            null;
        const limit = limitsByTime.get(point.time);
        return {
            time: point.time,
            average: point.value,
            p95: p95ByTime.get(point.time) ?? null,
            usual,
            bandBase: limit?.baseline ?? null,
            band: limit ? limit.threshold - limit.baseline : null,
            marked: limit?.marked === true,
            markedMedian: limit?.median ?? null,
        };
    });
    const latest = data.findLast((point) => point.average !== null);
    return (
        <Card className="overflow-hidden" data-performance-evidence>
            <CardHeader>
                <CardTitle>{t('Response time')}</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
                {latest ? (
                    <ChartContainer
                        className="h-[220px] w-full"
                        config={{
                            average: {
                                label: t('Hourly average'),
                                color: 'var(--chart-1)',
                            },
                            p95: {
                                label: 'p95',
                                color: 'var(--foreground-faint)',
                            },
                            usual: {
                                label: t('Usual'),
                                color: 'var(--muted-foreground)',
                            },
                        }}
                    >
                        <ComposedChart
                            data={data}
                            margin={{ left: 0, right: 10, top: 10, bottom: 0 }}
                        >
                            <CartesianGrid vertical={false} />
                            <XAxis
                                dataKey="time"
                                tickFormatter={(time: string) =>
                                    format.date(time, {
                                        hour: '2-digit',
                                        minute: '2-digit',
                                        hourCycle: 'h23',
                                    })
                                }
                                tickLine={false}
                                axisLine={false}
                                minTickGap={40}
                            />
                            <YAxis
                                width={48}
                                tickFormatter={(value: number) =>
                                    format.milliseconds(value)
                                }
                                tickLine={false}
                                axisLine={false}
                            />
                            <ChartTooltip
                                isAnimationActive={false}
                                content={(tooltip) => (
                                    <ChartTooltipContent
                                        {...tooltip}
                                        status={
                                            data.find(
                                                (point) =>
                                                    point.time ===
                                                    tooltip.label,
                                            )?.marked
                                                ? {
                                                      state: 'warning',
                                                      label: t(
                                                          'tooltip.above_usual',
                                                      ),
                                                  }
                                                : undefined
                                        }
                                        subject={t('Response time')}
                                        formatter={(value, name) => {
                                            if (typeof value !== 'number')
                                                throw new Error(
                                                    'Performance tooltip needs a numeric measurement.',
                                                );
                                            return (
                                                <div className="flex w-full justify-between gap-4">
                                                    <span>
                                                        {name === 'average'
                                                            ? t(
                                                                  'Hourly average',
                                                              )
                                                            : name === 'p95'
                                                              ? 'p95'
                                                              : t('Usual')}
                                                    </span>
                                                    <span className="font-mono">
                                                        {format.milliseconds(
                                                            value,
                                                        )}{' '}
                                                        {t('ms')}
                                                    </span>
                                                </div>
                                            );
                                        }}
                                        labelFormatter={(value) => {
                                            if (typeof value !== 'string')
                                                throw new Error(
                                                    'Performance tooltip needs its hour timestamp.',
                                                );
                                            return format.exact(value);
                                        }}
                                    />
                                )}
                            />
                            <Area
                                dataKey="bandBase"
                                stackId="usual-band"
                                stroke="none"
                                fill="transparent"
                                isAnimationActive={false}
                                legendType="none"
                                tooltipType="none"
                            />
                            <Area
                                dataKey="band"
                                stackId="usual-band"
                                stroke="none"
                                fill="var(--foreground-faint)"
                                fillOpacity={0.12}
                                isAnimationActive={false}
                                legendType="none"
                                tooltipType="none"
                            />
                            <Line
                                dataKey="usual"
                                stroke="var(--foreground-faint)"
                                strokeDasharray="3 3"
                                dot={false}
                                isAnimationActive={false}
                            />
                            <Line
                                dataKey="p95"
                                stroke="var(--foreground-faint)"
                                strokeWidth={1}
                                dot={false}
                                isAnimationActive={false}
                            />
                            <Line
                                dataKey="average"
                                stroke="var(--chart-1)"
                                strokeWidth={1.75}
                                dot={false}
                                isAnimationActive={false}
                            />
                            {data
                                .filter((point) => point.marked)
                                .map((point) => (
                                    <ReferenceDot
                                        key={point.time}
                                        x={point.time}
                                        y={point.markedMedian!}
                                        r={3}
                                        fill="var(--status-warn)"
                                        stroke="none"
                                    />
                                ))}
                        </ComposedChart>
                    </ChartContainer>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        {t('No measured response time')}
                    </p>
                )}
                <EvidenceFacts
                    rows={[
                        {
                            label: t('Hourly average'),
                            value:
                                latest?.average == null
                                    ? '—'
                                    : `${format.milliseconds(latest.average)} ${t('ms')}`,
                            mono: true,
                        },
                        {
                            label: 'p95',
                            value:
                                latest?.p95 == null
                                    ? '—'
                                    : `${format.milliseconds(latest.p95)} ${t('ms')}`,
                            mono: true,
                        },
                        {
                            label: t('Usual'),
                            value:
                                latest?.usual == null
                                    ? t('Not enough baseline history')
                                    : `${format.milliseconds(latest.usual)} ${t('ms')}`,
                            mono: latest?.usual != null,
                        },
                    ]}
                />
                <p className="text-xs text-muted-foreground">
                    {t('7-day median for the same UTC hour')}
                </p>
            </CardContent>
        </Card>
    );
}
