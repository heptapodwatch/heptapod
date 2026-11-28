import { Head, Link, router, useForm, usePoll } from '@inertiajs/react';
import { Plus, Server } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { EmptyState } from '@/components/empty-state';
import {
    EntityLink,
    openRowTarget,
    rowTargetClass,
} from '@/components/entity-link';
import { FilterBar, FilterSelectTrigger } from '@/components/filter-bar';
import { FormSecondaryAction } from '@/components/form-section';
import InputError from '@/components/input-error';
import { ListFooter } from '@/components/list-footer';
import { MonitoringTime } from '@/components/monitoring';
import { PageContainer } from '@/components/page-container';
import { PageHeader } from '@/components/page-header';
import { PendingChanges } from '@/components/pending-changes';
import {
    ServerGaugeCells,
    ServerGaugeLine,
    ServerListLegend,
    ServerStateBadge,
    useServerSize,
} from '@/components/server-values';
import { SheetForm } from '@/components/sheet-form';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectValue,
} from '@/components/ui/select';
import { Sheet } from '@/components/ui/sheet';
import {
    listCardPadding,
    SortableTableHead,
    Table,
    TableBody,
    TableCell,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useBufferedChanges } from '@/hooks/use-buffered-changes';
import { useFormat } from '@/hooks/use-format';
import {
    useLiveSearch,
    type LiveSearchVisitOptions,
} from '@/hooks/use-live-search';
import { cn } from '@/lib/utils';
import { index, show, store } from '@/routes/servers';

type ServerRow = Heptapod.Addons.Servers.Data.ServerRowData;
/** A ServerState, or `attention` for a server with an open issue (ServerReader::NeedsAttention). */
type ServerFilter = Heptapod.Addons.Servers.ServerState | 'attention';

/** The server's sort keys (ServerReader::SortKeys); the cards below md keep the default order, problems first. */
type SortKey =
    | 'status'
    | 'name'
    | 'disk'
    | 'memory'
    | 'load'
    | 'sites'
    | 'last_report';
// Disk, RAM and load show the highest value first.
const descendingFirst: SortKey[] = ['disk', 'memory', 'load', 'sites'];

const stateFilters: Record<ServerFilter, string> = {
    attention: 'Needs attention',
    no_report: 'servers.state.no_report',
    awaiting: 'servers.state.awaiting',
    reporting: 'servers.state.reporting',
};

function AddServerSheet({ onClose }: { onClose: () => void }) {
    const { t } = useTranslation();
    const form = useForm({ name: '' });
    return (
        <Sheet
            open
            onOpenChange={(open) => {
                if (!open && !form.processing) onClose();
            }}
        >
            <SheetForm
                title={t('servers.add.title')}
                description={t('servers.add.description')}
                aria-busy={form.processing}
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(store.url());
                }}
                footer={
                    <>
                        <FormSecondaryAction
                            disabled={form.processing}
                            onClick={onClose}
                        >
                            {t('Cancel')}
                        </FormSecondaryAction>
                        <Button type="submit" disabled={form.processing}>
                            {t('servers.add.submit')}
                        </Button>
                    </>
                }
            >
                <div className="space-y-1.5">
                    <Label htmlFor="server-name">{t('Name')}</Label>
                    <Input
                        id="server-name"
                        name="name"
                        required
                        maxLength={120}
                        value={form.data.name}
                        disabled={form.processing}
                        onChange={(event) =>
                            form.setData('name', event.target.value)
                        }
                        aria-invalid={!!form.errors.name}
                        aria-describedby={
                            form.errors.name ? 'server-name-error' : undefined
                        }
                    />
                    <InputError
                        id="server-name-error"
                        message={form.errors.name}
                    />
                </div>
            </SheetForm>
        </Sheet>
    );
}

