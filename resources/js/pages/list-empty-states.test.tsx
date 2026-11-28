import { listFilter } from '@/fixtures/list-filter';
import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import type { ComponentProps, ReactNode } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import ActivityIndex from '@/pages/activity/index';
import IncidentsIndex from '@/pages/incidents/index';
import MaintenanceIndex from '@/pages/maintenance/index';
import RunsIndex from '@/pages/runs/index';
import SitesIndex from '@/pages/sites/index';
import UpcomingIndex from '@/pages/upcoming/index';

// Every list page with a filter bar picks its EmptyState variant from its own filters (heptapod-ui-rules Lists):
// first-run copy (plus the create action for managers) by default, "No matching …" while a filter differs from its default.
const routerGet = vi.hoisted(() => vi.fn());
const routerCancelAll = vi.hoisted(() => vi.fn());
vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Head: () => null,
    Link: ({
        prefetch: _prefetch,
        ...props
    }: ComponentProps<'a'> & { prefetch?: boolean }) => <a {...props} />,
    Deferred: ({ children }: { children: ReactNode }) => <>{children}</>,
    usePage: () => ({
        url: '/',
        // First-load deferred groups whose props have not arrived yet.
        deferredProps: {
            sidebar: ['lateRunsCount'],
            history: ['sites_history'],
        },
        props: {
            timezone: 'UTC',
            overallHealth: {
                status: 'ok',
                active_checks: 0,
                notifications_covered: true,
                causes: [],
            },
        },
    }),
    router: {
        on: () => () => {},
        get: routerGet,
        cancelAll: routerCancelAll,
        visit: vi.fn(),
        reload: vi.fn(),
        replace: vi.fn(),
    },
}));
vi.mock('react-i18next', () => {
    const t = (key: string) => key;
    return { useTranslation: () => ({ t, i18n: { language: 'en', t } }) };
});
vi.mock('@/hooks/use-mobile', () => ({ useIsMobile: () => false }));
vi.mock('@/hooks/use-live', () => ({
    useLiveReload: () => vi.fn(),
    useLive: () => undefined,
}));
vi.mock('@laravel/echo-react', () => ({
    useConnectionStatus: () => 'connected',
}));
// The Sites headline band is covered by sites-attention-band.test.tsx; here only the list below it matters.
vi.mock('@/components/sites-attention-band', () => ({
    SitesAttentionBand: () => null,
}));

