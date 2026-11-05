import { ListFooter } from '@/components/list-footer';
import {
    FilterBar,
    FilterCombobox,
    FilterSelectTrigger,
} from '@/components/filter-bar';
import { clientComboboxOptions } from '@/components/entity-combobox';
import {
    useLiveSearch,
    type LiveSearchVisitOptions,
} from '@/hooks/use-live-search';
import { PageHeader } from '@/components/page-header';
import { MonitoringHealthNotices } from '@/components/monitoring-health-notices';
import { PageContainer } from '@/components/page-container';
import { Deferred, Head, Link, router } from '@inertiajs/react';
import { useLiveReload } from '@/hooks/use-live';
import { useBufferedChanges } from '@/hooks/use-buffered-changes';
import { useFlip } from '@/hooks/use-flip';
import { PendingChanges } from '@/components/pending-changes';
import { Globe, Plus } from 'lucide-react';
import { useRef } from 'react';
import { useTranslation } from 'react-i18next';
import { DataTable } from '@/components/data-table';
import { SegmentedControl } from '@/components/segmented-control';
import { EmptyState } from '@/components/empty-state';
import { SitesAttentionBand } from '@/components/sites-attention-band';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectValue,
} from '@/components/ui/select';
import { useFormat } from '@/hooks/use-format';
import { statusLabels } from '@/lib/status';
import { checkTypes } from '@/lib/check-types';
import {
    show as siteShow,
    index as sitesIndex,
    create as sitesCreate,
} from '@/routes/sites';

