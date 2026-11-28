import { AbsoluteTime } from '@/components/absolute-time';
import { TooltipHint, TruncatedText } from '@/components/truncated-text';
import { SiteAvatar } from '@/components/site-avatar';
import type { ReactNode, Ref } from 'react';
import { useTranslation } from 'react-i18next';
import { Skeleton } from '@/components/ui/skeleton';
import { rowEmphasisClass } from '@/components/ui/table';
import {
    SiteNameCell,
    StatusBadge,
    StatusDot,
    CheckStrip,
    responseLineColor,
    responseTimeSummary,
    statusColor,
} from '@/components/monitoring';
import { Sparkline } from '@/components/sparkline';
import {
    bySeverity,
    isAttentionState,
    isSnoozed,
    siteStateLabel,
    statusLabels,
} from '@/lib/status';
import { EntityLink } from '@/components/entity-link';
import {
    ListTable,
    type ListColumn,
    type ListSort,
} from '@/components/list-table';
import {
    Tooltip,
    TooltipContent,
    TooltipDetails,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useFormat } from '@/hooks/use-format';
import {
    SiteColumnsMenu,
    useSiteColumnChoices,
    type HideableSiteColumn,
} from '@/components/site-table-columns';
import { useCheckHeadline } from '@/hooks/use-check-headline';
import { useStreamState } from '@/hooks/use-live';
import { currentRunState } from '@/lib/live-store';
import { cn } from '@/lib/utils';
import { show as siteShow } from '@/routes/sites';
import type { SiteDto } from '@/types/monitoring';

/** The Sites table's server sorts (SitesReader), shared by its headers and the FilterBar's Sort. */
export function useSiteSortColumns() {
    const { t } = useTranslation();
    return {
        state: { header: t('Status'), sort: { key: 'status' } },
        name: { header: t('Site'), sort: { key: 'name' } },
        client_id: { header: t('Client'), sort: { key: 'client' } },
    } satisfies Record<string, { header: string; sort: ListSort }>;
}

function SiteChecksCell({ site }: { site: SiteDto }) {
    const runs = useStreamState('runs');
    return (
        <CheckStrip
            checks={site.checks}
            compact
            runningCheckIds={site.checks
                .filter((check) => {
                    const phase = currentRunState(
                        runs.get(check.id),
                        check.run,
                    )?.phase;
                    return phase === 'started' || phase === 'progress';
                })
                .map((check) => check.id)}
        />
    );
}

function SiteResponseCell({
    site,
    loading,
}: {
    site: SiteDto;
    loading: boolean;
}) {
    const { t } = useTranslation();
    const format = useFormat();
    if (loading) return <Skeleton className="h-6 w-20" />;
    return (
        <>
            <div aria-hidden="true">
                <Sparkline
                    data={site.response}
                    state={site.state}
                    decorative
                    color={responseLineColor(site.checks)}
                />
            </div>
            <span className="sr-only">
                {responseTimeSummary(site.response, t, format)}
            </span>
        </>
    );
}

/** A site whose Lighthouse check runs; the score column shows once a listed site has one. */
function hasListScoreCheck(site: SiteDto): boolean {
    return site.checks.some(
        (check) =>
            check.key === 'lighthouse' &&
            check.enabled &&
            check.state !== 'unavailable',
    );
}

/** The report's score bands: 90 and up good, 50 to 89 needs improvement, below 50 poor. */
function scoreState(score: number): 'ok' | 'warning' | 'failed' {
    return score >= 90 ? 'ok' : score >= 50 ? 'warning' : 'failed';
}

/** The latest Lighthouse scores as four small numbers; good ones stay muted, the rest take their band's colour. */
function ListScoresCell({ site }: { site: SiteDto }) {
    const { t } = useTranslation();
    const format = useFormat();
    const label = t('checks.lighthouse.label');
    const check = site.checks.find((item) => item.key === 'lighthouse');
    if (!hasListScoreCheck(site) || site.list_scores.length === 0) {
        const reason = hasListScoreCheck(site)
            ? t('No scores yet')
            : t('No Lighthouse check');
        return (
            <TooltipHint label={label} content={reason}>
                <span className="inline-flex">
                    <StatusDot state="disabled" label={reason} strip />
                </span>
            </TooltipHint>
        );
    }
    const facts = site.list_scores.map(
        (score) => `${t(score.label_key)}: ${score.score}`,
    );
    const lowest = Math.min(...site.list_scores.map((score) => score.score));
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span
                    role="img"
                    tabIndex={-1}
                    aria-label={`${t('lighthouse.scores')}: ${facts.join(', ')}`}
                    className="inline-flex items-center gap-1"
                >
                    {site.list_scores.map((score) => (
                        <span
                            key={score.label_key}
                            aria-hidden
                            className="w-[3ch] text-right font-mono text-xs tabular-nums"
                            style={{
                                color:
                                    scoreState(score.score) === 'ok'
                                        ? 'var(--muted-foreground)'
                                        : statusColor[scoreState(score.score)],
                            }}
                        >
                            {score.score}
                        </span>
                    ))}
                </span>
            </TooltipTrigger>
            <TooltipContent>
                <TooltipDetails
                    subject={t('lighthouse.scores')}
                    facts={[
                        ...facts,
                        ...(check?.checked_at
                            ? [format.exact(check.checked_at)]
                            : []),
                    ]}
                    status={{
                        state: scoreState(lowest),
                        label: t('Lowest score: {{score}}', { score: lowest }),
                    }}
                />
            </TooltipContent>
        </Tooltip>
    );
}

