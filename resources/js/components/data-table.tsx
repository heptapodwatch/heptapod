import { TruncatedText } from '@/components/truncated-text';
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
    Sparkline,
    responseTimeSummary,
} from '@/components/monitoring';
import { bySeverity, isAttentionState, statusLabels } from '@/lib/status';
import {
    EntityLink,
    rowTargetClass,
    rowTargetClick,
} from '@/components/entity-link';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useFormat } from '@/hooks/use-format';
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
    runs: ReturnType<typeof useStreamState>['runs'];
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
    const {
        full,
        showClient,
        selected,
        onSelectionChange,
        siteHref,
        clientFor,
    } = useSiteTableCellOptions();
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
                {full && showClient && (
                    <span className="block truncate text-xs text-muted-foreground @[1300px]/table:hidden">
                        {clientFor(row.original).name}
                    </span>
                )}
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
    return (
        <>
            <div aria-hidden="true">
                <Sparkline
                    data={row.original.response}
                    state={row.original.state}
                    decorative
                />
            </div>
            <span className="sr-only">
                {responseTimeSummary(row.original.response, t, format)}
            </span>
        </>
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
}: {
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
    const { runs } = useStreamState();
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
        columnHelper.display({
            id: 'response',
            header: t('24h'),
            cell: SiteTableResponseCell,
        }),
        ...(!full ? [statusColumn] : []),
        columnHelper.accessor('uptime', {
            header: t('Uptime 30d'),
            cell: ({ row }) => (
                <span
                    className={cn(
                        'text-xs',
                        !hasUptimeCheck(row.original) &&
                            'text-muted-foreground',
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
                ? 'w-auto max-w-0'
                : id === 'why'
                  ? 'w-[175px]'
                  : id === 'uptime'
                    ? 'hidden @[1100px]/table:table-cell @[1100px]/table:w-[128px] text-right'
                    : id === 'checked_at'
                      ? 'w-[145px] text-right'
                      : cn(
                            'hidden',
                            id === 'client_id'
                                ? '@[1300px]/table:table-cell @[1300px]/table:w-[120px]'
                                : id === 'checks'
                                  ? '@[900px]/table:table-cell @[900px]/table:w-[225px]'
                                  : id === 'response'
                                    ? 'text-right @[1100px]/table:table-cell @[1100px]/table:w-[108px]'
                                    : 'w-[84px]',
                        );
    const table = useTable({
        features,
        data,
        columns,
        state: {
            sorting,
            pagination: { pageIndex, pageSize: 50 },
            columnVisibility: {
                state: true,
                client_id: full,
                checks: full,
                why: full,
                response: full,
                checked_at: full,
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
                {showClient && (
                    <span className="flex min-w-0 items-center gap-1 text-xs text-muted-foreground">
                        <span className="truncate">{clientFor(site).name}</span>
                    </span>
                )}
                {worstCheck ? (
                    <span
                        className="text-xs break-words text-muted-foreground"
                        data-site-card-check
                    >
                        {t(worstCheck.label_key)} · {headline(worstCheck)}
                        {worstCheck.open_incident_started_at !== null &&
                            ` · ${t('Since {{time}}', { time: format.monitorTime(worstCheck.open_incident_started_at, 'since').label })}`}
                    </span>
                ) : why(site) ? (
                    <span className="line-clamp-2 text-xs text-muted-foreground">
                        {why(site)}
                    </span>
                ) : null}
                <span className="flex w-full items-center justify-between gap-2 border-t pt-2 text-xs">
                    <span className="text-muted-foreground">
                        {t('30-day uptime')}
                    </span>
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
                </span>
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
                                            aria-sort={
                                                header.column.getIsSorted() ===
                                                'asc'
                                                    ? 'ascending'
                                                    : header.column.getIsSorted() ===
                                                        'desc'
                                                      ? 'descending'
                                                      : 'none'
                                            }
                                        >
                                            <Tooltip>
                                                <TooltipTrigger asChild>
                                                    <Button
                                                        disabled={
                                                            serverMode
                                                                ? !onSort ||
                                                                  ![
                                                                      'name',
                                                                      'state',
                                                                  ].includes(
                                                                      header
                                                                          .column
                                                                          .id,
                                                                  )
                                                                : !header.column.getCanSort()
                                                        }
                                                        variant="ghost"
                                                        size="sm"
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
                                                                ? '-mr-3 max-w-full gap-1 px-1! text-xs @[900px]/table:-mr-1'
                                                                : full &&
                                                                    header
                                                                        .column
                                                                        .id ===
                                                                        'state'
                                                                  ? 'ml-0 max-w-full gap-1 px-1! text-xs'
                                                                  : full &&
                                                                      header
                                                                          .column
                                                                          .id ===
                                                                          'name'
                                                                    ? '-ml-1 max-w-full gap-1 px-1! text-xs'
                                                                    : '-ml-3 max-w-full gap-1 px-1! text-xs @[900px]/table:-ml-1'
                                                        }
                                                        onClick={
                                                            serverMode
                                                                ? () =>
                                                                      onSort?.(
                                                                          header
                                                                              .column
                                                                              .id ===
                                                                              'state'
                                                                              ? 'status'
                                                                              : 'name',
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
                                                              [
                                                                  'name',
                                                                  'state',
                                                              ].includes(
                                                                  header.column
                                                                      .id,
                                                              )
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
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </SiteTableCellContext.Provider>
            </div>
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
