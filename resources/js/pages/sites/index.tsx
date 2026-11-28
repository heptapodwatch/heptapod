import {
    filterDefaults,
    filterValues,
    filtersActive,
    ListFilterBar,
    withFilterValues,
} from '@/components/list-filter-bar';
import {
    useLiveSearch,
    type LiveSearchVisitOptions,
} from '@/hooks/use-live-search';
import { PageHeader } from '@/components/page-header';
import { MonitoringHealthNotices } from '@/components/monitoring-health-notices';
import { PageContainer } from '@/components/page-container';
import { Deferred, Head, Link, router, usePage } from '@inertiajs/react';
import { useLiveReload } from '@/hooks/use-live';
import { useBufferedChanges } from '@/hooks/use-buffered-changes';
import { useFlip } from '@/hooks/use-flip';
import { PendingChanges } from '@/components/pending-changes';
import { Globe, Plus } from 'lucide-react';
import { useMemo, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import { ListSortSelect } from '@/components/list-table';
import { SitesTable, useSiteSortColumns } from '@/components/sites-table';
import { listResponsePoints } from '@/components/monitoring';
import { EmptyState } from '@/components/empty-state';
import { SitesAttentionBand } from '@/components/sites-attention-band';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { useFormat } from '@/hooks/use-format';
import { SeverityBadge } from '@/components/security-advisory-list';
import { index as sitesIndex, create as sitesCreate } from '@/routes/sites';

export default function SitesIndex({
    sites,
    sites_history,
    clients,
    tags,
    summary,
    can_create,
    filters,
    sort: currentSort,
    sites_pagination,
    tiles,
    needs_attention,
    needs_attention_total,
    advisory_packages,
    more_advisory_packages,
}: Heptapod.Data.Ui.SitesIndexPageData) {
    useLiveReload(
        ['state.changed'],
        [
            'sites',
            'sites_history',
            'sites_pagination',
            'summary',
            'clients',
            'tags',
            'tiles',
            'needs_attention',
            'needs_attention_total',
            'advisory_packages',
            'more_advisory_packages',
            'overallHealth',
        ],
    );
    const page = usePage();
    const listRef = useRef<HTMLDivElement>(null);
    const { t } = useTranslation();
    const format = useFormat();
    const sortColumns = useSiteSortColumns();
    const { search, changeSearch, cancelPendingSearch } = useLiveSearch(
        filters.search?.value ?? '',
        (search, options) => visit({ search }, currentSort, options),
    );
    const draftFilters = withFilterValues(filters, { search: search || null });
    const hasFilters = filtersActive(draftFilters);
    const packageOptions = advisory_packages.map((item) => ({
        value: `package:${item.package}`,
        label: item.package,
        secondary: t('{{count}} sites', {
            count: item.sites,
            formattedCount: format.number(item.sites),
        }),
        badge: <SeverityBadge severity={item.severity} />,
        group: t('Packages'),
    }));
    const tagOptions = tags.map((tag) => ({
        value: `tag:${tag.name}`,
        label: tag.name,
    }));
    const visit = (
        changes: Record<string, string | null>,
        sort = currentSort,
        searchOptions: LiveSearchVisitOptions = {},
    ) => {
        cancelPendingSearch();
        const current = { ...filterValues(draftFilters), ...changes };
        // Inertia applies an async response whenever the path matches, so a first-load deferred group still in
        // flight would answer for the old filters and overwrite this visit's rows and URL. Cancel those loads and
        // request their props with this visit instead.
        const pendingDeferred = Object.values({
            ...page.initialDeferredProps,
            ...page.deferredProps,
        })
            .flat()
            .filter(
                (prop) =>
                    (page.props as Record<string, unknown>)[prop] === undefined,
            );
        if (pendingDeferred.length > 0) {
            router.cancelAll({ prefetch: false, sync: false });
        }
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
                only: [
                    ...new Set([
                        'sites',
                        'sites_history',
                        'sites_pagination',
                        'filters',
                        'sort',
                        ...pendingDeferred,
                    ]),
                ],
                preserveState: true,
                preserveScroll: true,
            },
        );
    };
    const addSiteAction = can_create ? (
        <Button asChild>
            <Link href={sitesCreate.url()} prefetch data-shortcut-create>
                <Plus />
                {t('Add site')}
            </Link>
        </Button>
    ) : null;
    return (
        <>
            <Head title={t('Sites')} />
            <PageContainer>
                {/* The band is the header of a portfolio with sites (ring, headline, meta); an empty one gets the plain header. */}
                {tiles.sites === 0 && (
                    <PageHeader
                        title={t('Sites')}
                        description={t('No sites monitored yet')}
                        notices={<MonitoringHealthNotices />}
                        actions={addSiteAction}
                    />
                )}
                <SitesAttentionBand
                    actions={addSiteAction}
                    notices={<MonitoringHealthNotices />}
                    clientCount={summary.clients}
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
                    <ListFilterBar
                        filters={draftFilters}
                        sources={{
                            clients: {
                                kind: 'clients',
                                scope: { browse: true },
                            },
                            tags: tagOptions,
                            advisories: [
                                { value: 'any:1', label: t('With advisories') },
                                ...packageOptions,
                            ],
                        }}
                        optionNotices={
                            more_advisory_packages
                                ? {
                                      advisories: t(
                                          'Showing the 100 most severe packages',
                                      ),
                                  }
                                : undefined
                        }
                        onChange={(changes) =>
                            Object.hasOwn(changes, 'search')
                                ? changeSearch(changes.search ?? '')
                                : visit(changes)
                        }
                        onClearFilters={() => visit(filterDefaults(filters))}
                        sort={
                            <ListSortSelect
                                columns={Object.values(sortColumns)}
                                sort={currentSort}
                                onSort={(sort) => visit({}, sort)}
                            />
                        }
                    />
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
                            history={sites_history}
                            clients={clients}
                            pagination={sites_pagination}
                            hasFilters={hasFilters}
                            canCreate={can_create}
                            sort={currentSort}
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
    history,
    clients,
    pagination,
    hasFilters,
    canCreate,
    sort,
    onSort,
}: {
    sites: Heptapod.Data.Ui.SiteRowData[];
    history: Heptapod.Data.Ui.SiteHistoryData[] | undefined;
    clients: Heptapod.Data.Ui.ClientData[];
    pagination: Heptapod.Contracts.PaginationData;
    hasFilters: boolean;
    canCreate: boolean;
    sort: string;
    onSort: (sort: string) => void;
}) {
    const { t } = useTranslation();
    const format = useFormat();
    // Merged before buffering, so a held row keeps the history it was shown with.
    const rows = useMemo(() => {
        if (history === undefined) return sites;
        const byId = new Map(history.map((entry) => [entry.site_id, entry]));
        return sites.map((site) => {
            const entry = byId.get(site.id);
            return entry
                ? {
                      ...site,
                      uptime: entry.uptime,
                      response: listResponsePoints(entry.response),
                  }
                : site;
        });
    }, [sites, history]);
    const buffered = useBufferedChanges(
        rows,
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
            <SitesTable
                ref={rowsRef}
                sites={buffered.rows}
                clients={clients}
                historyLoading={history === undefined}
                sort={sort}
                onSort={onSort}
                empty={
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
                }
                count={t('{{count}} sites', {
                    count: pagination.total,
                    formattedCount: format.number(pagination.total),
                })}
                pagination={pagination}
            />
        </>
    );
}