function SiteCheckedAtCell({ site }: { site: SiteDto }) {
    const { t } = useTranslation();
    const format = useFormat();
    const checkedAt = site.checked_at;
    if (checkedAt === null) return <span className="font-mono text-xs">—</span>;
    if (site.state === 'paused' || site.state === 'disabled') {
        return (
            <TooltipHint
                label={t('Last checked')}
                content={format.exact(checkedAt)}
            >
                <span className="font-mono text-xs whitespace-nowrap">
                    {t(siteStateLabel(site))}
                </span>
            </TooltipHint>
        );
    }
    return (
        <AbsoluteTime
            value={checkedAt}
            mode="listTime"
            label={t('Last checked')}
            className="text-xs whitespace-nowrap"
        />
    );
}

/** Width and visibility per column: the table drops its less important columns as its own width shrinks. */
const columnClass: Record<string, string> = {
    state: 'w-px',
    name: 'w-[170px] max-w-[170px]',
    client_id:
        'hidden @[600px]/table:table-cell @[600px]/table:w-[110px] @[600px]/table:max-w-[110px]',
    why: 'w-auto max-w-0 min-w-[96px]',
    checks: 'hidden @[900px]/table:table-cell @[900px]/table:w-[172px]',
    list_scores: 'hidden @[1000px]/table:table-cell @[1000px]/table:w-[104px]',
    response: 'hidden @[1100px]/table:table-cell @[1100px]/table:w-[108px]',
    uptime: 'hidden @[1100px]/table:table-cell @[1100px]/table:w-[128px] @[1100px]/table:max-w-[128px]',
    checked_at: 'w-[118px]',
};

/**
 * The Sites list (Sites, a client's Sites tab) on the shared ListTable: Status, Site and the columns this browser chose
 * (Settings2 menu), cards below the table breakpoint. Headers sort through the server when `onSort` is given; ArrowUp/Down
 * and j/k move between rows.
 */
