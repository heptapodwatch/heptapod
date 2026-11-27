import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { InfoTooltip } from '@/components/info-tooltip';
import { StatusBadge } from '@/components/monitoring';
import { ScoreRing, type ScoreRingTone } from '@/components/score-ring';
import { TableCell } from '@/components/ui/table';
import { useFormat } from '@/hooks/use-format';
import { cn } from '@/lib/utils';
import type { SiteState } from '@/types/monitoring';

type ServerRow = Heptapod.Addons.Servers.Data.ServerRowData;
type ServerValues = Heptapod.Addons.Servers.Data.ServerValuesData;
type Thresholds = Heptapod.Addons.Servers.Data.ServerThresholdsData;

const stateBadge: Record<
    Heptapod.Addons.Servers.ServerState,
    { state: SiteState; label: string }
> = {
    awaiting: { state: 'pending', label: 'servers.state.awaiting' },
    reporting: { state: 'ok', label: 'servers.state.reporting' },
    // We could not hear from the server: the Check error shape, never Down.
    no_report: { state: 'check-error', label: 'servers.state.no_report' },
};

export function ServerStateBadge({ server }: { server: ServerRow }) {
    return <StatusBadge {...stateBadge[server.state]} />;
}

export function usePercent() {
    const { number } = useFormat();
    return (value: number) =>
        number(value / 100, { style: 'percent', maximumFractionDigits: 0 });
}

/** At or above the threshold Failed, within 10 points below it Warning, else neutral. */
function gaugeTone(value: number, threshold?: number): ScoreRingTone {
    if (threshold === undefined) return 'neutral';
    if (value >= threshold) return 'failed';
    return value >= threshold - 10 ? 'warning' : 'neutral';
}

export const diskUsedPercent = (
    filesystem: ServerValues['filesystems'][number],
) =>
    filesystem.total_bytes === 0
        ? 0
        : 100 - (100 * filesystem.free_bytes) / filesystem.total_bytes;

export const memoryUsedPercent = (values: ServerValues) =>
    values.memory_total_bytes === 0
        ? 0
        : 100 -
          (100 * values.memory_available_bytes) / values.memory_total_bytes;

type ServerGauge = {
    key: string;
    label: string;
    /** Higher is worse: disk and RAM used, CPU, and load as a share of the cores. */
    value: number;
    threshold?: number;
    meta: string;
    /** The word at or above the threshold, so colour is never the only signal. */
    failedWord?: string;
};

/** The newest sample's values as gauges: disk used per mount, RAM used, CPU, then load against the cores. */
function useServerGauges(
    values: ServerValues | null,
    thresholds: Thresholds,
): ServerGauge[] {
    const { t } = useTranslation();
    const format = useFormat();
    if (values === null) return [];
    const cores = t('servers.gauge.cores', {
        count: values.cores,
        formattedCount: format.number(values.cores),
    });
    return [
        ...values.filesystems.map((filesystem) => ({
            key: `disk:${filesystem.mount}`,
            label: t('servers.gauge.disk', { mount: filesystem.mount }),
            value: diskUsedPercent(filesystem),
            threshold: thresholds.disk_used_percent,
            meta: t('servers.gauge.disk_meta', {
                free: format.bytes(filesystem.free_bytes),
                total: format.bytes(filesystem.total_bytes),
            }),
            failedWord: t('servers.gauge.almost_full'),
        })),
        {
            key: 'memory',
            label: t('servers.gauge.memory'),
            value: memoryUsedPercent(values),
            threshold: thresholds.memory_used_percent,
            meta: t('servers.gauge.memory_meta', {
                used: format.bytes(
                    values.memory_total_bytes - values.memory_available_bytes,
                ),
                total: format.bytes(values.memory_total_bytes),
            }),
            failedWord: t('servers.gauge.low_memory'),
        },
        {
            key: 'cpu',
            label: t('servers.gauge.cpu'),
            value: values.cpu_used_percent,
            meta: cores,
        },
        {
            key: 'load',
            label: t('servers.gauge.load'),
            value: (values.load_1 / values.cores) * 100,
            meta: t('servers.gauge.load_meta', {
                load: format.number(values.load_1, {
                    minimumFractionDigits: 1,
                    maximumFractionDigits: 2,
                }),
                cores,
            }),
        },
    ];
}

