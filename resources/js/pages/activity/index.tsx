import { ActivityTimeline } from '@/components/activity-timeline';
import { Activity as ActivityIcon } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import {
    FilterBar,
    FilterCombobox,
    FilterSelectTrigger,
} from '@/components/filter-bar';
import { clientComboboxOptions } from '@/components/entity-combobox';
import { PageHeader } from '@/components/page-header';
import { PageContainer } from '@/components/page-container';
import { Head, InfiniteScroll, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectValue,
} from '@/components/ui/select';
import { PendingChanges } from '@/components/pending-changes';
import { useLiveReload } from '@/hooks/use-live';
import { checkTypes } from '@/lib/check-types';
import { index as activityIndex } from '@/routes/activity';
import { exportMethod as exportIncidents } from '@/routes/incidents';
import { TextLink } from '@/components/text-link';

type ActivityFilters = Heptapod.Data.Ui.ActivityFilterData;

/** Batches load automatically while scrolling this many times, then "Load older" takes over so the footer stays reachable. */
const AUTOMATIC_BATCHES = 3;

/** Groups per batch; ActivityController::BatchSize. */
const ACTIVITY_BATCH = 50;

function isCheckKey(value: string): value is Heptapod.Contracts.CheckKey {
    return Object.hasOwn(checkTypes, value);
}

export default function Activity({
    events,
    head,
    retention_days,
    clients,
    filters,
    expanded_id,
    expanded_events,
    expanded_pagination,
}: Heptapod.Data.Ui.ActivityPageData) {
    // Live changes reload only the newest batch (`head`); the shown timeline never changes underneath the reader.
    useLiveReload(['state.changed'], ['head'], undefined, true, {
        pollFallback: false,
    });
    const groups = events.data;
    // The element that last took focus; removing the focused load button moves focus to <body> without a focusin.
    const lastFocused = useRef<Element | null>(null);
    useEffect(() => {
        const track = (event: FocusEvent) => {
            lastFocused.current = event.target as Element;
        };
        document.addEventListener('focusin', track);
        return () => document.removeEventListener('focusin', track);
    }, []);
    const shownGroups = new Map(
        groups.map((group) => [group.id, JSON.stringify(group)]),
    );
    const incoming = head?.data ?? [];
    const incomingIds = new Set(incoming.map((group) => group.id));
    // The window the newest batch covers: all of the range when it is not full, else down to its oldest row. A shown
    // row older than that was only pushed out by new arrivals; a shown row inside it but missing was removed.
    const windowStart =
        incoming.length < ACTIVITY_BATCH
            ? -Infinity
            : Date.parse(incoming.at(-1)!.ended_at);
    // New, changed or removed groups at the top. A new filter is a full visit without `head`, so the count starts over.
    const pendingChanges =
        head === undefined
            ? 0
            : incoming.filter(
                  (group) =>
                      shownGroups.get(group.id) !== JSON.stringify(group),
              ).length +
              groups.filter(
                  (group) =>
                      !incomingIds.has(group.id) &&
                      Date.parse(group.ended_at) >= windowStart,
              ).length;
    // Applying starts the timeline over from the newest batch, so the loaded batches always join without a gap. An
    // older batch still loading would append onto the fresh one from its stale cursor, so it is cancelled first.
    const acceptChanges = () => {
        router.cancelAll({ prefetch: false, sync: false });
        router.reload({
            only: [
                'events',
                'head',
                ...(expanded_id !== null ? ['expanded_events'] : []),
            ],
            reset: ['events'],
            onSuccess: () => window.scrollTo({ top: 0 }),
        });
    };
    const { t } = useTranslation();
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
    }, [filters.q, filters.client, filters.check, filters.period, url]);
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
                // New filters start the timeline over instead of appending to the loaded batches.
                reset: ['events'],
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
            {groups.length === 0 ? (
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
                // Batches of 50 load while scrolling, then on "Load older"; the URL keeps no cursor. One wrapper keeps
                // InfiniteScroll's sentinels out of the page's flex gap.
                <div>
                    <InfiniteScroll
                        data="events"
                        onlyNext
                        preserveUrl
                        manualAfter={AUTOMATIC_BATCHES}
                        loading={({ manualMode }) =>
                            manualMode ? null : (
                                <p className="flex items-center justify-center gap-2 pt-4 text-xs text-muted-foreground">
                                    <Spinner />
                                    {t('Loading older changes…')}
                                </p>
                            )
                        }
                        next={({ fetch, loading, hasMore, manualMode }) =>
                            hasMore ? (
                                // The button stays mounted while it loads, so keyboard focus stays on it.
                                manualMode ? (
                                    <div className="flex justify-center pt-4">
                                        <Button
                                            variant="outline"
                                            data-load-older
                                            aria-disabled={loading || undefined}
                                            onClick={() => {
                                                if (!loading) fetch();
                                            }}
                                        >
                                            {loading && <Spinner />}
                                            {t(
                                                loading
                                                    ? 'Loading older changes…'
                                                    : 'Load older changes',
                                            )}
                                        </Button>
                                    </div>
                                ) : null
                            ) : (
                                <p
                                    tabIndex={-1}
                                    ref={(element) => {
                                        // The load button that just disappeared had focus, which fell to <body>; a
                                        // reader who moved on (search, a link) keeps their place.
                                        if (
                                            element &&
                                            lastFocused.current?.hasAttribute(
                                                'data-load-older',
                                            ) &&
                                            document.activeElement ===
                                                document.body
                                        )
                                            element.focus();
                                    }}
                                    className="pt-4 text-xs text-muted-foreground"
                                >
                                    {t(
                                        'End of activity in this range. Older changes are not shown.',
                                    )}{' '}
                                    <TextLink
                                        variant="secondary"
                                        document
                                        href={exportIncidents.url('csv', {
                                            query: {
                                                filter: {
                                                    status: 'all',
                                                    period: 'all',
                                                },
                                            },
                                        })}
                                    >
                                        {t(
                                            'Older changes: see the incident timeline or the export.',
                                        )}
                                    </TextLink>
                                </p>
                            )
                        }
                    >
                        <ActivityTimeline
                            groups={groups}
                            filters={filters}
                            expandedId={expanded_id}
                            expandedEvents={expanded_events}
                            expandedPagination={expanded_pagination}
                        />
                    </InfiniteScroll>
                </div>
            )}
        </PageContainer>
    );
}