export function SitesTable({
    sites,
    clients,
    showClient = true,
    historyLoading = false,
    sort,
    onSort,
    onPrefetch,
    empty,
    count,
    pagination,
    ref,
}: {
    sites: SiteDto[];
    clients: Heptapod.Data.Ui.ClientData[];
    showClient?: boolean;
    /** Uptime and response arrive after the rows; their cells hold a skeleton of the same size until then. */
    historyLoading?: boolean;
    /** The server's current sort (`name`, `-client`, …); the matching header shows its direction. */
    sort?: string;
    onSort?: (sort: string) => void;
    onPrefetch?: (site: SiteDto) => void;
    empty: ReactNode;
    count: ReactNode;
    pagination: Heptapod.Contracts.PaginationData;
    ref?: Ref<HTMLDivElement>;
}) {
    const { t } = useTranslation();
    const format = useFormat();
    const headline = useCheckHeadline();
    const sortColumns = useSiteSortColumns();
    const columnChoices = useSiteColumnChoices();
    const showsScores = sites.some(hasListScoreCheck);
    // Lighthouse shows by default only where at least half of the listed sites have it, so a few scored sites do not
    // squeeze Site, Client and Why on every row.
    const scoresByDefault =
        sites.filter(hasListScoreCheck).length * 2 >= sites.length;
    const shows = (column: HideableSiteColumn) =>
        columnChoices.get(column) ??
        (column === 'list_scores' ? scoresByDefault : true);
    const hideable: HideableSiteColumn[] = [
        ...(showClient ? (['client_id'] as const) : []),
        'why',
        'checks',
        ...(showsScores ? (['list_scores'] as const) : []),
        'response',
        'uptime',
        'checked_at',
    ];
    const hasUptimeCheck = (site: SiteDto) =>
        site.checks.some((check) => check.key === 'uptime');
    const percentage = (site: SiteDto) =>
        !hasUptimeCheck(site)
            ? t('No uptime check')
            : site.uptime === null
              ? t(statusLabels[site.state])
              : format.uptimePercentage(site.uptime);
    const why = (site: SiteDto): string | null => {
        if (site.state === 'ok' || site.state === 'pending') return null;
        if (['maintenance', 'paused', 'disabled'].includes(site.state))
            return t(siteStateLabel(site));
        const relevant = site.checks.filter(
            (check) =>
                check.state === site.state ||
                (site.state === 'failed' && check.state === 'down'),
        );
        const check =
            (site.state === 'down'
                ? site.checks.find((item) => item.key === 'uptime')
                : undefined) ??
            relevant.find((item) => item.open_incident_id !== null) ??
            relevant[0];
        if (check?.summary) {
            const summary = format.summary(check.summary, check.summary_params);
            return check.open_incident_started_at === null
                ? summary
                : `${summary} · ${t('Since {{time}}', { time: format.monitorTime(check.open_incident_started_at, 'since').label })}`;
        }
        return check?.unavailable_reason
            ? t(check.unavailable_reason)
            : t('No reason recorded');
    };
    const clientFor = (site: SiteDto) => {
        const client = clients.find((option) => option.id === site.client_id);
        if (!client)
            throw new Error(`Missing visible client for site ${site.id}`);
        return client;
    };
    const allColumns: ListColumn<SiteDto>[] = [
        {
            id: 'state',
            ...sortColumns.state,
            cell: (site) => (
                <StatusBadge state={site.state} label={siteStateLabel(site)} />
            ),
        },
        {
            id: 'name',
            ...sortColumns.name,
            cell: (site) => (
                <div className="min-w-0">
                    <SiteNameCell
                        site={site}
                        compact
                        href={siteShow.url(site.id)}
                    />
                </div>
            ),
        },
        {
            id: 'client_id',
            ...sortColumns.client_id,
            cell: (site) => (
                <span className="flex min-w-0 items-center gap-1 text-xs">
                    <TruncatedText
                        label={t('Client')}
                        content={clientFor(site).name}
                    >
                        <span className="truncate">{clientFor(site).name}</span>
                    </TruncatedText>
                </span>
            ),
        },
        {
            id: 'why',
            header: t('Why'),
            cell: (site) => (
                <TruncatedText
                    label={t('Why')}
                    content={why(site) ?? undefined}
                >
                    <span className="line-clamp-2 text-xs leading-4 whitespace-normal">
                        {why(site) ?? '—'}
                    </span>
                </TruncatedText>
            ),
        },
        {
            id: 'checks',
            header: t('Checks'),
            cell: (site) => <SiteChecksCell site={site} />,
        },
        {
            id: 'list_scores',
            header: t('checks.lighthouse.label'),
            cell: (site) => <ListScoresCell site={site} />,
        },
        {
            id: 'response',
            header: t('24h'),
            title: t('24h response'),
            align: 'end',
            cell: (site) => (
                <SiteResponseCell site={site} loading={historyLoading} />
            ),
        },
        {
            id: 'uptime',
            header: t('Uptime 30d'),
            title: t('30-day uptime'),
            align: 'end',
            cell: (site) =>
                historyLoading ? (
                    <Skeleton className="ml-auto h-4 w-14" />
                ) : (
                    <span
                        className={cn(
                            'text-xs',
                            // "No uptime check" wraps, so its long German form never widens the column.
                            !hasUptimeCheck(site) &&
                                'block hyphens-auto whitespace-normal text-muted-foreground',
                            site.uptime !== null && 'font-mono tabular-nums',
                        )}
                    >
                        {percentage(site)}
                    </span>
                ),
        },
        {
            id: 'checked_at',
            header: t('Checked'),
            title: t('Last checked'),
            align: 'end',
            cell: (site) => <SiteCheckedAtCell site={site} />,
        },
    ];
    const columns = allColumns
        .filter(
            (column) =>
                ['state', 'name'].includes(column.id) ||
                (hideable.includes(column.id as HideableSiteColumn) &&
                    shows(column.id as HideableSiteColumn)),
        )
        .map((column) => ({
            ...column,
            className: cn(columnClass[column.id], column.className),
        }));
    const card = (site: SiteDto) => {
        const worstCheck = isAttentionState(site.state)
            ? site.checks
                  .filter(
                      (check) =>
                          check.enabled &&
                          !check.freshness_suppressed_by &&
                          isAttentionState(check.state),
                  )
                  .sort(
                      (a, b) =>
                          bySeverity(a.state, b.state) ||
                          Number(b.open_incident_id !== null) -
                              Number(a.open_incident_id !== null) ||
                          a.id - b.id,
                  )[0]
            : undefined;
        return (
            <>
                <div className="flex w-full min-w-0 items-start justify-between gap-3">
                    <EntityLink
                        name={site.name}
                        insideLink
                        icon={
                            <SiteAvatar
                                name={site.name}
                                iconUrl={site.identity.icon_url}
                                iconBacking={site.identity.icon_backing}
                            />
                        }
                    />
                    <StatusBadge
                        state={site.state}
                        label={siteStateLabel(site)}
                    />
                </div>
                <TruncatedText label={t('URL')} content={site.url}>
                    <span className="w-full truncate font-mono text-xs text-muted-foreground">
                        {site.url}
                    </span>
                </TruncatedText>
                {showClient && shows('client_id') && (
                    <span className="flex min-w-0 items-center gap-1 text-xs text-muted-foreground">
                        <span className="truncate">{clientFor(site).name}</span>
                    </span>
                )}
                {!shows('why') ? null : worstCheck ? (
                    <span
                        className="text-xs break-words text-muted-foreground"
                        data-site-card-check
                    >
                        {t(worstCheck.label_key)} · {headline(worstCheck)}
                        {worstCheck.open_incident_started_at !== null &&
                            ` · ${t('Since {{time}}', { time: format.monitorTime(worstCheck.open_incident_started_at, 'since').label })}`}
                        {isSnoozed(worstCheck, Date.now()) &&
                            ` · ${t('Snoozed until {{time}}', { time: format.timestamp(worstCheck.snoozed_until!) })}`}
                    </span>
                ) : why(site) ? (
                    <span className="line-clamp-2 text-xs text-muted-foreground">
                        {why(site)}
                    </span>
                ) : null}
                <span
                    className={cn(
                        'flex w-full items-center justify-between gap-2 border-t pt-2 text-xs',
                        !shows('uptime') && 'hidden',
                    )}
                >
                    <span className="text-muted-foreground">
                        {t('30-day uptime')}
                    </span>
                    {historyLoading ? (
                        <Skeleton className="h-4 w-14" />
                    ) : (
                        <span
                            className={
                                !hasUptimeCheck(site)
                                    ? 'text-muted-foreground'
                                    : site.uptime === null
                                      ? ''
                                      : 'font-mono tabular-nums'
                            }
                        >
                            {percentage(site)}
                        </span>
                    )}
                </span>
                {shows('list_scores') && hasListScoreCheck(site) && (
                    <span className="flex w-full items-center justify-between gap-2 text-xs">
                        <span className="text-muted-foreground">
                            {t('checks.lighthouse.label')}
                        </span>
                        <ListScoresCell site={site} />
                    </span>
                )}
            </>
        );
    };
    return (
        <ListTable
            ref={ref}
            rows={sites}
            rowKey={(site) => site.id}
            rowHref={(site) => siteShow.url(site.id)}
            rowClassName={(site) =>
                cn(
                    site.state === 'paused' &&
                        'bg-muted/40 text-muted-foreground',
                    site.state === 'down' && rowEmphasisClass('down'),
                )
            }
            rowProps={(site) => ({
                'data-site-row': true,
                'aria-label': `${site.name}: ${t('Open site')}`,
                onMouseEnter: () => onPrefetch?.(site),
            })}
            tableClassName="table-auto"
            columns={columns}
            card={card}
            sort={sort}
            onSort={onSort}
            columnsMenu={
                <SiteColumnsMenu
                    columns={hideable}
                    choices={columnChoices}
                    shows={shows}
                />
            }
            empty={empty}
            count={count}
            pagination={pagination}
            onKeyDown={(event) => {
                if (
                    !['ArrowDown', 'ArrowUp', 'j', 'k'].includes(event.key) ||
                    !(event.target instanceof HTMLElement) ||
                    !event.target.closest('[data-site-row]')
                )
                    return;
                const rows = Array.from(
                    event.currentTarget.querySelectorAll<HTMLElement>(
                        '[data-site-row]',
                    ),
                ).filter((row) => row.offsetParent !== null);
                const index = rows.indexOf(
                    event.target.closest<HTMLElement>('[data-site-row]')!,
                );
                const next =
                    rows[
                        index +
                            (event.key === 'ArrowDown' || event.key === 'j'
                                ? 1
                                : -1)
                    ];
                if (next) {
                    event.preventDefault();
                    (
                        next.querySelector<HTMLAnchorElement>('a') ?? next
                    ).focus();
                }
            }}
        />
    );
}