export default function ServersIndex({
    servers,
    pagination,
    defaults,
    filters,
    sort,
}: Heptapod.Addons.Servers.Data.ServersPageData) {
    const { t } = useTranslation();
    const format = useFormat();
    const size = useServerSize();
    const [adding, setAdding] = useState(false);
    const visit = (
        changes: { search?: string; state?: string | null },
        nextSort = sort,
        searchOptions: LiveSearchVisitOptions = {},
    ) => {
        cancelPendingSearch();
        const next = { search, state: filters.state, ...changes };
        router.get(
            index.url(),
            {
                filter: {
                    ...(next.search ? { search: next.search } : {}),
                    ...(next.state ? { state: next.state } : {}),
                },
                ...(nextSort !== 'status' ? { sort: nextSort } : {}),
            },
            {
                ...searchOptions,
                only: ['servers', 'pagination', 'filters', 'sort'],
                preserveState: true,
                preserveScroll: true,
            },
        );
    };
    const { search, changeSearch, cancelPendingSearch } = useLiveSearch(
        filters.search ?? '',
        (search, options) => visit({ search }, sort, options),
    );
    // New reports arrive every minute: values refresh in place; servers another tab added or removed, and rows a new
    // value moves in the sort, wait behind "N new changes". A sort or filter of this tab changes the URL and shows at once.
    usePoll(15_000, { only: ['servers', 'pagination'] });
    const buffered = useBufferedChanges(servers, (server) => server.id);
    const filtersActive = Boolean(search) || filters.state !== null;
    const reboot = (server: ServerRow) =>
        server.values?.reboot_required === true && (
            <span className="text-xs text-status-warn">
                {t('servers.fact.reboot_required')}
            </span>
        );
    const addButton = (
        <Button onClick={() => setAdding(true)}>
            <Plus className="size-4" />
            {t('servers.add.action')}
        </Button>
    );
    const head = (
        sortKey: SortKey,
        label: string,
        align?: 'center' | 'end',
    ) => (
        <SortableTableHead
            sortKey={sortKey}
            sort={sort}
            onSort={(next) => visit({}, next)}
            firstDirection={
                descendingFirst.includes(sortKey) ? 'descending' : 'ascending'
            }
            justify={align}
            className={
                align === 'center'
                    ? 'text-center'
                    : align === 'end'
                      ? 'text-right'
                      : undefined
            }
        >
            {label}
        </SortableTableHead>
    );
    return (
        <>
            <Head title={t('servers.title')} />
            <PageContainer>
                <PageHeader
                    title={t('servers.title')}
                    description={t('servers.list.description')}
                    count={format.number(pagination.total)}
                    badge={
                        <Badge variant="outline">
                            {t('servers.experimental')}
                        </Badge>
                    }
                    actions={addButton}
                />
                {(pagination.total > 0 || filtersActive) && (
                    <FilterBar
                        activeCount={filters.state !== null ? 1 : 0}
                        filtersActive={filtersActive}
                        onClearFilters={() =>
                            visit({ search: '', state: null })
                        }
                        search={
                            <Input
                                type="search"
                                className="w-full"
                                aria-label={t('servers.list.search')}
                                placeholder={t('servers.list.search')}
                                value={search}
                                onChange={(event) =>
                                    changeSearch(event.target.value)
                                }
                            />
                        }
                    >
                        <Select
                            value={filters.state ?? 'all'}
                            onValueChange={(value) =>
                                visit({
                                    state:
                                        value === 'all'
                                            ? null
                                            : (value as ServerFilter),
                                })
                            }
                        >
                            <FilterSelectTrigger
                                aria-label={t('Status')}
                                onClear={
                                    filters.state !== null
                                        ? () => visit({ state: null })
                                        : null
                                }
                            >
                                <SelectValue />
                            </FilterSelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">
                                    {t('servers.list.all_states')}
                                </SelectItem>
                                {Object.entries(stateFilters).map(
                                    ([value, label]) => (
                                        <SelectItem key={value} value={value}>
                                            {t(label)}
                                        </SelectItem>
                                    ),
                                )}
                            </SelectContent>
                        </Select>
                    </FilterBar>
                )}
                <PendingChanges
                    count={buffered.pendingCount}
                    apply={buffered.apply}
                />
                {/* A page beyond the end comes back as the last page, so no rows with a total means none match. */}
                {buffered.rows.length === 0 && pagination.total === 0 ? (
                    <EmptyState
                        icon={Server}
                        title={t('servers.list.empty_title')}
                        description={t('servers.list.empty_description')}
                        action={addButton}
                        filtered={{
                            active: filtersActive,
                            title: t('servers.list.filtered_title'),
                            description: t('servers.list.filtered_description'),
                        }}
                    />
                ) : (
                    <>
                        <ServerListLegend defaults={defaults} />
                        <div className="overflow-hidden rounded-lg border">
                            <div className="hidden md:block">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            {head(
                                                'name',
                                                t('servers.column.server'),
                                            )}
                                            {head('status', t('Status'))}
                                            {head(
                                                'disk',
                                                t('servers.column.disk'),
                                                'center',
                                            )}
                                            {head(
                                                'memory',
                                                t('servers.column.memory'),
                                                'center',
                                            )}
                                            {head(
                                                'load',
                                                t('servers.column.load'),
                                                'center',
                                            )}
                                            {head(
                                                'sites',
                                                t('servers.column.sites'),
                                                'end',
                                            )}
                                            {head(
                                                'last_report',
                                                t('servers.column.last_report'),
                                                'end',
                                            )}
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {buffered.rows.map((server) => (
                                            <TableRow
                                                key={server.id}
                                                className={rowTargetClass}
                                                onClick={openRowTarget(
                                                    show.url(server.id),
                                                )}
                                            >
                                                <TableCell column="name">
                                                    <EntityLink
                                                        name={server.name}
                                                        href={show.url(
                                                            server.id,
                                                        )}
                                                    />
                                                    {server.values && (
                                                        <span className="block font-mono text-xs text-muted-foreground tabular-nums">
                                                            {size(
                                                                server.values,
                                                            )}
                                                        </span>
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    <span className="flex flex-col items-start gap-1">
                                                        <ServerStateBadge
                                                            server={server}
                                                        />
                                                        {reboot(server)}
                                                    </span>
                                                </TableCell>
                                                <ServerGaugeCells
                                                    server={server}
                                                />
                                                <TableCell className="text-right font-mono tabular-nums">
                                                    {format.number(
                                                        server.site_count,
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    {server.last_report_at && (
                                                        <MonitoringTime
                                                            value={
                                                                server.last_report_at
                                                            }
                                                            className="font-mono text-xs tabular-nums"
                                                        />
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                            <ul className="divide-y md:hidden">
                                {buffered.rows.map((server) => (
                                    <li key={server.id}>
                                        <Link
                                            href={show.url(server.id)}
                                            className={cn(
                                                'block space-y-2',
                                                listCardPadding,
                                                rowTargetClass,
                                            )}
                                        >
                                            <span className="flex items-start justify-between gap-3">
                                                <span className="min-w-0">
                                                    <EntityLink
                                                        name={server.name}
                                                        insideLink
                                                    />
                                                    {server.values && (
                                                        <span className="block font-mono text-xs text-muted-foreground tabular-nums">
                                                            {size(
                                                                server.values,
                                                            )}
                                                        </span>
                                                    )}
                                                </span>
                                                <ServerStateBadge
                                                    server={server}
                                                />
                                            </span>
                                            {reboot(server)}
                                            {server.values && (
                                                <ServerGaugeLine
                                                    server={{
                                                        ...server,
                                                        values: server.values,
                                                    }}
                                                />
                                            )}
                                            <span className="flex justify-between gap-2 text-xs text-muted-foreground">
                                                <span>
                                                    {t('servers.column.sites')}:{' '}
                                                    <span className="font-mono tabular-nums">
                                                        {format.number(
                                                            server.site_count,
                                                        )}
                                                    </span>
                                                </span>
                                                {server.last_report_at && (
                                                    <span className="font-mono tabular-nums">
                                                        {
                                                            format.monitorTime(
                                                                server.last_report_at,
                                                            ).label
                                                        }
                                                    </span>
                                                )}
                                            </span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </div>
                        <ListFooter
                            count={t('servers.list.count', {
                                count: pagination.total,
                                formattedCount: format.number(pagination.total),
                            })}
                            pagination={pagination}
                        />
                    </>
                )}
            </PageContainer>
            {adding && <AddServerSheet onClose={() => setAdding(false)} />}
        </>
    );
}