const toneTextClass: Record<ScoreRingTone, string | undefined> = {
    neutral: undefined,
    warning: 'text-status-warn',
    failed: 'text-status-down',
};

function GaugeWord({ gauge }: { gauge: ServerGauge }) {
    const { t } = useTranslation();
    const tone = gaugeTone(gauge.value, gauge.threshold);
    if (tone === 'neutral') return null;
    return (
        <span className={cn('text-xs font-medium', toneTextClass[tone])}>
            {tone === 'failed'
                ? gauge.failedWord
                : t('servers.gauge.near_threshold')}
        </span>
    );
}

/** The ring with its label, state word, absolute values and threshold under it, as in the Lighthouse score grid. */
function LabelledGauge({ gauge }: { gauge: ServerGauge }) {
    const { t } = useTranslation();
    const percent = usePercent();
    return (
        <div className="grid min-w-0 content-start justify-items-center gap-2 text-center">
            <ScoreRing
                score={gauge.value}
                label={gauge.label}
                text={percent(gauge.value)}
                tone={gaugeTone(gauge.value, gauge.threshold)}
                threshold={gauge.threshold}
            />
            <span
                aria-hidden
                className="text-xs leading-tight break-all text-muted-foreground"
            >
                {gauge.label}
            </span>
            <GaugeWord gauge={gauge} />
            <span className="text-xs leading-relaxed text-foreground-faint">
                {gauge.meta}
                {gauge.threshold !== undefined && (
                    <span className="block">
                        {t('servers.gauge.alert_at', {
                            percent: percent(gauge.threshold),
                        })}
                    </span>
                )}
            </span>
        </div>
    );
}

/** The server page's values row: one ScoreRing per value, then what the colours and the tick mean. */
export function ServerGaugeRow({
    values,
    thresholds,
}: {
    values: ServerValues;
    thresholds: Thresholds;
}) {
    const { t } = useTranslation();
    const gauges = useServerGauges(values, thresholds);
    return (
        <section aria-label={t('servers.gauge.title')} className="space-y-3">
            <div className="grid grid-cols-2 gap-x-4 gap-y-6 sm:grid-cols-3 lg:grid-cols-5">
                {gauges.map((gauge) => (
                    <LabelledGauge key={gauge.key} gauge={gauge} />
                ))}
            </div>
            <p className="flex items-center gap-1 text-xs text-muted-foreground">
                {t('servers.gauge.legend')}
                <InfoTooltip>{t('servers.gauge.legend_detail')}</InfoTooltip>
            </p>
        </section>
    );
}

/** The list's value variants (temporary `?list=a|b` switch): a, the Lighthouse ring; b, the plain tinted number. */
export type ServerListVariant = 'a' | 'b';

/** A list value: the ring (a) or the tinted number (b), then the state word near or over the threshold. */
function ListValue({
    gauge,
    variant,
}: {
    gauge: ServerGauge;
    variant: ServerListVariant;
}) {
    const percent = usePercent();
    const tone = gaugeTone(gauge.value, gauge.threshold);
    return (
        <span className="inline-flex flex-col items-center gap-1">
            {variant === 'a' ? (
                <ScoreRing
                    score={gauge.value}
                    label={gauge.label}
                    text={percent(gauge.value)}
                    tone={tone}
                    threshold={gauge.threshold}
                />
            ) : (
                <span
                    className={cn(
                        'font-mono text-sm tabular-nums',
                        toneTextClass[tone],
                    )}
                >
                    {percent(gauge.value)}
                </span>
            )}
            <GaugeWord gauge={gauge} />
        </span>
    );
}

/** Load as the raw 1-minute load against the cores ("1.8 / 4 vCPU"): it has no alert threshold, and it can exceed the cores. */
function ListLoad({ values }: { values: ServerValues }) {
    const { t } = useTranslation();
    const format = useFormat();
    return (
        <span className="font-mono text-sm whitespace-nowrap tabular-nums">
            {t('servers.list.load', {
                load: format.number(values.load_1, {
                    minimumFractionDigits: 1,
                    maximumFractionDigits: 2,
                }),
                cores: t('servers.gauge.cores', {
                    count: values.cores,
                    formattedCount: format.number(values.cores),
                }),
            })}
        </span>
    );
}

