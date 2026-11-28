import {
    filterDefaults,
    filterValues,
    filtersActive,
    ListFilterBar,
    withFilterValues,
} from '@/components/list-filter-bar';
import { ListTable } from '@/components/list-table';
import {
    useLiveSearch,
    type LiveSearchVisitOptions,
} from '@/hooks/use-live-search';
import { PageHeader } from '@/components/page-header';
import { PageContainer } from '@/components/page-container';
import { Head, Link, router } from '@inertiajs/react';
import { Plus, Users } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { StatusDot } from '@/components/monitoring';
import { stateCountOrder, statusLabels } from '@/lib/status';
import type { SiteState } from '@/types/monitoring';
import { EmptyState } from '@/components/empty-state';
import { ClientMark } from '@/components/client-mark';
import { EntityLink } from '@/components/entity-link';
import { TextLink } from '@/components/text-link';
import { Alert, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { useFormat } from '@/hooks/use-format';
import { useLiveReload } from '@/hooks/use-live';
import { useBufferedChanges } from '@/hooks/use-buffered-changes';
import { PendingChanges } from '@/components/pending-changes';
import {
    create as clientCreate,
    index as clientsIndex,
    show as clientShow,
} from '@/routes/clients';

export default function ClientsIndex({
    clients,
    pagination,
    filters,
    can_manage,
}: Heptapod.Data.Ui.ClientsIndexPageData) {
    const { t } = useTranslation();
    const format = useFormat();
    const state = filters.state?.value;
    const { search, changeSearch, cancelPendingSearch } = useLiveSearch(
        filters.search?.value ?? '',
        (search, options) => visit({ search }, options),
    );
    const draftFilters = withFilterValues(filters, { search: search || null });
    const visit = (
        changes: Record<string, string | null>,
        searchOptions: LiveSearchVisitOptions = {},
    ) => {
        cancelPendingSearch();
        router.get(
            clientsIndex.url(),
            {
                filter: Object.fromEntries(
                    Object.entries({
                        ...filterValues(draftFilters),
                        ...changes,
                    }).filter(
                        ([key, value]) =>
                            value && value !== filters[key]?.default,
                    ),
                ),
            },
            {
                ...searchOptions,
                only: ['clients', 'pagination', 'filters'],
                preserveState: true,
                preserveScroll: true,
            },
        );
    };
    useLiveReload(['state.changed'], ['clients', 'pagination']);
    const buffered = useBufferedChanges(clients, (row) => row.client.id);
    const uptime = (summary: Heptapod.Data.Ui.DashboardTilesData) =>
        summary.uptime === null ? '—' : format.uptimePercentage(summary.uptime);
    const statusSummary = (summary: Heptapod.Data.Ui.DashboardTilesData) => {
        // OK and Unavailable are not listed: the row shows what needs a look.
        const counts: Partial<Record<SiteState, number>> = {
            down: summary.down,
            failed: summary.failed,
            'check-error': summary.check_errors,
            warning: summary.warning,
            pending: summary.pending,
            maintenance: summary.maintenance,
            paused: summary.paused,
            disabled: summary.disabled,
        };
        const populated = stateCountOrder.flatMap((state) => {
            const count = counts[state] ?? 0;
            return count > 0 ? [[state, count] as const] : [];
        });
        return (
            <span className="inline-flex flex-wrap items-center gap-x-3 gap-y-1 text-xs tabular-nums">
                {populated.map(([state, count]) => (
                    <span
                        key={state}
                        className="inline-flex items-center gap-1"
                    >
                        <StatusDot
                            state={state}
                            label={`${t(statusLabels[state])}: ${format.number(count)}`}
                        />
                        <span aria-hidden>
                            {t(statusLabels[state])}: {format.number(count)}
                        </span>
                    </span>
                ))}
                {summary.ok > 0 ? (
                    <span className="text-muted-foreground">
                        {t('clients.healthy_count', {
                            number: format.number(summary.ok),
                        })}
                    </span>
                ) : populated.length === 0 ? (
                    <span className="text-muted-foreground">—</span>
                ) : null}
            </span>
        );
    };
    return (
        <>
            <Head title={t('Clients')} />
            <PageContainer>
                <PageHeader
                    title={t('Clients')}
                    description={t('pages.clients.description')}
                    count={format.number(pagination.total)}
                    actions={
                        <>
                            {can_manage && (
                                <Button asChild>
                                    <Link
                                        href={clientCreate.url()}
                                        data-shortcut-create
                                    >
                                        <Plus className="size-4" />
                                        {t('New client')}
                                    </Link>
                                </Button>
                            )}
                        </>
                    }
                    notices={
                        state === 'archived' && (
                            <Alert
                                tone="info"
                                action={
                                    <TextLink href={clientsIndex.url()}>
                                        {t('Show active clients')}
                                    </TextLink>
                                }
                            >
                                <AlertTitle>{t('Archived clients')}</AlertTitle>
                            </Alert>
                        )
                    }
                />
                <ListFilterBar
                    filters={draftFilters}
                    onChange={(changes) =>
                        Object.hasOwn(changes, 'search')
                            ? changeSearch(changes.search ?? '')
                            : visit(changes)
                    }
                    onClearFilters={() => visit(filterDefaults(filters))}
                />
                <PendingChanges
                    count={buffered.pendingCount}
                    apply={buffered.apply}
                />
                <ListTable
                    rows={buffered.rows}
                    rowKey={({ client }) => client.id}
                    rowHref={({ client }) => clientShow.url(client.id)}
                    columns={[
                        {
                            id: 'client',
                            header: t('Client'),
                            content: 'name',
                            cell: ({ client }) => (
                                <EntityLink
                                    name={client.name}
                                    href={clientShow.url(client.id)}
                                    icon={<ClientMark small client={client} />}
                                />
                            ),
                        },
                        {
                            id: 'sites',
                            header: t('Sites monitored'),
                            align: 'end',
                            cell: ({ summary }) => (
                                <span className="font-mono tabular-nums">
                                    {format.number(summary.sites)}
                                </span>
                            ),
                        },
                        {
                            id: 'status',
                            header: t('Status'),
                            align: 'end',
                            cell: ({ summary }) => statusSummary(summary),
                        },
                        {
                            id: 'open_incidents',
                            header: t('Open incidents'),
                            align: 'end',
                            cell: ({ summary }) => (
                                <span className="font-mono tabular-nums">
                                    {format.number(summary.open_incidents)}
                                </span>
                            ),
                        },
                        {
                            id: 'uptime',
                            header: t('30-day uptime'),
                            align: 'end',
                            cell: ({ summary }) => (
                                <span className="font-mono tabular-nums">
                                    {uptime(summary)}
                                </span>
                            ),
                        },
                    ]}
                    card={({ client, summary }) => (
                        <>
                            <EntityLink
                                name={client.name}
                                insideLink
                                icon={<ClientMark client={client} />}
                            />
                            {statusSummary(summary)}
                            <span className="flex justify-between gap-2 text-xs text-muted-foreground">
                                <span>
                                    {t('Sites monitored')}:{' '}
                                    <span className="font-mono tabular-nums">
                                        {format.number(summary.sites)}
                                    </span>
                                </span>
                                <span>
                                    {t('Open incidents')}:{' '}
                                    <span className="font-mono tabular-nums">
                                        {format.number(summary.open_incidents)}
                                    </span>
                                </span>
                            </span>
                            <span className="text-xs text-muted-foreground">
                                {t('30-day uptime')}: {uptime(summary)}
                            </span>
                        </>
                    )}
                    empty={
                        <EmptyState
                            icon={Users}
                            title={t('No clients yet')}
                            description={t(
                                'Add a client to group its sites, alerts and reports.',
                            )}
                            action={
                                can_manage && (
                                    <Button asChild>
                                        <Link href={clientCreate.url()}>
                                            <Plus className="size-4" />
                                            {t('New client')}
                                        </Link>
                                    </Button>
                                )
                            }
                            filtered={{
                                active: filtersActive(draftFilters),
                                title: t('No matching clients'),
                                description: t(
                                    'Clear filters to see all clients.',
                                ),
                            }}
                        />
                    }
                    footer={{
                        count: t('{{count}} clients', {
                            count: pagination.total,
                            formattedCount: format.number(pagination.total),
                        }),
                        pagination,
                    }}
                />
            </PageContainer>
        </>
    );
}
