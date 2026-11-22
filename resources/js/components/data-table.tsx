import { TooltipHint, TruncatedText } from '@/components/truncated-text';
import { SiteAvatar } from '@/components/site-avatar';
import {
    columnVisibilityFeature,
    createColumnHelper,
    createSortedRowModel,
    createPaginatedRowModel,
    rowPaginationFeature,
    FlexRender,
    rowSortingFeature,
    sortFn_text,
    sortFn_alphanumeric,
    tableFeatures,
    useTable,
    type SortingState,
    type CellContext,
} from '@tanstack/react-table';
import { ArrowUpDown } from 'lucide-react';
import { createContext, useContext, useRef, useState } from 'react';
import { Link } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Table,
    listCardPadding,
    rowEmphasisClass,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
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
    statusLabels,
} from '@/lib/status';
import {
    EntityLink,
    rowTargetClass,
    rowTargetClick,
} from '@/components/entity-link';
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
import type { SiteDto } from '@/types/monitoring';

// dashboard-01's data-table, adapted to monitoring DTOs without document drag handles.
const features = tableFeatures({
    columnVisibilityFeature,
    rowSortingFeature,
    rowPaginationFeature,
    sortedRowModel: createSortedRowModel(),
    sortFns: { text: sortFn_text, alphanumeric: sortFn_alphanumeric },
    paginatedRowModel: createPaginatedRowModel(),
});
const columnHelper = createColumnHelper<typeof features, SiteDto>();

type SiteTableCellOptions = {
    full: boolean;
    showClient: boolean;
    selected: number[];
    onSelectionChange?: (selected: number[]) => void;
    siteHref?: (site: SiteDto) => string;
    clientFor: (site: SiteDto) => Heptapod.Data.Ui.ClientData;
    runs: ReturnType<typeof useStreamState<'runs'>>;
    historyLoading: boolean;
};
const SiteTableCellContext = createContext<SiteTableCellOptions | null>(null);
function useSiteTableCellOptions() {
    const options = useContext(SiteTableCellContext);
    if (options === null)
        throw new Error('Site table cells require their options provider');
    return options;
}

