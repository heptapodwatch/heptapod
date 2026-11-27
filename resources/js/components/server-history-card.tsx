import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
    CartesianGrid,
    Line,
    LineChart,
    ReferenceLine,
    XAxis,
    YAxis,
} from 'recharts';
import { SegmentedControl } from '@/components/segmented-control';
import {
    diskThreshold,
    diskUsedPercent,
    memoryUsedPercent,
    usePercent,
} from '@/components/server-values';
import {
    Card,
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
    type ChartConfig,
} from '@/components/ui/chart';
import { useFormat } from '@/hooks/use-format';

type Metric = 'disk' | 'memory' | 'cpu';
type History = Heptapod.Addons.Servers.Data.ServerPageData['history'];
type Thresholds = Heptapod.Addons.Servers.Data.ServerThresholdsData;
type Point = { at: number; [series: string]: number | null };

// A time scale keeps the points at their true distance; the form-control guard reads `type=` as an input.
const timeAxis = { type: 'number', scale: 'time' } as const;
const diskColors = ['--chart-1', '--chart-3', '--chart-5', '--chart-2'];
const metricTitle: Record<Metric, string> = {
    disk: 'servers.history.disk',
    memory: 'servers.history.memory',
    cpu: 'servers.history.cpu',
};

/** The metric whose current value is nearest its alert threshold; CPU has none. */
function nearestThreshold(
    values: Heptapod.Addons.Servers.Data.ServerValuesData | null,
    thresholds: Thresholds,
): Metric {
    if (values === null) return 'disk';
    const diskGap = Math.min(
        Infinity,
        ...values.filesystems.map(
            (filesystem) =>
                diskThreshold(thresholds, filesystem.mount) -
                diskUsedPercent(filesystem),
        ),
    );
    return thresholds.memory_percent - memoryUsedPercent(values) < diskGap
        ? 'memory'
        : 'disk';
}

