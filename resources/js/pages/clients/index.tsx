import { ListFooter } from '@/components/list-footer';
import { FilterBar } from '@/components/filter-bar';
import { SegmentedControl } from '@/components/segmented-control';
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
import {
    EntityLink,
    openRowTarget,
    rowTargetClass,
} from '@/components/entity-link';
import { TextLink } from '@/components/text-link';
import { cn } from '@/lib/utils';
import { Alert, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Table,
    listCardPadding,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
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
    search: initialSearch,
    state,
    can_manage,
}: Heptapod.Data.Ui.ClientsIndexPageData) {
    const { t } = useTranslation();
    const format = useFormat();
    const { search, changeSearch, cancelPendingSearch } = useLiveSearch(
        initialSearch ?? '',
        (search, options) => visit(search, state, options),
    );
    const visit = (
        search: string,
        nextState = state,
        searchOptions: LiveSearchVisitOptions = {},
    ) => {
        cancelPendingSearch();
        router.get(
            clientsIndex.url(),
            {
                filter: {
                    ...(search ? { search } : {}),
                    ...(nextState !== 'all' ? { state: nextState } : {}),
                },
            },
            {
                ...searchOptions,
                only: ['clients', 'pagination', 'search', 'state'],
                preserveState: true,
                preserveScroll: true,
            },
        );
    };
    useLiveReload(['state.changed'], ['clients', 'pagination']);
    const buffered = useBufferedChanges(clients, (row) => row.client.id);
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
                <FilterBar
                    activeCount={state !== 'all' ? 1 : 0}
                    filtersActive={Boolean(search) || state !== 'all'}
                    onClearFilters={() => visit('', 'all')}
                    search={
                        <Input
                            type="search"
                            className="w-full"
                            aria-label={t('Search clients')}
                            placeholder={t('Search clients')}
                            value={search}
                            onChange={(event) =>
                                changeSearch(event.target.value)
                            }
                        />
                    }
                    segmented={
                        <SegmentedControl
                            label={t('Client state')}
                            value={state}
                            onValueChange={(state) => visit(search, state)}
                            options={[
                                {
                                    value: 'attention',
                                    label: t('Needs attention'),
                                },
                                { value: 'all', label: t('All clients') },
                            ]}
                        />
                    }
                />
                <PendingChanges
                    count={buffered.pendingCount}
                    apply={buffered.apply}
                />
                {buffered.rows.length ? (
                    <div className="overflow-hidden rounded-lg border">
                        <div className="hidden sm:block">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>{t('Client')}</TableHead>
                                        <TableHead className="text-right">
                                            {t('Sites monitored')}
                                        </TableHead>
                                        <TableHead className="text-right">
                                            {t('Status')}
                                        </TableHead>
                                        <TableHead className="text-right">
                                            {t('Open incidents')}
                                        </TableHead>
                                        <TableHead className="text-right">
                                            {t('30-day uptime')}
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {buffered.rows.map(
                                        ({ client, summary }) => (
                                            <TableRow
                                                key={client.id}
                                                className={rowTargetClass}
                                                onClick={openRowTarget(
                                                    clientShow.url(client.id),
                                                )}
                                            >
                                                <TableCell>
                                                    <EntityLink
                                                        name={client.name}
                                                        href={clientShow.url(
                                                            client.id,
                                                        )}
                                                        icon={
                                                            <ClientMark
                                                                small
                                                                client={client}
                                                            />
                                                        }
                                                    />
                                                </TableCell>
                                                <TableCell className="text-right font-mono tabular-nums">
                                                    {format.number(
                                                        summary.sites,
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    {statusSummary(summary)}
                                                </TableCell>
                                                <TableCell className="text-right font-mono tabular-nums">
                                                    {format.number(
                                                        summary.open_incidents,
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-right font-mono tabular-nums">
                                                    {summary.uptime === null
                                                        ? '—'
                                                        : format.uptimePercentage(
                                                              summary.uptime,
                                                          )}
                                                </TableCell>
                                            </TableRow>
                                        ),
                                    )}
                                </TableBody>
                            </Table>
                        </div>
                        <ul className="divide-y sm:hidden">
                            {buffered.rows.map(({ client, summary }) => (
                                <li key={client.id}>
                                    <Link
                                        href={clientShow.url(client.id)}
                                        prefetch
                                        className={cn(
                                            'block space-y-2',
                                            listCardPadding,
                                            rowTargetClass,
                                        )}
                                    >
                                        <EntityLink
                                            name={client.name}
                                            insideLink
                                            icon={
                                                <ClientMark client={client} />
                                            }
                                        />
                                        {statusSummary(summary)}
                                        <span className="flex justify-between gap-2 text-xs text-muted-foreground">
                                            <span>
                                                {t('Sites monitored')}:{' '}
                                                <span className="font-mono tabular-nums">
                                                    {format.number(
                                                        summary.sites,
                                                    )}
                                                </span>
                                            </span>
                                            <span>
                                                {t('Open incidents')}:{' '}
                                                <span className="font-mono tabular-nums">
                                                    {format.number(
                                                        summary.open_incidents,
                                                    )}
                                                </span>
                                            </span>
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {t('30-day uptime')}:{' '}
                                            {summary.uptime === null
                                                ? '—'
                                                : format.uptimePercentage(
                                                      summary.uptime,
                                                  )}
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </div>
                ) : (
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
                            active: Boolean(search) || state !== 'all',
                            title: t('No matching clients'),
                            description: t('Clear filters to see all clients.'),
                        }}
                    />
                )}
                {pagination.total > 0 && (
                    <ListFooter
                        count={t('{{count}} clients', {
                            count: pagination.total,
                            formattedCount: format.number(pagination.total),
                        })}
                        previous={
                            pagination.previous_page_url
                                ? { href: pagination.previous_page_url }
                                : null
                        }
                        next={
                            pagination.next_page_url
                                ? { href: pagination.next_page_url }
                                : null
                        }
                        page={pagination.current_page}
                        lastPage={pagination.last_page}
                    />
                )}
            </PageContainer>
        </>
    );
}
