import { ListFooter } from '@/components/list-footer';
import {
    FilterBar,
    FilterChip,
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
import { Deferred, Head, Link, router, usePage } from '@inertiajs/react';
import { useLiveReload } from '@/hooks/use-live';
import { useBufferedChanges } from '@/hooks/use-buffered-changes';
import { useFlip } from '@/hooks/use-flip';
import { PendingChanges } from '@/components/pending-changes';
import { Globe, Plus } from 'lucide-react';
import { useMemo, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import { DataTable } from '@/components/data-table';
import { listResponsePoints } from '@/components/monitoring';
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
import { SeverityBadge } from '@/components/security-advisory-list';
import {
    show as siteShow,
    index as sitesIndex,
    create as sitesCreate,
} from '@/routes/sites';

export default function SitesIndex({
    sites,
    sites_history,
    clients,
    tags,
    summary,
    can_create,
    filters,
    pagination,
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
            'pagination',
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
    const { search, changeSearch, cancelPendingSearch } = useLiveSearch(
        filters.search ?? '',
        (search, options) => visit({ search }, filters.sort, options),
    );
    const advisoryValue = filters.advisory
        ? `advisory:${filters.advisory}`
        : filters.package
          ? `package:${filters.package}`
          : filters.advisories
            ? 'any'
            : 'all';
    const clearAdvisories = { advisories: null, package: null, advisory: null };
    const hasFilters = Boolean(
        filters.search ||
        filters.client_id ||
        filters.state ||
        filters.tag ||
        filters.check ||
        filters.attention ||
        advisoryValue !== 'all',
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
    const advisoryFilter = (
        <FilterCombobox
            ariaLabel={t('Security advisories')}
            options={[
                { value: 'all', label: t('All advisories') },
                { value: 'any', label: t('With advisories') },
                ...packageOptions,
                // An advisory ID, or a package no longer listed, comes from a check's Sites affected link.
                ...(advisoryValue.includes(':') &&
                !packageOptions.some((option) => option.value === advisoryValue)
                    ? [
                          {
                              value: advisoryValue,
                              label: (filters.advisory ?? filters.package)!,
                          },
                      ]
                    : []),
            ]}
            value={advisoryValue}
            onValueChange={(value) =>
                visit({
                    advisories: value === 'any' ? '1' : null,
                    package: value.startsWith('package:')
                        ? value.slice('package:'.length)
                        : null,
                    advisory: value.startsWith('advisory:')
                        ? value.slice('advisory:'.length)
                        : null,
                })
            }
            onClear={
                advisoryValue !== 'all' ? () => visit(clearAdvisories) : null
            }
            notice={
                more_advisory_packages
                    ? t('Showing the 100 most severe packages')
                    : undefined
            }
        />
    );
    const tagValue = filters.tag ? `tag:${filters.tag}` : 'all';
    const tagOptions = tags.map((tag) => ({
        value: `tag:${tag.name}`,
        label: tag.name,
    }));
    const tagFilter = (
        <FilterCombobox
            ariaLabel={t('Tag')}
            options={[
                { value: 'all', label: t('All tags') },
                ...tagOptions,
                ...(filters.tag &&
                !tagOptions.some((option) => option.value === tagValue)
                    ? [{ value: tagValue, label: filters.tag }]
                    : []),
            ]}
            value={tagValue}
            onValueChange={(value) =>
                visit({
                    tag: value.startsWith('tag:')
                        ? value.slice('tag:'.length)
                        : null,
                })
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
            advisories: filters.advisories ? '1' : null,
            package: filters.package,
            advisory: filters.advisory,
            ...changes,
        };
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
                        'pagination',
                        'filters',
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
                                ...clearAdvisories,
                            })
                        }
                        activeCount={
                            [
                                filters.client_id,
                                filters.state,
                                filters.tag,
                                filters.check,
                                filters.attention,
                                advisoryValue !== 'all',
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
                        {advisoryFilter}
                        {filters.attention && (
                            <FilterChip
                                label={t('Needs attention')}
                                onClear={() => visit({ attention: null })}
                            />
                        )}
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
                            history={sites_history}
                            clients={clients}
                            pagination={pagination}
                            hasFilters={hasFilters}
                            canCreate={can_create}
                            sort={filters.sort}
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
            {buffered.rows.length ? (
                <div ref={rowsRef} className="relative">
                    <DataTable
                        data={buffered.rows}
                        clients={clients}
                        full
                        serverMode
                        historyLoading={history === undefined}
                        pageSize={pagination.per_page}
                        sort={sort}
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
                    pagination={pagination}
                />
            )}
        </>
    );
}