// TanStack renders cell templates as component types; stable identities preserve focus through live refreshes.
function SiteTableNameCell({
    row,
}: CellContext<typeof features, SiteDto, string>) {
    const { t } = useTranslation();
    const { full, selected, onSelectionChange, siteHref } =
        useSiteTableCellOptions();
    return (
        <div className="flex min-w-0 items-center gap-3">
            {onSelectionChange && (
                <Checkbox
                    aria-label={t('Select {{name}}', {
                        name: row.original.name,
                    })}
                    checked={selected.includes(row.original.id)}
                    onClick={(event) => event.stopPropagation()}
                    onKeyDown={(event) => event.stopPropagation()}
                    onCheckedChange={(checked) =>
                        onSelectionChange(
                            checked
                                ? [...selected, row.original.id]
                                : selected.filter(
                                      (id) => id !== row.original.id,
                                  ),
                        )
                    }
                />
            )}
            <span
                className={cn(
                    'inline-flex shrink-0 items-center gap-2',
                    full && 'hidden',
                )}
            >
                {full ? (
                    <StatusBadge state={row.original.state} />
                ) : (
                    <StatusDot state={row.original.state} />
                )}
            </span>
            <div className="min-w-0">
                <SiteNameCell
                    site={row.original}
                    compact={full}
                    href={siteHref?.(row.original)}
                />
            </div>
        </div>
    );
}
function SiteTableChecksCell({
    row,
}: CellContext<typeof features, SiteDto, unknown>) {
    const { runs } = useSiteTableCellOptions();
    return (
        <CheckStrip
            checks={row.original.checks}
            compact
            runningCheckIds={row.original.checks
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
function SiteTableResponseCell({
    row,
}: CellContext<typeof features, SiteDto, unknown>) {
    const { t } = useTranslation();
    const format = useFormat();
    const { historyLoading } = useSiteTableCellOptions();
    if (historyLoading) return <Skeleton className="h-6 w-20" />;
    return (
        <>
            <div aria-hidden="true">
                <Sparkline
                    data={row.original.response}
                    state={row.original.state}
                    decorative
                    color={responseLineColor(row.original.checks)}
                />
            </div>
            <span className="sr-only">
                {responseTimeSummary(row.original.response, t, format)}
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

function SiteTableCheckedAtCell({
    row,
}: CellContext<typeof features, SiteDto, string | null>) {
    const { t } = useTranslation();
    const format = useFormat();
    return row.original.checked_at === null ? (
        <span className="font-mono text-xs">—</span>
    ) : (
        <Tooltip>
            <TooltipTrigger asChild>
                <time
                    dateTime={row.original.checked_at ?? undefined}
                    tabIndex={-1}
                    className="font-mono text-xs whitespace-nowrap tabular-nums"
                >
                    {['paused', 'disabled'].includes(row.original.state)
                        ? t(statusLabels[row.original.state])
                        : format.monitorTime(row.original.checked_at!).label}
                </time>
            </TooltipTrigger>
            <TooltipContent>
                {format.monitorTime(row.original.checked_at!).exact}
            </TooltipContent>
        </Tooltip>
    );
}

export function DataTable({
    data,
    onSelect,
    selected = [],
    onSelectionChange,
    pageIndex = 0,
    onPageChange,
    full = false,
    onPrefetch,
    clients,
    serverMode = false,
    showClient = true,
    onSort,
    siteHref,
    historyLoading = false,
    pageSize = 50,
    sort,
}: {
    /** The server's current sort (`name`, `-client`, …); the matching header shows its direction. */
    sort?: string;
    /** The server's page size; the table shows the whole page it gets. */
    pageSize?: number;
    /** Uptime and response arrive after the rows; their cells hold a skeleton of the same size until then. */
    historyLoading?: boolean;
    onPrefetch?: (site: SiteDto) => void;
    clients: Heptapod.Data.Ui.ClientData[];
    serverMode?: boolean;
    showClient?: boolean;
    onSort?: (sort: string) => void;
    siteHref?: (site: SiteDto) => string;
    full?: boolean;
    pageIndex?: number;
    onPageChange?: (page: number) => void;
    selected?: number[];
    onSelectionChange?: (selected: number[]) => void;
    data: SiteDto[];
    onSelect: (site: SiteDto) => void;
}) {
    const { t } = useTranslation();
    const format = useFormat();
    const headline = useCheckHeadline();
    const runs = useStreamState('runs');
    const columnChoices = useSiteColumnChoices();
    const showsScores = full && data.some(hasListScoreCheck);
    // Lighthouse shows by default only where at least half of the listed sites have it, so a few scored sites do not
    // squeeze Site, Client and Why on every row.
    const scoresByDefault =
        data.filter(hasListScoreCheck).length * 2 >= data.length;
    // The column choice applies to the full Sites table and its cards; the compact table has fixed columns.
    const shows = (column: HideableSiteColumn) =>
        !full ||
        (columnChoices.get(column) ??
            (column === 'list_scores' ? scoresByDefault : true));
    const hideable: HideableSiteColumn[] = [
        ...(showClient ? (['client_id'] as const) : []),
        'why',
        'checks',
        ...(showsScores ? (['list_scores'] as const) : []),
        'response',
        'uptime',
        'checked_at',
    ];
    const columnsMenu = (
        <SiteColumnsMenu
            columns={hideable}
            choices={columnChoices}
            shows={shows}
        />
    );
    // The server sorts by these columns; clicking the sorted column again reverses it.
    const serverSorts: Record<string, string> = {
        state: 'status',
        name: 'name',
        client_id: 'client',
    };
    const nextSort = (column: string) =>
        sort === serverSorts[column]
            ? `-${serverSorts[column]}`
            : serverSorts[column]!;
    const ariaSort = (column: string, clientSorted: false | 'asc' | 'desc') =>
        serverMode
            ? sort === serverSorts[column]
                ? 'ascending'
                : sort === `-${serverSorts[column]}`
                  ? 'descending'
                  : 'none'
            : clientSorted === 'asc'
              ? 'ascending'
              : clientSorted === 'desc'
                ? 'descending'
                : 'none';
    const [sorting, setSorting] = useState<SortingState>([]);
    const root = useRef<HTMLDivElement>(null);
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
            return t(statusLabels[site.state]);
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
    const fullHeaderLabels: Record<string, string> = {
        response: t('24h response'),
        uptime: t('30-day uptime'),
        checked_at: t('Last checked'),
    };
    const statusColumn = columnHelper.accessor('state', {
        header: t('Status'),
        cell: ({ row }) => <StatusBadge state={row.original.state} />,
    });
    const columns = columnHelper.columns([
        ...(full ? [statusColumn] : []),
        columnHelper.accessor('name', {
            header: t('Site'),
            cell: SiteTableNameCell,
        }),
        ...(showClient
            ? [
                  columnHelper.accessor('client_id', {
                      header: t('Client'),
                      cell: ({ row }) => {
                          const client = clientFor(row.original);
                          return (
                              <span className="flex min-w-0 items-center gap-1 text-xs">
                                  <TruncatedText
                                      label={t('Client')}
                                      content={client.name}
                                  >
                                      <span className="truncate">
                                          {client.name}
                                      </span>
                                  </TruncatedText>
                              </span>
                          );
                      },
                  }),
              ]
            : []),
        columnHelper.display({
            id: 'why',
            header: t('Why'),
            cell: ({ row }) => {
                const reason = why(row.original);
                return (
                    <TruncatedText
                        label={t('Why')}
                        content={reason ?? undefined}
                    >
                        <span className="line-clamp-2 text-xs leading-4 whitespace-normal">
                            {reason ?? '—'}
                        </span>
                    </TruncatedText>
                );
            },
        }),
        columnHelper.display({
            id: 'checks',
            header: t('Checks'),
            cell: SiteTableChecksCell,
        }),
        ...(showsScores
            ? [
                  columnHelper.display({
                      id: 'list_scores',
                      header: t('checks.lighthouse.label'),
                      cell: ({ row }) => <ListScoresCell site={row.original} />,
                  }),
              ]
            : []),
        columnHelper.display({
            id: 'response',
            header: t('24h'),
            cell: SiteTableResponseCell,
        }),
        ...(!full ? [statusColumn] : []),
        columnHelper.accessor('uptime', {
            header: t('Uptime 30d'),
            cell: ({ row }) =>
                historyLoading ? (
                    <Skeleton className="ml-auto h-4 w-14" />
                ) : (
                    <span
                        className={cn(
                            'text-xs',
                            // "No uptime check" wraps, so its long German form never widens the column.
                            !hasUptimeCheck(row.original) &&
                                'block hyphens-auto whitespace-normal text-muted-foreground',
                            row.original.uptime !== null &&
                                'font-mono tabular-nums',
                        )}
                    >
                        {percentage(row.original)}
                    </span>
                ),
        }),
        columnHelper.accessor('checked_at', {
            header: t('Checked'),
            cell: SiteTableCheckedAtCell,
        }),
    ]);
    const columnClass = (id: string) =>
        !full
            ? id === 'name'
                ? 'w-[45%]'
                : id === 'state'
                  ? 'w-[25%]'
                  : 'w-[30%] text-right'
            : id === 'state'
              ? 'w-px'
              : id === 'name'
                ? 'w-[170px] max-w-[170px]'
                : id === 'why'
                  ? 'w-auto max-w-0 min-w-[96px]'
                  : id === 'uptime'
                    ? 'hidden @[1100px]/table:table-cell @[1100px]/table:w-[128px] @[1100px]/table:max-w-[128px] text-right'
                    : id === 'checked_at'
                      ? 'w-[118px] text-right'
                      : cn(
                            'hidden',
                            id === 'client_id'
                                ? '@[600px]/table:table-cell @[600px]/table:w-[110px] @[600px]/table:max-w-[110px]'
                                : id === 'checks'
                                  ? '@[900px]/table:table-cell @[900px]/table:w-[172px]'
                                  : id === 'response'
                                    ? 'text-right @[1100px]/table:table-cell @[1100px]/table:w-[108px]'
                                    : id === 'list_scores'
                                      ? '@[1000px]/table:table-cell @[1000px]/table:w-[104px]'
                                      : 'w-[84px]',
                        );
    const table = useTable({
        features,
        data,
        columns,
        state: {
            sorting,
            pagination: { pageIndex, pageSize },
            columnVisibility: {
                state: true,
                client_id: full && shows('client_id'),
                checks: full && shows('checks'),
                why: full && shows('why'),
                response: full && shows('response'),
                checked_at: full && shows('checked_at'),
                uptime: shows('uptime'),
                list_scores: shows('list_scores'),
            },
        },
        onSortingChange: (updater) => {
            setSorting(updater);
            onPageChange?.(0);
        },
        autoResetPageIndex: false,
        getRowId: (row) => String(row.id),
    });
    const rowClass = (site: SiteDto) =>
        cn(
            site.state === 'paused' && 'bg-muted/40 text-muted-foreground',
            site.state === 'down' && rowEmphasisClass('down'),
        );
    const mobileClass = (site: SiteDto) =>
        cn(
            'flex w-full flex-col gap-2 text-left',
            listCardPadding,
            rowTargetClass,
            rowClass(site),
            onSelectionChange && 'pr-12',
        );
    const mobileContent = (site: SiteDto) => {
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
                        insideLink={siteHref !== undefined}
                        icon={
                            <SiteAvatar
                                name={site.name}
                                iconUrl={site.identity.icon_url}
                                iconBacking={site.identity.icon_backing}
                            />
                        }
                    />
                    <StatusBadge state={site.state} />
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
        <div
            ref={root}
            className="@container/table"
            onKeyDown={(event) => {
                if (
                    !['ArrowDown', 'ArrowUp', 'j', 'k'].includes(event.key) ||
                    !(event.target instanceof HTMLElement) ||
                    !event.target.closest('[data-site-row]')
                )
                    return;
                const rows = Array.from(
                    root.current?.querySelectorAll<HTMLElement>(
                        '[data-site-row]',
                    ) ?? [],
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
        >
            <div className="hidden overflow-hidden rounded-lg border @[600px]/table:block">
                <SiteTableCellContext.Provider
                    value={{
                        full,
                        showClient,
                        selected,
                        onSelectionChange,
                        siteHref,
                        clientFor,
                        runs,
                        historyLoading,
                    }}
                >
                    <Table className={full ? 'table-auto' : 'table-fixed'}>
                        <TableHeader>
                            {table.getHeaderGroups().map((group) => (
                                <TableRow key={group.id}>
                                    {group.headers.map((header) => (
                                        <TableHead
                                            key={header.id}
                                            className={cn(
                                                columnClass(header.column.id),
                                            )}
                                            aria-sort={ariaSort(
                                                header.column.id,
                                                header.column.getIsSorted(),
                                            )}
                                        >
                                            <Tooltip>
                                                <TooltipTrigger asChild>
                                                    <Button
                                                        disabled={
                                                            serverMode
                                                                ? !onSort ||
                                                                  !(
                                                                      header
                                                                          .column
                                                                          .id in
                                                                      serverSorts
                                                                  )
                                                                : !header.column.getCanSort()
                                                        }
                                                        variant="ghost"
                                                        aria-label={
                                                            fullHeaderLabels[
                                                                header.column.id
                                                            ]
                                                        }
                                                        className={
                                                            [
                                                                'uptime',
                                                                'checked_at',
                                                                'response',
                                                            ].includes(
                                                                header.column
                                                                    .id,
                                                            )
                                                                ? '-mr-3 max-w-full gap-1 px-1 text-xs has-[>svg]:px-1 @[900px]/table:-mr-1'
                                                                : full &&
                                                                    header
                                                                        .column
                                                                        .id ===
                                                                        'state'
                                                                  ? 'ml-0 max-w-full gap-1 px-1 text-xs has-[>svg]:px-1'
                                                                  : full &&
                                                                      header
                                                                          .column
                                                                          .id ===
                                                                          'name'
                                                                    ? '-ml-1 max-w-full gap-1 px-1 text-xs has-[>svg]:px-1'
                                                                    : '-ml-3 max-w-full gap-1 px-1 text-xs has-[>svg]:px-1 @[900px]/table:-ml-1'
                                                        }
                                                        onClick={
                                                            serverMode
                                                                ? () =>
                                                                      onSort?.(
                                                                          nextSort(
                                                                              header
                                                                                  .column
                                                                                  .id,
                                                                          ),
                                                                      )
                                                                : header.column.getToggleSortingHandler()
                                                        }
                                                    >
                                                        <span className="whitespace-nowrap">
                                                            <FlexRender
                                                                header={header}
                                                            />
                                                        </span>
                                                        {(serverMode
                                                            ? !!onSort &&
                                                              header.column
                                                                  .id in
                                                                  serverSorts
                                                            : header.column.getCanSort()) && (
                                                            <ArrowUpDown className="size-3" />
                                                        )}
                                                    </Button>
                                                </TooltipTrigger>
                                                <TooltipContent>
                                                    {fullHeaderLabels[
                                                        header.column.id
                                                    ] ?? (
                                                        <FlexRender
                                                            header={header}
                                                        />
                                                    )}
                                                </TooltipContent>
                                            </Tooltip>
                                        </TableHead>
                                    ))}
                                    {full && (
                                        // Its own cell, so hiding any column never hides the gear.
                                        <TableHead className="w-10 px-1 text-right">
                                            {columnsMenu}
                                        </TableHead>
                                    )}
                                </TableRow>
                            ))}
                        </TableHeader>
                        <TableBody>
                            {table.getRowModel().rows.map((row) => (
                                <TableRow
                                    key={row.id}
                                    data-site-row
                                    data-flip-key={`site-row:${row.original.id}`}
                                    tabIndex={siteHref ? undefined : 0}
                                    className={cn(
                                        rowTargetClass,
                                        rowClass(row.original),
                                    )}
                                    aria-label={`${row.original.name}: ${t('Open site')}`}
                                    onMouseEnter={() =>
                                        onPrefetch?.(row.original)
                                    }
                                    onClick={rowTargetClick(() =>
                                        onSelect(row.original),
                                    )}
                                    onKeyDown={(event) => {
                                        if (
                                            event.key === 'Enter' &&
                                            event.target ===
                                                event.currentTarget &&
                                            !event.ctrlKey &&
                                            !event.metaKey &&
                                            !event.altKey &&
                                            !event.shiftKey
                                        ) {
                                            event.preventDefault();
                                            onSelect(row.original);
                                        }
                                    }}
                                >
                                    {row.getVisibleCells().map((cell) => (
                                        <TableCell
                                            key={cell.id}
                                            className={cn(
                                                'overflow-hidden',
                                                columnClass(cell.column.id),
                                            )}
                                        >
                                            <FlexRender cell={cell} />
                                        </TableCell>
                                    ))}
                                    {full && (
                                        <TableCell className="w-10 px-1" />
                                    )}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </SiteTableCellContext.Provider>
            </div>
            {full && (
                // The cards have no header row, so the column choice sits above them.
                <div className="-mt-1 mb-1 flex justify-end @[600px]/table:hidden">
                    {columnsMenu}
                </div>
            )}
            <ul className="divide-y overflow-hidden rounded-lg border @[600px]/table:hidden">
                {table.getRowModel().rows.map((row) => (
                    <li
                        key={row.id}
                        data-flip-key={`site-card:${row.original.id}`}
                        className="relative"
                    >
                        {onSelectionChange && (
                            <Label
                                htmlFor={`select-mobile-${row.id}`}
                                className="absolute top-3 right-3 z-10 after:absolute after:-inset-3"
                            >
                                <Checkbox
                                    id={`select-mobile-${row.id}`}
                                    aria-label={t('Select {{name}}', {
                                        name: row.original.name,
                                    })}
                                    checked={selected.includes(row.original.id)}
                                    onCheckedChange={(checked) =>
                                        onSelectionChange(
                                            checked
                                                ? [...selected, row.original.id]
                                                : selected.filter(
                                                      (id) =>
                                                          id !==
                                                          row.original.id,
                                                  ),
                                        )
                                    }
                                />
                            </Label>
                        )}
                        {siteHref ? (
                            <Link
                                data-site-row
                                href={siteHref(row.original)}
                                aria-label={`${row.original.name}: ${t('Open site')}`}
                                className={mobileClass(row.original)}
                                onMouseEnter={() => onPrefetch?.(row.original)}
                            >
                                {mobileContent(row.original)}
                            </Link>
                        ) : (
                            <button
                                type="button"
                                data-site-row
                                aria-label={`${row.original.name}: ${t('Open site')}`}
                                className={mobileClass(row.original)}
                                onMouseEnter={() => onPrefetch?.(row.original)}
                                onClick={() => onSelect(row.original)}
                            >
                                {mobileContent(row.original)}
                            </button>
                        )}
                    </li>
                ))}
            </ul>
        </div>
    );
}
