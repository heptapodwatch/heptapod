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

export function ServerStateBadge({
    server,
}: {
    server: Pick<ServerRow, 'state'>;
}) {
    return <StatusBadge {...stateBadge[server.state]} />;
}

export function usePercent() {
    const { number } = useFormat();
    return (value: number) =>
        number(value / 100, { style: 'percent', maximumFractionDigits: 0 });
}

/** At or above the threshold Failed, from `nearFrom` on Warning, else neutral. */
function toneOf(
    value: number,
    threshold: number,
    nearFrom: number,
): ScoreRingTone {
    if (value >= threshold) return 'failed';
    return value >= nearFrom ? 'warning' : 'neutral';
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

/** A mount's own disk threshold, else the server's disk threshold. */
export const diskThreshold = (thresholds: Thresholds, mount: string) =>
    thresholds.mounts.find((custom) => custom.mount === mount)?.percent ??
    thresholds.disk_percent;

/** A share of a whole (disk per mount, RAM): the only values drawn as a ring. Higher is worse. */
type ServerShare = {
    key: string;
    label: string;
    /** Percent in use. */
    value: number;
    threshold: number;
    tone: ScoreRingTone;
    meta: string;
    /** The word at or above the threshold, so colour is never the only signal. */
    failedWord: string;
};

/** Disk used per shown mount and RAM used; Warning within 10 points below the threshold. */
function useServerShares(
    values: ServerValues | null,
    thresholds: Thresholds,
): ServerShare[] {
    const { t } = useTranslation();
    const format = useFormat();
    if (values === null) return [];
    const share = (rest: Omit<ServerShare, 'tone'>): ServerShare => ({
        ...rest,
        tone: toneOf(rest.value, rest.threshold, rest.threshold - 10),
    });
    return [
        ...values.filesystems.map((filesystem) =>
            share({
                key: `disk:${filesystem.mount}`,
                label: t('servers.gauge.disk', { mount: filesystem.mount }),
                value: diskUsedPercent(filesystem),
                threshold: diskThreshold(thresholds, filesystem.mount),
                meta: t('servers.gauge.disk_meta', {
                    free: format.bytes(filesystem.free_bytes),
                    total: format.bytes(filesystem.total_bytes),
                }),
                failedWord: t('servers.gauge.almost_full'),
            }),
        ),
        share({
            key: 'memory',
            label: t('servers.gauge.memory'),
            value: memoryUsedPercent(values),
            threshold: thresholds.memory_percent,
            meta: t('servers.gauge.memory_meta', {
                used: format.bytes(
                    values.memory_total_bytes - values.memory_available_bytes,
                ),
                total: format.bytes(values.memory_total_bytes),
            }),
            failedWord: t('servers.gauge.low_memory'),
        }),
    ];
}

/** The 5-minute load against its threshold (load_per_core x vCPU); Warning from 75 % of the threshold. */
function useServerLoad(values: ServerValues, thresholds: Thresholds) {
    const { t } = useTranslation();
    const format = useFormat();
    const threshold = thresholds.load_per_core * values.cores;
    const load = (value: number) =>
        format.number(value, {
            minimumFractionDigits: 1,
            maximumFractionDigits: 1,
        });
    const cores = t('servers.gauge.cores', {
        count: values.cores,
        formattedCount: format.number(values.cores),
    });
    return {
        tone: toneOf(values.load_5, threshold, threshold * 0.75),
        value: load(values.load_5),
        text: t('servers.load.value', { load: load(values.load_5), cores }),
        cores,
        alertAt: t('servers.load.alert_at', { load: load(threshold) }),
    };
}

const toneTextClass: Record<ScoreRingTone, string | undefined> = {
    neutral: undefined,
    warning: 'text-status-warn',
    failed: 'text-status-down',
};

/** The state word that goes with a non-neutral tone; nothing while fine. */
function StateWord({
    tone,
    failedWord,
}: {
    tone: ScoreRingTone;
    failedWord: string;
}) {
    const { t } = useTranslation();
    if (tone === 'neutral') return null;
    return (
        <span className={cn('text-xs font-medium', toneTextClass[tone])}>
            {tone === 'failed' ? failedWord : t('servers.gauge.near_threshold')}
        </span>
    );
}

/** One column of the values row: the gauge, its label, the state word and the facts under it. */
function ValueColumn({
    gauge,
    label,
    word,
    meta,
}: {
    gauge: ReactNode;
    label: ReactNode;
    word?: ReactNode;
    meta: ReactNode;
}) {
    return (
        <div className="grid min-w-0 content-start justify-items-center gap-2 text-center">
            {gauge}
            <span className="text-xs leading-tight break-all text-muted-foreground">
                {label}
            </span>
            {word}
            <span className="text-xs leading-relaxed text-foreground-faint">
                {meta}
            </span>
        </div>
    );
}

/** The ring with its label, state word, absolute values and threshold under it, as in the Lighthouse score grid. */
function LabelledShare({ share }: { share: ServerShare }) {
    const { t } = useTranslation();
    const percent = usePercent();
    return (
        <ValueColumn
            gauge={
                <ScoreRing
                    score={share.value}
                    label={share.label}
                    text={percent(share.value)}
                    tone={share.tone}
                    threshold={share.threshold}
                />
            }
            label={<span aria-hidden>{share.label}</span>}
            word={<StateWord tone={share.tone} failedWord={share.failedWord} />}
            meta={
                <>
                    {share.meta}
                    <span className="block">
                        {t('servers.gauge.alert_at', {
                            percent: percent(share.threshold),
                        })}
                    </span>
                </>
            }
        />
    );
}

/**
 * The server page's values: a ring for each share in use (disk per shown mount, RAM, CPU without an alert), then the
 * 5-minute load in the same column shape. Colour and a word mean the value can alert.
 */
export function ServerGaugeRow({
    values,
    thresholds,
}: {
    values: ServerValues;
    thresholds: Thresholds;
}) {
    const { t } = useTranslation();
    const percent = usePercent();
    const shares = useServerShares(values, thresholds);
    const load = useServerLoad(values, thresholds);
    return (
        <section aria-label={t('servers.gauge.title')} className="space-y-3">
            <div className="grid grid-cols-2 gap-x-4 gap-y-6 sm:grid-cols-3 lg:grid-cols-6">
                {shares.map((share) => (
                    <LabelledShare key={share.key} share={share} />
                ))}
                <ValueColumn
                    gauge={
                        <ScoreRing
                            score={values.cpu_used_percent}
                            label={t('servers.gauge.cpu')}
                            text={percent(values.cpu_used_percent)}
                            tone="neutral"
                        />
                    }
                    label={<span aria-hidden>{t('servers.gauge.cpu')}</span>}
                    meta={load.cores}
                />
                <ValueColumn
                    // The ring's box, so the value sits at the rings' height.
                    gauge={
                        <span
                            className={cn(
                                'grid size-14 place-items-center font-mono text-lg tabular-nums',
                                toneTextClass[load.tone],
                            )}
                        >
                            {load.value}
                        </span>
                    }
                    label={
                        <span className="inline-flex items-center gap-1">
                            {t('servers.gauge.load')}
                            <InfoTooltip>
                                {t('servers.gauge.load_detail')}
                            </InfoTooltip>
                        </span>
                    }
                    word={
                        <StateWord
                            tone={load.tone}
                            failedWord={t('servers.load.high')}
                        />
                    }
                    meta={`${load.cores} · ${load.alertAt}`}
                />
            </div>
            <p className="text-xs text-muted-foreground">
                {t('servers.gauge.legend')}
            </p>
        </section>
    );
}

/** A list share: the small ring with the bare number (the unit is in the column header), then the state word. */
function ListShare({ share }: { share: ServerShare }) {
    const format = useFormat();
    return (
        <span className="inline-flex flex-col items-center gap-0.5">
            <ScoreRing
                size="sm"
                score={share.value}
                label={`${share.label} (%)`}
                text={format.number(Math.round(share.value))}
                tone={share.tone}
                threshold={share.threshold}
            />
            <StateWord tone={share.tone} failedWord={share.failedWord} />
        </span>
    );
}

/** The list's load: the coloured value and, near or over its threshold, the word. */
function ListLoad({
    values,
    thresholds,
}: {
    values: ServerValues;
    thresholds: Thresholds;
}) {
    const { t } = useTranslation();
    const load = useServerLoad(values, thresholds);
    return (
        <span className="inline-flex flex-col items-center gap-0.5">
            <span
                className={cn(
                    'font-mono text-xs whitespace-nowrap tabular-nums',
                    toneTextClass[load.tone],
                )}
            >
                {load.text}
            </span>
            <StateWord tone={load.tone} failedWord={t('servers.load.high')} />
        </span>
    );
}

/** The list's disk (the fullest shown mount) and RAM used; none until the first report. */
function useListShares(
    values: ServerValues | null,
    thresholds: Thresholds,
): (ServerShare | undefined)[] {
    const shares = useServerShares(values, thresholds);
    return [
        shares
            .filter((share) => share.key.startsWith('disk:'))
            .reduce<ServerShare | undefined>(
                (top, share) =>
                    top === undefined || share.value > top.value ? share : top,
                undefined,
            ),
        shares.find((share) => share.key === 'memory'),
    ];
}

/** One line above the list that says what the values and colours mean. */
export function ServerListLegend({ defaults }: { defaults: Thresholds }) {
    const { t } = useTranslation();
    const format = useFormat();
    return (
        <p className="flex items-center gap-1 text-xs text-muted-foreground">
            {t('servers.list.legend')}
            <InfoTooltip>
                {t('servers.list.legend_detail', {
                    factor: format.number(defaults.load_per_core),
                })}
            </InfoTooltip>
        </p>
    );
}

/** The list row's value columns (desktop table): Disk used, RAM used, Load. */
export function ServerGaugeCells({ server }: { server: ServerRow }) {
    const [disk, memory] = useListShares(server.values, server.thresholds);
    return (
        <>
            {[disk, memory].map((share, index) => (
                <TableCell key={index} className="text-center">
                    {share !== undefined && <ListShare share={share} />}
                </TableCell>
            ))}
            <TableCell className="text-center">
                {server.values !== null && (
                    <ListLoad
                        values={server.values}
                        thresholds={server.thresholds}
                    />
                )}
            </TableCell>
        </>
    );
}

/** The list card's values (below md). */
export function ServerGaugeLine({
    server,
}: {
    server: ServerRow & { values: ServerValues };
}) {
    const { t } = useTranslation();
    const [disk, memory] = useListShares(server.values, server.thresholds);
    const cell = (label: string, value: ReactNode) => (
        <span className="flex flex-col items-center justify-end gap-1 text-center">
            {value}
            <span className="text-xs text-muted-foreground">{label}</span>
        </span>
    );
    return (
        <span className="grid grid-cols-3 items-end gap-3">
            {cell(t('servers.column.disk'), disk && <ListShare share={disk} />)}
            {cell(
                t('servers.column.memory'),
                memory && <ListShare share={memory} />,
            )}
            {cell(
                t('servers.column.load'),
                <ListLoad
                    values={server.values}
                    thresholds={server.thresholds}
                />,
            )}
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
