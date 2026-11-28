import {
    filterDefaults,
    filterValues,
    filtersActive,
    ListFilterBar,
    withFilterValues,
} from '@/components/list-filter-bar';
import { Head, router, useForm, usePoll } from '@inertiajs/react';
import { Plus, Server } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { EmptyState } from '@/components/empty-state';
import { EntityLink } from '@/components/entity-link';
import InputError from '@/components/input-error';
import {
    ListSortSelect,
    ListTable,
    type ListColumn,
} from '@/components/list-table';
import { AbsoluteTime } from '@/components/absolute-time';
import { PageContainer } from '@/components/page-container';
import { PageHeader } from '@/components/page-header';
import { PendingChanges } from '@/components/pending-changes';
import {
    ServerGaugeLine,
    ServerListLegend,
    ServerLoadValue,
    ServerShareGauge,
    ServerStateBadge,
    useServerSize,
} from '@/components/server-values';
import { SheetForm } from '@/components/sheet-form';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Sheet } from '@/components/ui/sheet';
import { useBufferedPage } from '@/hooks/use-buffered-changes';
import { useFormat } from '@/hooks/use-format';
import {
    useLiveSearch,
    type LiveSearchVisitOptions,
} from '@/hooks/use-live-search';
import { index, show, store } from '@/routes/servers';