beforeEach(() => {
    vi.stubGlobal(
        'ResizeObserver',
        class {
            observe() {}
            unobserve() {}
            disconnect() {}
        },
    );
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

const pagination: Heptapod.Contracts.PaginationData = {
    current_page: 1,
    last_page: 1,
    per_page: 25,
    total: 0,
    previous_page_url: null,
    next_page_url: null,
    page_name: 'page',
};
const tiles: Heptapod.Data.Ui.DashboardTilesData = {
    sites: 0,
    ok: 0,
    down: 0,
    check_errors: 0,
    warning: 0,
    pending: 0,
    paused: 0,
    open_incidents: 0,
    uptime: null,
    certificates_expiring: 0,
    cron_jobs_failing: 0,
    failed: 0,
    maintenance: 0,
    disabled: 0,
    unavailable: 0,
};

/** The list's EmptyState (Sites also names "No sites monitored yet" in its header description). */
function emptyState(title: string): HTMLElement {
    const [match] = screen
        .getAllByText(title)
        .flatMap(
            (node) => node.closest<HTMLElement>('[data-slot="empty"]') ?? [],
        );
    if (!match) throw new Error(`No EmptyState titled ${title}`);
    return match;
}

function expectFirstRun(title: string) {
    expect(within(emptyState(title)).getByText(title)).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Clear filters' })).toBeNull();
}

function expectFiltered(title: string) {
    const empty = emptyState(title);
    // One Clear filters per screen, in the filter bar; the empty state only points to it.
    const [clear, ...others] = screen.getAllByRole('button', {
        name: 'Clear filters',
    });
    expect(others).toHaveLength(0);
    expect(empty.contains(clear!)).toBe(false);
    expect(within(empty).queryByRole('button')).toBeNull();
}

function incidents(values: {
    status?: string;
    cause?: string;
    started_after?: string;
    period?: string;
}) {
    const filters = {
        status: listFilter('segmented', 'Status', {
            default: 'open',
            value: values.status ?? 'open',
        }),
        cause: listFilter('chip', 'Cause', { value: values.cause ?? null }),
        started_after: listFilter('date', 'Started on or after', {
            value: values.started_after ?? null,
        }),
        period: listFilter('select', 'Time range', {
            default: '30d',
            value: values.period ?? '30d',
        }),
    };
    render(
        <IncidentsIndex
            incidents={[]}
            clients={[]}
            filters={filters}
            pagination={pagination}
            summary={{
                count: 0,
                total_duration_seconds: 0,
                median_duration_seconds: null,
            }}
        />,
    );
}

it('Incidents: the default Open view says what opens an incident, without Clear filters', () => {
    incidents({});
    expectFirstRun('No open incidents');
});

it.each([
    ['a cause from the URL', { cause: 'failed' as const }],
    ['a start date from the URL', { started_after: '2026-10-01' }],
    ['the all-time period', { period: 'all' }],
    ['the All status', { status: 'all' }],
    ['the Resolved status', { status: 'resolved' }],
])(
    'Incidents: %s shows the filtered state with Clear filters in the toolbar',
    (_, filters) => {
        incidents(filters);
        expectFiltered('No matching incidents');
    },
);

function sites(
    filters: { search?: string; tag?: string; attention?: boolean },
    canCreate: boolean,
    tags: Heptapod.Data.Ui.TagData[] = [],
) {
    render(
        <SitesIndex
            sites={[]}
            clients={[]}
            tags={tags}
            summary={{ total: 0, attention: 0, paused: 0, clients: 0 }}
            can_create={canCreate}
            sort="attention"
            filters={{
                search: listFilter('search', 'Search sites', {
                    value: filters.search ?? null,
                }),
                tag: listFilter('combobox', 'Tag', {
                    value: filters.tag ?? null,
                    prefix: 'tag:',
                    empty_label: 'All tags',
                    option_source: 'tags',
                }),
                attention: listFilter('chip', 'Needs attention', {
                    value: filters.attention ? '1' : null,
                }),
            }}
            pagination={pagination}
            tiles={tiles}
            needs_attention={[]}
            needs_attention_total={0}
            advisory_packages={[]}
            more_advisory_packages={false}
        />,
    );
}

it('Sites: first run offers Add site to a manager and nothing to a Member', () => {
    sites({}, true);
    expectFirstRun('No sites monitored yet');
    // The header action and the empty-state action.
    expect(screen.getAllByRole('link', { name: 'Add site' })).toHaveLength(2);
    cleanup();

    sites({}, false);
    expectFirstRun('No sites monitored yet');
    expect(screen.queryByRole('link', { name: 'Add site' })).toBeNull();
});

it.each([
    ['a search', { search: 'api' }],
    ['the attention segment', { attention: true }],
    ['a tag', { tag: 'prod' }],
])(
    'Sites: %s shows the filtered state with Clear filters in the toolbar',
    (_, filters) => {
        sites(filters, true);
        expectFiltered('No matching sites');
    },
);

it('Sites: a filter change reloads the rows with their history and replaces first-load deferred requests still in flight', () => {
    sites({ tag: 'prod' }, true);
    fireEvent.click(screen.getByRole('button', { name: 'Clear filters' }));
    // A superseded deferred response would overwrite the new rows and URL; its props come with this visit instead.
    expect(routerCancelAll).toHaveBeenCalledWith({
        prefetch: false,
        sync: false,
    });
    expect(routerGet).toHaveBeenLastCalledWith(
        expect.any(String),
        expect.anything(),
        expect.objectContaining({
            only: expect.arrayContaining([
                'sites',
                'sites_history',
                'lateRunsCount',
            ]),
        }),
    );
});

it('Sites: filters by a tag named "all" without colliding with the "All tags" sentinel', () => {
    Element.prototype.scrollIntoView = vi.fn();
    const allTag: Heptapod.Data.Ui.TagData = { id: 1, name: 'all' };
    sites({}, true, [allTag]);

    fireEvent.click(screen.getByRole('combobox', { name: 'Tag' }));
    fireEvent.click(screen.getByRole('option', { name: 'all' }));
    expect(routerGet).toHaveBeenLastCalledWith(
        expect.any(String),
        expect.objectContaining({ filter: { tag: 'all' } }),
        expect.anything(),
    );

    cleanup();
    sites({ tag: 'all' }, true, [allTag]);
    const trigger = screen.getByRole('combobox', { name: 'Tag' });
    expect(trigger.textContent).toBe('all');

    fireEvent.click(trigger);
    fireEvent.click(screen.getByRole('option', { name: 'All tags' }));
    expect(routerGet).toHaveBeenLastCalledWith(
        expect.any(String),
        expect.objectContaining({ filter: {} }),
        expect.anything(),
    );
});

function upcoming(changes: {
    days?: string;
    type?: string;
    client_id?: number | null;
}) {
    render(
        <UpcomingIndex
            items={[]}
            next_page_url={null}
            previous_page_url={null}
            per_page={50}
            clients={[]}
            filters={{
                days: listFilter('segmented', 'Time range', {
                    default: '90',
                    value: changes.days ?? '90',
                }),
                type: listFilter('select', 'Type', {
                    default: 'all',
                    value: changes.type ?? 'all',
                }),
                client_id: listFilter('combobox', 'Client', {
                    value:
                        changes.client_id == null
                            ? null
                            : String(changes.client_id),
                }),
            }}
            calendar_now="2026-10-21T12:00:00Z"
        />,
    );
}

it('Upcoming: first run names what appears here, without Clear filters', () => {
    upcoming({});
    expectFirstRun('Nothing scheduled in the next 90 days.');
});

it.each([
    ['the 30-day range', { days: '30' }],
    ['a type', { type: 'domain' }],
    ['a client', { client_id: 4 }],
])(
    'Upcoming: %s shows the filtered state with Clear filters in the toolbar',
    (_, changes) => {
        upcoming(changes);
        expectFiltered('Nothing scheduled in this range.');
    },
);

function activity(values: { q?: string; check?: string; period?: string }) {
    render(
        <ActivityIndex
            events={{ data: [] }}
            head={undefined}
            retention_days={90}
            filters={{
                q: listFilter('search', 'Filter by site or client', {
                    value: values.q ?? null,
                }),
                check: listFilter('select', 'Check type', {
                    value: values.check ?? null,
                }),
                period: listFilter('select', 'Time range', {
                    default: '24h',
                    value: values.period ?? '24h',
                }),
            }}
            expanded_id={null}
            expanded_events={[]}
            expanded_pagination={null}
        />,
    );
}

it('Activity: first run names what appears here, without Clear filters', () => {
    activity({});
    expectFirstRun('No changes in the last 24 hours.');
});

it.each([
    ['a search', { q: 'api' }],
    ['a check', { check: 'uptime' as const }],
    ['the 7-day period', { period: '7d' }],
])(
    'Activity: %s shows the filtered state with Clear filters in the toolbar',
    (_, filters) => {
        activity(filters);
        expectFiltered('No changes in this range.');
    },
);

function runs(values: Record<string, string | null>) {
    render(
        <RunsIndex
            current={[]}
            queued_more={0}
            finished={[]}
            runs={[]}
            next_cursor={null}
            filters={{
                type: listFilter('select', 'Check type', {
                    value: values.type ?? null,
                }),
                state: listFilter('select', 'State', {
                    value: values.state ?? null,
                }),
                period: listFilter('segmented', 'History time range', {
                    value: values.period ?? '24h',
                    default: '24h',
                    slot: 'history-range',
                }),
                show_same_result: listFilter(
                    'boolean',
                    'Show runs with the same result as before',
                    {
                        value: values.show_same_result ?? null,
                        slot: 'history',
                        widens: true,
                    },
                ),
            }}
            before={null}
            per_page={50}
            attempt_retention_days={7}
        />,
    );
}

it('Runs: first run names what appears here, without Clear filters', () => {
    runs({});
    expectFirstRun('No attempts in the last 24 hours.');
    // Showing repeated results only widens the history; it is not a filter.
    cleanup();
    runs({ show_same_result: '1' });
    expectFirstRun('No attempts in the last 24 hours.');
});

it.each([
    ['a check type', { type: 'uptime' as const }],
    ['a run state', { state: 'done' as const }],
    ['the 7-day history range', { period: '7d' as const }],
])(
    'Runs: %s shows the filtered state with Clear filters in the toolbar',
    (_, filters) => {
        runs(filters);
        expectFiltered('runs.screen.noHistory');
    },
);

function maintenance(changes: {
    state?: string;
    client_id?: number;
    site_id?: number;
    site_name?: string;
    can_manage?: boolean;
}) {
    render(
        <MaintenanceIndex
            windows={[]}
            next_page={null}
            previous_page={null}
            total={0}
            per_page={50}
            filters={{
                state: listFilter('segmented', 'Window state', {
                    default: 'all',
                    value: changes.state ?? 'all',
                }),
                client_id: listFilter('combobox', 'Client', {
                    value:
                        changes.client_id === undefined
                            ? null
                            : String(changes.client_id),
                }),
                site_id: listFilter('chip', 'Site', {
                    value:
                        changes.site_id === undefined
                            ? null
                            : String(changes.site_id),
                    display: changes.site_name ?? null,
                }),
            }}
            can_manage={changes.can_manage ?? true}
            timezones={['UTC']}
            tags={[]}
            window_clients={[]}
        />,
    );
}

it('Maintenance: first run offers New window to a manager and nothing to a Member', () => {
    maintenance({});
    expectFirstRun('No maintenance windows yet.');
    expect(screen.getAllByRole('button', { name: 'New window' })).toHaveLength(
        2,
    );
    cleanup();

    maintenance({ can_manage: false });
    expectFirstRun('No maintenance windows yet.');
    expect(screen.queryByRole('button', { name: 'New window' })).toBeNull();
});

it.each([
    ['a window state', { state: 'planned' }],
    ['a client', { client_id: 4 }],
    ['a site scope', { site_id: 8, site_name: 'API' }],
])(
    'Maintenance: %s shows the filtered state with Clear filters in the toolbar',
    (_, changes) => {
        maintenance(changes);
        expectFiltered('No matching maintenance windows.');
    },
);