export default function SitesIndex({
    sites,
    clients,
    tags,
    summary,
    can_create,
    filters,
    pagination,
    tiles,
    needs_attention,
    needs_attention_total,
}: Heptapod.Data.Ui.SitesIndexPageData) {
    useLiveReload(
        ['state.changed'],
        [
            'sites',
            'pagination',
            'summary',
            'clients',
            'tags',
            'tiles',
            'needs_attention',
            'needs_attention_total',
            'overallHealth',
        ],
    );
    const listRef = useRef<HTMLDivElement>(null);
    const { t } = useTranslation();
    const format = useFormat();
    const { search, changeSearch, cancelPendingSearch } = useLiveSearch(
        filters.search ?? '',
        (search, options) => visit({ search }, filters.sort, options),
    );
    const hasFilters = Boolean(
        filters.search ||
        filters.client_id ||
        filters.state ||
        filters.tag ||
        filters.check ||
        filters.attention,
    );
    const checkFilter = (
        <Select
            value={filters.check ?? 'all'}
            onValueChange={(value) =>
                visit({ check: value === 'all' ? null : value })
            }
        >
            <FilterSelectTrigger
                aria-label={t('Check')}
                onClear={filters.check ? () => visit({ check: null }) : null}
            >
                <SelectValue />
            </FilterSelectTrigger>
            <SelectContent>
                <SelectItem value="all">{t('All checks')}</SelectItem>
                {Object.entries(checkTypes).map(([key, check]) => (
                    <SelectItem key={key} value={key}>
                        {t(check.label)}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
    const tagFilter = (
        <FilterCombobox
            ariaLabel={t('Tag')}
            options={[
                { value: 'all', label: t('All tags') },
                ...tags.map((tag) => ({ value: tag.name, label: tag.name })),
            ]}
            value={filters.tag ?? 'all'}
            onValueChange={(value) =>
                visit({ tag: value === 'all' ? null : value })
            }
            onClear={filters.tag ? () => visit({ tag: null }) : null}
        />
    );
    const visit = (
        changes: Record<string, string | null>,
        sort = filters.sort,
        searchOptions: LiveSearchVisitOptions = {},
    ) => {
        cancelPendingSearch();
        const current = {
            search,
            attention: filters.attention ? '1' : null,
            client_id: filters.client_id?.toString(),
            state: filters.state,
            tag: filters.tag,
            check: filters.check,
            ...changes,
        };
        router.get(
            sitesIndex.url(),
            {
                filter: Object.fromEntries(
                    Object.entries(current).filter(([, value]) => value),
                ),
                sort,
            },
            {
                ...searchOptions,
                only: ['sites', 'pagination', 'filters'],
                preserveState: true,
                preserveScroll: true,
            },
        );
    };
    return (
        <>
            <Head title={t('Sites')} />
            <PageContainer>
                <PageHeader
                    title={t('Sites')}
                    count={format.number(summary.total)}
                    description={[
                        summary.total > 0
                            ? t('sites.list_summary', {
                                  count: summary.total,
                                  formattedCount: format.number(summary.total),
                              })
                            : t('No sites monitored yet'),
                        summary.attention > 0
                            ? t('sites.list_attention', {
                                  count: format.number(summary.attention),
                              })
                            : null,
                        summary.paused > 0
                            ? t('sites.list_paused', {
                                  count: format.number(summary.paused),
                              })
                            : null,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                    notices={<MonitoringHealthNotices />}
                    actions={
                        <>
                            {can_create && (
                                <Button asChild>
                                    <Link
                                        href={sitesCreate.url()}
                                        prefetch
                                        data-shortcut-create
                                    >
                                        <Plus />
                                        {t('Add site')}
                                    </Link>
                                </Button>
                            )}
                        </>
                    }
                />
                <SitesAttentionBand
                    tiles={tiles}
                    needsAttention={needs_attention}
                    needsAttentionTotal={needs_attention_total}
                    clients={clients}
                    onShowAll={() => {
                        visit({ attention: '1' });
                        listRef.current?.scrollIntoView({ block: 'start' });
                    }}
                />
                <div ref={listRef}>
                    <FilterBar
                        filtersActive={hasFilters}
                        onClearFilters={() =>
                            visit({
                                search: null,
                                attention: null,
                                client_id: null,
                                state: null,
                                tag: null,
                                check: null,
                            })
                        }
                        activeCount={
                            [
                                filters.client_id,
                                filters.state,
                                filters.tag,
                                filters.check,
                                filters.attention,
                            ].filter(Boolean).length
                        }
                        search={
                            <Input
                                type="search"
                                className="w-full"
                                aria-label={t('Search sites')}
                                placeholder={t('Search sites')}
                                value={search}
                                onChange={(event) =>
                                    changeSearch(event.target.value)
                                }
                            />
                        }
                        segmented={
                            <SegmentedControl
                                label={t('Sites')}
                                value={filters.attention ? 'attention' : 'all'}
                                onValueChange={(value) =>
                                    visit({
                                        attention:
                                            value === 'attention' ? '1' : null,
                                    })
                                }
                                options={[
                                    { value: 'all', label: t('All') },
                                    {
                                        value: 'attention',
                                        label: t('Attention'),
                                    },
                                ]}
                            />
                        }
                    >
                        <FilterCombobox
                            ariaLabel={t('Client')}
                            options={[
                                { value: 'all', label: t('All clients') },
                                ...clientComboboxOptions(clients),
                            ]}
                            value={filters.client_id?.toString() ?? 'all'}
                            onValueChange={(value) =>
                                visit({
                                    client_id: value === 'all' ? null : value,
                                })
                            }
                            onClear={
                                filters.client_id
                                    ? () => visit({ client_id: null })
                                    : null
                            }
                        />
                        <Select
                            value={filters.state ?? 'all'}
                            onValueChange={(value) =>
                                visit({ state: value === 'all' ? null : value })
                            }
                        >
                            <FilterSelectTrigger
                                aria-label={t('Status')}
                                onClear={
                                    filters.state
                                        ? () => visit({ state: null })
                                        : null
                                }
                            >
                                <SelectValue />
                            </FilterSelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">
                                    {t('All statuses')}
                                </SelectItem>
                                {(
                                    Object.keys(
                                        statusLabels,
                                    ) as Heptapod.Enums.SiteStatus[]
                                ).map((state) => (
                                    <SelectItem key={state} value={state}>
                                        {t(statusLabels[state])}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {checkFilter}
                        {tagFilter}
                    </FilterBar>
                </div>
                <Deferred
                    data="sites"
                    fallback={
                        <div aria-busy="true" className="space-y-3">
                            {Array.from({ length: 5 }, (_, index) => (
                                <Skeleton key={index} className="h-20 w-full" />
                            ))}
                        </div>
                    }
                >
                    {sites && (
                        <SitesList
                            sites={sites}
                            clients={clients}
                            pagination={pagination}
                            hasFilters={hasFilters}
                            canCreate={can_create}
                            onSort={(sort) => visit({}, sort)}
                        />
                    )}
                </Deferred>
            </PageContainer>
        </>
    );
}

function SitesList({
    sites,
    clients,
    pagination,
    hasFilters,
    canCreate,
    onSort,
}: {
    sites: Heptapod.Data.Ui.SiteRowData[];
    clients: Heptapod.Data.Ui.ClientData[];
    pagination: Heptapod.Data.Ui.PaginationData;
    hasFilters: boolean;
    canCreate: boolean;
    onSort: (sort: string) => void;
}) {
    const { t } = useTranslation();
    const format = useFormat();
    const buffered = useBufferedChanges(
        sites,
        (site) => site.id,
        undefined,
        undefined,
        'sites',
    );
    // Reorders, additions and removals wait behind "N new changes"; applying them moves the rows by FLIP.
    const { ref: rowsRef, flip } = useFlip<HTMLDivElement>();
    return (
        <>
            <PendingChanges
                count={buffered.pendingCount}
                apply={() => flip(buffered.apply)}
            />
            {buffered.rows.length ? (
                <div ref={rowsRef} className="relative">
                    <DataTable
                        data={buffered.rows}
                        clients={clients}
                        full
                        serverMode
                        onSort={(sort) => onSort(sort)}
                        onSelect={(site) => router.visit(siteShow.url(site.id))}
                        siteHref={(site) => siteShow.url(site.id)}
                    />
                </div>
            ) : (
                <EmptyState
                    icon={Globe}
                    title={t('No sites monitored yet')}
                    description={t('Add a site to start monitoring.')}
                    action={
                        canCreate && (
                            <Button asChild>
                                <Link href={sitesCreate.url()}>
                                    <Plus className="size-4" />
                                    {t('Add site')}
                                </Link>
                            </Button>
                        )
                    }
                    filtered={{
                        active: hasFilters,
                        title: t('No matching sites'),
                        description: t('Clear filters to see all sites.'),
                    }}
                />
            )}
            {sites.length > 0 && (
                <ListFooter
                    count={t('{{count}} sites', {
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
        </>
    );
}