type ServerRow = Heptapod.Addons.Servers.Data.ServerRowData;

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
                actions={{
                    kind: 'create',
                    valid: form.data.name.trim() !== '',
                    busy: form.processing,
                    onCancel: onClose,
                }}
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
    servers_pagination: latestPagination,
    defaults,
    filters,
    sort,
}: Heptapod.Addons.Servers.Data.ServersPageData) {
    const { t } = useTranslation();
    const format = useFormat();
    const serverSize = useServerSize();
    const [adding, setAdding] = useState(false);
    const visit = (
        changes: Record<string, string | null>,
        nextSort = sort,
        searchOptions: LiveSearchVisitOptions = {},
    ) => {
        cancelPendingSearch();
        const next = { ...filterValues(draftFilters), ...changes };
        router.get(
            index.url(),
            {
                filter: Object.fromEntries(
                    Object.entries(next).filter(([, value]) => value),
                ),
                sort: nextSort,
            },
            {
                ...searchOptions,
                only: ['servers', 'servers_pagination', 'filters', 'sort'],
                preserveState: true,
                preserveScroll: true,
            },
        );
    };
    const { search, changeSearch, cancelPendingSearch } = useLiveSearch(
        filters.search?.value ?? '',
        (search, options) => visit({ search }, sort, options),
    );
    // New reports arrive every minute: values refresh in place; servers another tab added or removed, and rows a new
    // value moves in the sort, wait behind "N new changes". A sort or filter of this tab changes the URL and shows at once.
    usePoll(15_000, { only: ['servers', 'servers_pagination'] });
    const buffered = useBufferedPage(
        servers,
        latestPagination,
        (server) => server.id,
    );
    const servers_pagination = buffered.pagination;
    const draftFilters = withFilterValues(filters, { search: search || null });
    const hasFilters = filtersActive(draftFilters);
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
    const size = (server: ServerRow) =>
        server.values && (
            <span className="block font-mono text-xs text-muted-foreground tabular-nums">
                {serverSize(server.values)}
            </span>
        );
    const columns: ListColumn<ServerRow>[] = [
        {
            id: 'name',
            header: t('servers.column.server'),
            sort: { key: 'name' },
            content: 'name',
            cell: (server) => (
                <>
                    <EntityLink name={server.name} href={show.url(server.id)} />
                    {size(server)}
                </>
            ),
        },
        {
            id: 'status',
            header: t('Status'),
            sort: { key: 'status' },
            cell: (server) => (
                <span className="flex flex-col items-start gap-1">
                    <ServerStateBadge server={server} />
                    {reboot(server)}
                </span>
            ),
        },
        {
            id: 'disk',
            header: t('servers.column.disk'),
            sort: { key: 'disk', firstDirection: 'descending' },
            align: 'center',
            cell: (server) => <ServerShareGauge server={server} share="disk" />,
        },
        {
            id: 'memory',
            header: t('servers.column.memory'),
            sort: { key: 'memory', firstDirection: 'descending' },
            align: 'center',
            cell: (server) => (
                <ServerShareGauge server={server} share="memory" />
            ),
        },
        {
            id: 'load',
            header: t('servers.column.load'),
            sort: { key: 'load', firstDirection: 'descending' },
            align: 'center',
            cell: (server) => <ServerLoadValue server={server} />,
        },
        {
            id: 'sites',
            header: t('servers.column.sites'),
            sort: { key: 'sites', firstDirection: 'descending' },
            align: 'end',
            cell: (server) => (
                <span className="font-mono tabular-nums">
                    {format.number(server.site_count)}
                </span>
            ),
        },
        {
            id: 'last_report',
            header: t('servers.column.last_report'),
            sort: { key: 'last_report' },
            align: 'end',
            cell: (server) =>
                server.last_report_at && (
                    <AbsoluteTime
                        mode="listTime"
                        label={t('servers.last_report')}
                        value={server.last_report_at}
                        className="font-mono text-xs tabular-nums"
                    />
                ),
        },
    ];
    return (
        <>
            <Head title={t('servers.title')} />
            <PageContainer>
                <PageHeader
                    title={t('servers.title')}
                    description={t('servers.list.description')}
                    count={format.number(servers_pagination.total)}
                    badge={
                        <Badge variant="outline">
                            {t('servers.experimental')}
                        </Badge>
                    }
                    actions={addButton}
                />
                {(servers_pagination.total > 0 || hasFilters) && (
                    <ListFilterBar
                        filters={draftFilters}
                        onChange={(changes) =>
                            Object.hasOwn(changes, 'search')
                                ? changeSearch(changes.search ?? '')
                                : visit(changes)
                        }
                        onClearFilters={() => visit(filterDefaults(filters))}
                        sort={
                            <ListSortSelect
                                columns={columns}
                                sort={sort}
                                onSort={(next) => visit({}, next)}
                                breakpoint="xl"
                            />
                        }
                    />
                )}
                <PendingChanges
                    count={buffered.pendingCount}
                    apply={buffered.apply}
                />
                {buffered.rows.length > 0 && (
                    <ServerListLegend defaults={defaults} />
                )}
                <ListTable
                    rows={buffered.rows}
                    rowKey={(server) => server.id}
                    breakpoint="xl"
                    rowHref={(server) => show.url(server.id)}
                    sort={sort}
                    onSort={(next) => visit({}, next)}
                    columns={columns}
                    card={(server) => (
                        <>
                            <span className="flex items-start justify-between gap-3">
                                <span className="min-w-0">
                                    <EntityLink name={server.name} insideLink />
                                    {size(server)}
                                </span>
                                <ServerStateBadge server={server} />
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
                                        {format.number(server.site_count)}
                                    </span>
                                </span>
                                {server.last_report_at && (
                                    <AbsoluteTime
                                        value={server.last_report_at}
                                        mode="listTime"
                                    />
                                )}
                            </span>
                        </>
                    )}
                    empty={
                        <EmptyState
                            icon={Server}
                            title={t('servers.list.empty_title')}
                            description={t('servers.list.empty_description')}
                            action={addButton}
                            filtered={{
                                active: hasFilters,
                                title: t('servers.list.filtered_title'),
                                description: t(
                                    'servers.list.filtered_description',
                                ),
                            }}
                        />
                    }
                    count={t('servers.list.count', {
                        count: servers_pagination.total,
                        formattedCount: format.number(servers_pagination.total),
                    })}
                    pagination={servers_pagination}
                />
            </PageContainer>
            {adding && <AddServerSheet onClose={() => setAdding(false)} />}
        </>
    );
}
