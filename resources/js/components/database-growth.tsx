import { Database } from 'lucide-react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import {
    Area,
    CartesianGrid,
    ComposedChart,
    Line,
    XAxis,
    YAxis,
} from 'recharts';
import { EmptyState } from '@/components/empty-state';
import { InfoTooltip } from '@/components/info-tooltip';
import { SectionHeading } from '@/components/section-heading';
import { StatTile } from '@/components/stat-tile';
import { Card, CardContent } from '@/components/ui/card';
import {
    ChartContainer,
    ChartLegend,
    ChartLegendContent,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import {
    ListHeader,
    listRowPadding,
    tableRowStyle,
} from '@/components/ui/table';
import { useFormat } from '@/hooks/use-format';
import { cn } from '@/lib/utils';

type Growth = Heptapod.SystemHealth.DatabaseGrowthData;

const classLabels: Record<Heptapod.Enums.DatabaseDataClass, string> = {
    check_results: 'system.data_class.check_results',
    attempts: 'system.data_class.attempts',
    hourly_rollups: 'system.data_class.hourly_rollups',
    daily_rollups: 'system.data_class.daily_rollups',
    history: 'system.data_class.history',
    other: 'system.data_class.other',
};
const MeasuredSampleCount = 7;

/** Sample dates are UTC calendar days; they are plotted and labelled as such, independent of the account timezone. */
const day = (date: string) => Date.parse(`${date}T00:00:00Z`);
// A time scale keeps the sparse projection points at their true distance; the form-control guard reads `type=` as an input.
const timeAxis = { type: 'number', scale: 'time' } as const;

export function DatabaseGrowth({ growth }: { growth: Growth | null }) {
    const { t } = useTranslation();
    const format = useFormat();
    const calendarDay = (value: number) =>
        format.calendarDate(new Date(value).toISOString(), 'UTC');
    if (growth === null) {
        return (
            <DatabaseGrowthSection>
                <div className="rounded-lg border">
                    <EmptyState
                        bare
                        icon={Database}
                        title={t('No database size sample yet')}
                        description={t(
                            'The scheduler records the first sample within the hour.',
                        )}
                    />
                </div>
            </DatabaseGrowthSection>
        );
    }
    const latest = growth.history[growth.history.length - 1];
    if (!latest) throw new Error('Database growth requires a sample');
    // The projection starts at the latest sample, so both series share that point and the lines join.
    const points = [
        ...growth.history.map((point) => ({
            day: day(point.date),
            measured: point.bytes,
            projected: point.date === latest.date ? point.bytes : undefined,
        })),
        ...growth.projection.slice(1).map((point) => ({
            day: day(point.date),
            measured: undefined,
            projected: point.bytes,
        })),
    ];
    const disk = growth.disk;
    const unenforced = growth.classes.some(
        (item) => item.retentionDays !== null && !item.retentionEnforced,
    );
    const hourly = growth.classes.find(
        (item) => item.dataClass === 'hourly_rollups',
    );
    return (
        <DatabaseGrowthSection
            description={
                growth.measured
                    ? t(
                          'Measured once a day; projected from the last week of intake and the retention that is enforced.',
                      )
                    : t('database_growth.estimate', {
                          count: growth.sampleCount,
                          formattedCount: format.number(growth.sampleCount),
                          required: MeasuredSampleCount,
                      })
            }
        >
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatTile
                    bordered
                    label={t('Now')}
                    value={format.bytes(latest.bytes)}
                    meta={t('Sampled :date', {
                        date: calendarDay(day(latest.date)),
                    })}
                />
                <StatTile
                    bordered
                    label={t('In 90 days')}
                    value={format.bytes(growth.in90DaysBytes)}
                    meta={t('In 30 days: :size', {
                        size: format.bytes(growth.in30DaysBytes),
                    })}
                />
                {growth.plateauBytes !== null && growth.plateauOn !== null ? (
                    <StatTile
                        bordered
                        label={t('Levels off at')}
                        value={format.bytes(growth.plateauBytes)}
                        meta={t('Around :date', {
                            date: calendarDay(day(growth.plateauOn)),
                        })}
                    />
                ) : (
                    <StatTile
                        bordered
                        label={t('In one year')}
                        value={format.bytes(growth.in365DaysBytes)}
                        meta={t('Then keeps growing by :size per month', {
                            size: format.bytes(growth.monthlyGrowthBytes ?? 0),
                        })}
                    />
                )}
                <StatTile
                    bordered
                    label={t('Free disk')}
                    value={
                        disk.freeBytes === null
                            ? t('Unknown')
                            : format.bytes(disk.freeBytes)
                    }
                    meta={
                        disk.path === null
                            ? t(
                                  'The database disk is not visible to Heptapod. Set HEPTAPOD_POSTGRES_DATA_PATH to a folder on that disk.',
                              )
                            : disk.failure !== null
                              ? t(`disk.read_failure.${disk.failure}`, {
                                    path: disk.path,
                                })
                              : disk.fillsOn !== null
                                ? t('The disk would fill around :date', {
                                      date: calendarDay(day(disk.fillsOn)),
                                  })
                                : t('Fits for a year with :size to spare', {
                                      size: format.bytes(disk.spareBytes ?? 0),
                                  })
                    }
                />
            </div>
            <Card className="min-w-0">
                <CardContent>
                    <ChartContainer
                        className="aspect-auto h-64 w-full"
                        config={{
                            measured: {
                                label: t('Measured'),
                                color: 'var(--chart-2)',
                            },
                            projected: {
                                label: t('Projected'),
                                color: 'var(--chart-3)',
                            },
                        }}
                        aria-label={t(
                            'Database size: measured and projected for one year',
                        )}
                    >
                        <ComposedChart
                            accessibilityLayer
                            data={points}
                            margin={{ left: 0, right: 32, top: 8, bottom: 0 }}
                        >
                            <CartesianGrid vertical={false} />
                            <XAxis
                                dataKey="day"
                                {...timeAxis}
                                domain={['dataMin', 'dataMax']}
                                tickLine={false}
                                axisLine={false}
                                minTickGap={40}
                                tickFormatter={(value: number) =>
                                    format.date(new Date(value).toISOString(), {
                                        month: 'short',
                                        year: 'numeric',
                                        timeZone: 'UTC',
                                    })
                                }
                            />
                            <YAxis
                                tickLine={false}
                                axisLine={false}
                                width={72}
                                domain={[0, 'auto']}
                                tickFormatter={(value: number) =>
                                    format.bytes(value)
                                }
                            />
                            <ChartTooltip
                                isAnimationActive={false}
                                content={(tooltip) => (
                                    <ChartTooltipContent
                                        {...tooltip}
                                        subject={t('Database size')}
                                        labelFormatter={(value) =>
                                            calendarDay(Number(value))
                                        }
                                        formatter={(value, name) => (
                                            <div className="flex w-full justify-between gap-4">
                                                <span className="text-muted-foreground">
                                                    {t(
                                                        name === 'measured'
                                                            ? 'Measured'
                                                            : 'Projected',
                                                    )}
                                                </span>
                                                <span className="font-mono tabular-nums">
                                                    {format.bytes(
                                                        Number(value),
                                                    )}
                                                </span>
                                            </div>
                                        )}
                                    />
                                )}
                            />
                            <ChartLegend content={<ChartLegendContent />} />
                            <Area
                                dataKey="measured"
                                type="linear"
                                stroke="var(--color-measured)"
                                fill="var(--color-measured)"
                                fillOpacity={0.08}
                                strokeWidth={1.5}
                                connectNulls
                                isAnimationActive={false}
                                dot={growth.history.length === 1}
                            />
                            <Line
                                dataKey="projected"
                                type="linear"
                                stroke="var(--color-projected)"
                                strokeDasharray="4 4"
                                strokeWidth={1.5}
                                connectNulls
                                dot={false}
                                isAnimationActive={false}
                            />
                        </ComposedChart>
                    </ChartContainer>
                </CardContent>
            </Card>
            <div className="@container/table overflow-hidden rounded-lg border">
                <ListHeader
                    className="@[48rem]/table:grid @[48rem]/table:grid-cols-[minmax(0,1fr)_6rem_6rem_minmax(0,20rem)]"
                    aria-hidden="true"
                >
                    <span>{t('Data')}</span>
                    <span>{t('Now')}</span>
                    <span>{t('In one year')}</span>
                    <span>{t('Retention')}</span>
                </ListHeader>
                <ul>
                    {growth.classes.map((item) => (
                        <li
                            key={item.dataClass}
                            className={cn(
                                listRowPadding,
                                tableRowStyle,
                                'grid grid-cols-[minmax(0,1fr)_auto] items-start gap-x-4 gap-y-1 last:border-b-0 @[48rem]/table:grid-cols-[minmax(0,1fr)_6rem_6rem_minmax(0,20rem)]',
                            )}
                        >
                            <span className="min-w-0 text-cell">
                                {t(classLabels[item.dataClass])}
                            </span>
                            <span className="font-mono text-cell tabular-nums">
                                <span className="sr-only">{t('Now')}: </span>
                                {format.bytes(item.bytes)}
                            </span>
                            <span className="col-span-2 font-mono text-cell tabular-nums @[48rem]/table:col-span-1">
                                <span className="font-sans text-xs text-muted-foreground @[48rem]/table:sr-only">
                                    {t('In one year')}:{' '}
                                </span>
                                {format.bytes(item.in365DaysBytes)}
                            </span>
                            <span className="col-span-2 min-w-0 text-xs text-muted-foreground @[48rem]/table:col-span-1">
                                <span className="sr-only">
                                    {t('Retention')}:{' '}
                                </span>
                                {item.retentionDays === null
                                    ? t('No retention')
                                    : item.retentionEnforced
                                      ? t('{{count}} days', {
                                            count: item.retentionDays,
                                            formattedCount: format.number(
                                                item.retentionDays,
                                            ),
                                        })
                                      : t('{{count}} days, not enforced yet', {
                                            count: item.retentionDays,
                                            formattedCount: format.number(
                                                item.retentionDays,
                                            ),
                                        })}
                                {item.dataClass === 'check_results' && (
                                    <>
                                        {' '}
                                        <InfoTooltip>
                                            {t(
                                                'Results leave with their whole month, so they stay up to one month longer.',
                                            )}
                                        </InfoTooltip>
                                    </>
                                )}
                                {item.retentionVariable !== null && (
                                    <span className="block font-mono wrap-anywhere">
                                        {item.retentionVariable}
                                    </span>
                                )}
                            </span>
                        </li>
                    ))}
                </ul>
            </div>
            {unenforced && (
                <p className="text-xs leading-relaxed text-muted-foreground">
                    {t(
                        'A retention that is not enforced yet deletes nothing, so its data keeps growing.',
                    )}
                </p>
            )}
            {hourly !== undefined && !hourly.retentionEnforced && (
                <p className="text-xs leading-relaxed text-muted-foreground">
                    {t(
                        'Hourly summaries are deleted after their retention once MONITORING_HOURLY_ROLLUP_DELETION_ENABLED is true.',
                    )}
                </p>
            )}
        </DatabaseGrowthSection>
    );
}

function DatabaseGrowthSection({
    description,
    children,
}: {
    description?: string;
    children: ReactNode;
}) {
    const { t } = useTranslation();
    return (
        <section
            aria-labelledby="database-growth-heading"
            className="space-y-3"
        >
            <div className="space-y-1">
                <SectionHeading id="database-growth-heading">
                    {t('Database growth')}
                </SectionHeading>
                {description !== undefined && (
                    <p className="text-xs text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>
            {children}
        </section>
    );
}
