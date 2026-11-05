import { ActivityFeedGroup } from '@/components/activity-feed-group';
import { Activity as ActivityIcon } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import { ListFooter } from '@/components/list-footer';
import {
    FilterBar,
    FilterCombobox,
    FilterSelectTrigger,
} from '@/components/filter-bar';
import { clientComboboxOptions } from '@/components/entity-combobox';
import { PageHeader } from '@/components/page-header';
import { PageContainer } from '@/components/page-container';
import { Head, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectValue,
} from '@/components/ui/select';
import { PendingChanges } from '@/components/pending-changes';
import { useBufferedChanges } from '@/hooks/use-buffered-changes';
import { useLiveReload } from '@/hooks/use-live';
import { useFormat } from '@/hooks/use-format';
import { checkTypes } from '@/lib/check-types';
import { index as activityIndex } from '@/routes/activity';
import { exportMethod as exportIncidents } from '@/routes/incidents';
import { TextLink } from '@/components/text-link';

type ActivityFilters = Omit<
    Heptapod.Data.Ui.ActivityFilterData,
    'before' | 'after'
>;

function isCheckKey(value: string): value is Heptapod.Contracts.CheckKey {
    return Object.hasOwn(checkTypes, value);
}

export default function Activity({
    events,
    older_cursor,
    newer_cursor,
    retention_days,
    clients,
    filters,
    expanded_id,
    expanded_events,
    event_page,
}: Heptapod.Data.Ui.ActivityPageData) {
    const reloadPage = useLiveReload(
        ['state.changed'],
        ['events', 'older_cursor', 'newer_cursor'],
        undefined,
        true,
        { pollFallback: false },
    );
    const buffered = useBufferedChanges(
        events,
        (group) => group.id,
        undefined,
        (group) => JSON.stringify(group),
    );
    const [animatedIds, setAnimatedIds] = useState<Set<number>>(new Set());
    const shownGroups = new Map(
        buffered.rows.map((group) => [group.id, JSON.stringify(group)]),
    );
    // A row pushed off the end of this page is not another new change.
    const pendingChanges =
        buffered.pendingCount === 0
            ? 0
            : Math.max(
                  1,
                  events.filter(
                      (group) =>
                          shownGroups.get(group.id) !== JSON.stringify(group),
                  ).length,
              );
    const acceptChanges = () => {
        const shown = new Set(buffered.rows.map((group) => group.id));
        setAnimatedIds(
            new Set(
                events
                    .filter((group) => !shown.has(group.id))
                    .map((group) => group.id),
            ),
        );
        buffered.apply();
        if (expanded_id !== null) {
            reloadPage({ only: ['expanded_events'] });
        }
    };
    const { t } = useTranslation();
    const format = useFormat();
    const { url } = usePage();
    const [draft, setDraft] = useState<ActivityFilters>({
        q: filters.q,
        client: filters.client,
        check: filters.check,
        period: filters.period,
    });
    const searchTimer = useRef<number | undefined>(undefined);
    const cancelFilter = useRef<(() => void) | undefined>(undefined);
    const ownSearchResponse = useRef<string | undefined>(undefined);
    const clearSearchTimer = () => {
        window.clearTimeout(searchTimer.current);
        searchTimer.current = undefined;
    };
    useEffect(() => {
        const submittedSearch = ownSearchResponse.current;
        ownSearchResponse.current = undefined;
        // Own responses normalize whitespace, not the user's unfinished text. Other visits restore the URL filters.
        setDraft((current) => ({
            q:
                submittedSearch === filters.q && current.q.trim() === filters.q
                    ? current.q
                    : filters.q,
            client: filters.client,
            check: filters.check,
            period: filters.period,
        }));
    }, [
        filters.q,
        filters.client,
        filters.check,
        filters.period,
        filters.before,
        filters.after,
        url,
    ]);
    useEffect(() => {
        const removeStart = router.on('start', ({ detail }) => {
            if (!detail.visit.async) {
                clearSearchTimer();
                ownSearchResponse.current = undefined;
            }
        });
        const removeNavigate = router.on('navigate', clearSearchTimer);
        return () => {
            clearSearchTimer();
            removeStart();
            removeNavigate();
        };
    }, []);
    const visit = (next: ActivityFilters) => {
        clearSearchTimer();
        router.get(
            activityIndex.url(),
            Object.fromEntries(
                Object.entries({ ...next, q: next.q.trim() }).filter(
                    ([, value]) => value !== '' && value !== null,
                ),
            ),
            {
                preserveState: true,
                preserveScroll: true,
                onBeforeUpdate: () => {
                    ownSearchResponse.current = next.q.trim();
                },
                onCancelToken: (token) => {
                    cancelFilter.current = token.cancel;
                },
            },
        );
    };
    const change = (changes: Partial<ActivityFilters>, debounce = false) => {
        clearSearchTimer();
        cancelFilter.current?.();
        const next = { ...draft, ...changes };
        setDraft(next);
        if (debounce) {
            searchTimer.current = window.setTimeout(() => visit(next), 250);
        } else {
            visit(next);
        }
    };
    const submit = (event: React.SubmitEvent<HTMLFormElement>) => {
        event.preventDefault();
        visit(draft);
    };
    return (
        <PageContainer>
            <Head title={t('Activity')} />
            <PageHeader
                title={t('Activity')}
                description={t('pages.activity.description')}
            />
            <FilterBar
                status={
                    pendingChanges > 0 ? (
                        <PendingChanges
                            count={pendingChanges}
                            apply={acceptChanges}
                        />
                    ) : null
                }
                activeCount={
                    [draft.client, draft.check, draft.period !== '24h'].filter(
                        Boolean,
                    ).length
                }
                filtersActive={
                    draft.q !== '' ||
                    draft.client !== null ||
                    draft.check !== null ||
                    draft.period !== '24h'
                }
                onClearFilters={() =>
                    change({ q: '', client: null, check: null, period: '24h' })
                }
                onSubmit={submit}
                search={
                    <Input
                        type="search"
                        name="q"
                        aria-label={t('Filter by site or client')}
                        placeholder={t('Filter by site or client')}
                        value={draft.q}
                        onChange={(event) =>
                            change(
                                { q: event.target.value },
                                event.target.value !== '',
                            )
                        }
                        className="w-full"
                    />
                }
            >
                <FilterCombobox
                    ariaLabel={t('Client')}
                    options={[
                        { value: 'all', label: t('All clients') },
                        ...clientComboboxOptions(clients),
                    ]}
                    value={draft.client?.toString() ?? 'all'}
                    onValueChange={(value) =>
                        change({
                            client: value === 'all' ? null : Number(value),
                        })
                    }
                    onClear={
                        draft.client !== null
                            ? () => change({ client: null })
                            : null
                    }
                />
                <Select
                    value={draft.check ?? 'all'}
                    onValueChange={(value) => {
                        if (value !== 'all' && !isCheckKey(value)) {
                            throw new Error(`Unknown activity check: ${value}`);
                        }
                        change({ check: value === 'all' ? null : value });
                    }}
                >
                    <FilterSelectTrigger
                        aria-label={t('Check type')}
                        onClear={
                            draft.check !== null
                                ? () => change({ check: null })
                                : null
                        }
                    >
                        <SelectValue />
                    </FilterSelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">{t('All checks')}</SelectItem>
                        {Object.entries(checkTypes).map(([key, value]) => (
                            <SelectItem key={key} value={key}>
                                {t(value.label)}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <Select
                    value={draft.period}
                    onValueChange={(period) => change({ period })}
                >
                    <FilterSelectTrigger
                        aria-label={t('Time range')}
                        onClear={
                            draft.period !== '24h'
                                ? () => change({ period: '24h' })
                                : null
                        }
                    >
                        <SelectValue />
                    </FilterSelectTrigger>
                    <SelectContent>
                        {(
                            [
                                ['24h', 'Last 24 hours'],
                                ['7d', 'Last 7 days'],
                                ['30d', 'Last 30 days'],
                                ['all', 'All'],
                            ] as const
                        ).map(([value, label]) => (
                            <SelectItem key={value} value={value}>
                                {t(label)}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </FilterBar>
            {(filters.period === 'all' || retention_days < 30) && (
                <p className="text-xs text-muted-foreground">
                    {t('All activity in the last {{days}} days', {
                        days: retention_days,
                    })}
                </p>
            )}
            {buffered.rows.length === 0 ? (
                <EmptyState
                    icon={ActivityIcon}
                    title={t('No changes in the last 24 hours.')}
                    description={t(
                        'State changes of your sites and checks appear here.',
                    )}
                    filtered={{
                        active:
                            filters.q !== '' ||
                            filters.client !== null ||
                            filters.check !== null ||
                            filters.period !== '24h',
                        title: t('No changes in this range.'),
                        description: t(
                            'Clear filters to see the last 24 hours.',
                        ),
                    }}
                />
            ) : (
                <div className="@container/table overflow-hidden rounded-lg border">
                    {buffered.rows.map((group) => (
                        <ActivityFeedGroup
                            key={group.id}
                            group={group}
                            filters={filters}
                            expanded={expanded_id === group.id}
                            details={
                                expanded_id === group.id ? expanded_events : []
                            }
                            eventPage={event_page}
                            animate={animatedIds.has(group.id)}
                        />
                    ))}
                </div>
            )}
            {buffered.rows.length > 0 && (
                <ListFooter
                    count={t('activity.list.count', {
                        count: buffered.rows.length,
                        formattedCount: format.number(buffered.rows.length),
                    })}
                    previous={
                        newer_cursor !== null
                            ? {
                                  href: activityIndex.url({
                                      query: {
                                          ...filters,
                                          before: undefined,
                                          after:
                                              buffered.rows[0]?.latest.id ??
                                              newer_cursor,
                                          group: undefined,
                                          event_page: undefined,
                                      },
                                  }),
                              }
                            : null
                    }
                    next={
                        older_cursor !== null
                            ? {
                                  href: activityIndex.url({
                                      query: {
                                          ...filters,
                                          after: undefined,
                                          before:
                                              buffered.rows.at(-1)?.latest.id ??
                                              older_cursor,
                                          group: undefined,
                                          event_page: undefined,
                                      },
                                  }),
                              }
                            : null
                    }
                />
            )}
            {older_cursor === null && buffered.rows.length > 0 && (
                <p className="text-xs text-muted-foreground">
                    {t(
                        'End of activity in this range. Older changes are not shown.',
                    )}{' '}
                    <TextLink
                        variant="secondary"
                        document
                        href={exportIncidents.url({
                            query: { filter: { status: 'all', period: 'all' } },
                        })}
                    >
                        {t(
                            'Older changes: see the incident timeline or the export.',
                        )}
                    </TextLink>
                </p>
            )}
        </PageContainer>
    );
}