/** One card for the 14-day peaks: Disk · Memory · CPU switch the chart and its threshold line. */
export function ServerHistoryCard({
    history,
    values,
    thresholds,
}: {
    history: History;
    values: Heptapod.Addons.Servers.Data.ServerValuesData | null;
    thresholds: Thresholds;
}) {
    const { t } = useTranslation();
    const format = useFormat();
    const percent = usePercent();
    const [metric, setMetric] = useState<Metric>(() =>
        nearestThreshold(values, thresholds),
    );
    const mounts = [
        ...new Set(history.points.flatMap((point) => Object.keys(point.disks))),
    ].sort();
    const data: Point[] = history.points.map((point) => ({
        at: Date.parse(point.at),
        cpu: point.cpu,
        load: point.load,
        memory: point.memory,
        ...Object.fromEntries(
            mounts.map((mount, index) => [
                `disk${index}`,
                point.disks[mount] ?? null,
            ]),
        ),
    }));
    // Each disk alerts at its own threshold: one dashed line per mount in that mount's colour, or one red line while they
    // all share it.
    const diskLines = mounts.map((mount, index) => ({
        y: diskThreshold(thresholds, mount),
        color: `var(${diskColors[index % diskColors.length]})`,
    }));
    const shared =
        new Set(diskLines.map((line) => line.y)).size <= 1
            ? (diskLines[0]?.y ?? thresholds.disk_percent)
            : undefined;
    const thresholdLines =
        metric === 'cpu'
            ? []
            : metric === 'memory'
              ? [{ y: thresholds.memory_percent, color: 'var(--status-down)' }]
              : shared === undefined
                ? diskLines
                : [{ y: shared, color: 'var(--status-down)' }];
    const thresholdText =
        metric === 'cpu'
            ? null
            : metric === 'disk' && shared === undefined
              ? t('servers.history.thresholds')
              : t('servers.history.threshold', {
                    percent: percent(thresholdLines[0]!.y),
                });
    const config: ChartConfig =
        metric === 'disk'
            ? Object.fromEntries(
                  mounts.map((mount, index) => [
                      `disk${index}`,
                      {
                          label: t('servers.gauge.disk', { mount }),
                          color: `var(${diskColors[index % diskColors.length]})`,
                      },
                  ]),
              )
            : metric === 'memory'
              ? {
                    memory: {
                        label: t('servers.gauge.memory'),
                        color: 'var(--chart-2)',
                    },
                }
              : {
                    cpu: {
                        label: t('servers.gauge.cpu'),
                        color: 'var(--chart-2)',
                    },
                };
    const title = t(metricTitle[metric]);
    const minutes = history.step_seconds < 3600;
    return (
        <Card className="min-w-0">
            <CardHeader>
                <CardTitle>{title}</CardTitle>
                <CardDescription>
                    {thresholdText === null
                        ? t('servers.history.window')
                        : `${t('servers.history.window')} · ${thresholdText}`}
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                <SegmentedControl
                    label={t('servers.history.metric')}
                    value={metric}
                    onValueChange={(value) => setMetric(value as Metric)}
                    options={[
                        { value: 'disk', label: t(metricTitle.disk) },
                        { value: 'memory', label: t(metricTitle.memory) },
                        { value: 'cpu', label: t(metricTitle.cpu) },
                    ]}
                />
                {data.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('servers.history.empty')}
                    </p>
                ) : (
                    <ChartContainer
                        className="aspect-auto h-56 w-full"
                        config={config}
                        aria-label={title}
                    >
                        <LineChart
                            accessibilityLayer
                            data={data}
                            margin={{ left: 0, right: 8, top: 8, bottom: 0 }}
                        >
                            <CartesianGrid vertical={false} />
                            <XAxis
                                dataKey="at"
                                {...timeAxis}
                                domain={['dataMin', 'dataMax']}
                                tickLine={false}
                                axisLine={false}
                                minTickGap={48}
                                tickFormatter={(value: number) => {
                                    const at = new Date(value).toISOString();
                                    return minutes
                                        ? format.time(at)
                                        : format.date(at, {
                                              day: 'numeric',
                                              month: 'short',
                                          });
                                }}
                            />
                            <YAxis
                                tickLine={false}
                                axisLine={false}
                                width={48}
                                domain={[0, 100]}
                                tickFormatter={(value: number) =>
                                    percent(value)
                                }
                            />
                            {thresholdLines.map((line, index) => (
                                <ReferenceLine
                                    key={index}
                                    y={line.y}
                                    stroke={line.color}
                                    strokeDasharray="4 4"
                                    ifOverflow="extendDomain"
                                />
                            ))}
                            <ChartTooltip
                                isAnimationActive={false}
                                content={(tooltip) => (
                                    <ChartTooltipContent
                                        {...tooltip}
                                        subject={title}
                                        formatter={(value, name, item) => (
                                            <span key={String(name)}>
                                                {config[String(name)]?.label}:{' '}
                                                <span className="font-mono tabular-nums">
                                                    {percent(Number(value))}
                                                </span>
                                                {metric === 'cpu' && (
                                                    <>
                                                        {' · '}
                                                        {t(
                                                            'servers.gauge.load',
                                                        )}
                                                        :{' '}
                                                        <span className="font-mono tabular-nums">
                                                            {format.number(
                                                                Number(
                                                                    (
                                                                        item.payload as Point
                                                                    ).load,
                                                                ),
                                                                {
                                                                    minimumFractionDigits: 1,
                                                                    maximumFractionDigits: 2,
                                                                },
                                                            )}
                                                        </span>
                                                    </>
                                                )}
                                            </span>
                                        )}
                                        labelFormatter={(value) =>
                                            format.timestamp(
                                                new Date(
                                                    Number(value),
                                                ).toISOString(),
                                            )
                                        }
                                    />
                                )}
                            />
                            <ChartLegend content={<ChartLegendContent />} />
                            {Object.keys(config).map((key) => (
                                <Line
                                    key={key}
                                    dataKey={key}
                                    type="linear"
                                    stroke={`var(--color-${key})`}
                                    strokeWidth={1.5}
                                    dot={data.length === 1}
                                    connectNulls
                                    isAnimationActive={false}
                                />
                            ))}
                        </LineChart>
                    </ChartContainer>
                )}
            </CardContent>
        </Card>
    );
}