/** The list's disk (the fullest mount) and RAM used; none until the first report. */
function useListGauges(
    values: ServerValues | null,
    thresholds: Thresholds,
): (ServerGauge | undefined)[] {
    const gauges = useServerGauges(values, thresholds);
    const disks = gauges.filter((gauge) => gauge.key.startsWith('disk:'));
    return [
        disks.reduce<ServerGauge | undefined>(
            (top, gauge) =>
                top === undefined || gauge.value > top.value ? gauge : top,
            undefined,
        ),
        gauges.find((gauge) => gauge.key === 'memory'),
    ];
}

/** One line above the list that says what the values and colours mean. */
export function ServerListLegend({ variant }: { variant: ServerListVariant }) {
    const { t } = useTranslation();
    return (
        <p className="flex items-center gap-1 text-xs text-muted-foreground">
            {t('servers.list.legend')}
            <InfoTooltip>
                {t(
                    variant === 'a'
                        ? 'servers.list.legend_detail_ring'
                        : 'servers.list.legend_detail_value',
                )}
            </InfoTooltip>
        </p>
    );
}

/** The list row's value columns (desktop table): Disk used, RAM used, Load. */
export function ServerGaugeCells({
    values,
    thresholds,
    variant,
}: {
    values: ServerValues | null;
    thresholds: Thresholds;
    variant: ServerListVariant;
}) {
    const [disk, memory] = useListGauges(values, thresholds);
    return (
        <>
            {[disk, memory].map((gauge, index) => (
                <TableCell key={index} className="text-center">
                    {gauge !== undefined && (
                        <ListValue gauge={gauge} variant={variant} />
                    )}
                </TableCell>
            ))}
            <TableCell className="text-center">
                {values !== null && <ListLoad values={values} />}
            </TableCell>
        </>
    );
}

/** The list card's values (below md). */
export function ServerGaugeLine({
    values,
    thresholds,
    variant,
}: {
    values: ServerValues;
    thresholds: Thresholds;
    variant: ServerListVariant;
}) {
    const { t } = useTranslation();
    const [disk, memory] = useListGauges(values, thresholds);
    const cell = (label: string, value: ReactNode) => (
        <span className="flex flex-col items-center justify-end gap-1 text-center">
            {value}
            <span className="text-xs text-muted-foreground">{label}</span>
        </span>
    );
    return (
        <span className="grid grid-cols-3 items-end gap-3">
            {cell(
                t('servers.column.disk'),
                disk && <ListValue gauge={disk} variant={variant} />,
            )}
            {cell(
                t('servers.column.memory'),
                memory && <ListValue gauge={memory} variant={variant} />,
            )}
            {cell(t('servers.column.load'), <ListLoad values={values} />)}
        </span>
    );
}

/** "4 vCPU · 8 GB", from the newest sample. */
export function useServerSize() {
    const { t } = useTranslation();
    const format = useFormat();
    return (values: ServerValues) =>
        t('servers.size', {
            cores: t('servers.gauge.cores', {
                count: values.cores,
                formattedCount: format.number(values.cores),
            }),
            memory: format.bytes(values.memory_total_bytes),
        });
}

/** Up time, reboot and updates as one meta line in the check-header style; Reboot required in the warning colour. */
export function ServerFacts({ values }: { values: ServerValues }) {
    const { t } = useTranslation();
    const format = useFormat();
    const facts = [
        t('servers.fact.up', {
            duration: format.compactDuration(values.uptime_seconds),
        }),
        ...(values.reboot_required === true
            ? [
                  <span key="reboot" className="text-status-warn">
                      {t('servers.fact.reboot_required')}
                  </span>,
              ]
            : []),
        ...(values.updates_pending === null
            ? []
            : [
                  values.updates_pending === 0
                      ? t('servers.fact.no_updates')
                      : values.updates_security !== null &&
                          values.updates_security > 0
                        ? t('servers.fact.updates_security', {
                              count: values.updates_pending,
                              formattedCount: format.number(
                                  values.updates_pending,
                              ),
                              security: format.number(values.updates_security),
                          })
                        : t('servers.fact.updates', {
                              count: values.updates_pending,
                              formattedCount: format.number(
                                  values.updates_pending,
                              ),
                          }),
              ]),
    ];
    return (
        <span className="flex flex-wrap items-center gap-x-2.5 gap-y-1 text-xs text-foreground-faint">
            {facts.map((fact, index) => (
                <span key={index} className="inline-flex gap-x-2.5">
                    {index > 0 && <span aria-hidden>·</span>}
                    {fact}
                </span>
            ))}
        </span>
    );
}
