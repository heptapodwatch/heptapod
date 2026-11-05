import { cleanup, render, screen, within } from '@testing-library/react';
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
        get: vi.fn(),
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

const pagination: Heptapod.Data.Ui.PaginationData = {
    current_page: 1,
    last_page: 1,
    per_page: 25,
    total: 0,
    previous_page_url: null,
    next_page_url: null,
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
    certificates_expiring_30_days: null,
    domains_expiring_30_days: null,
    scheduled_tasks: null,
    scheduled_tasks_overdue_pings: null,
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

const incidentFilters: Heptapod.Data.Ui.IncidentFilterData = {
    status: 'open',
    cause: null,
    check: null,
    client_id: null,
    started_after: null,
    started_before: null,
    period: '30d',
};
function incidents(filters: Partial<Heptapod.Data.Ui.IncidentFilterData>) {
    render(
        <IncidentsIndex
            incidents={[]}
            clients={[]}
            filters={{ ...incidentFilters, ...filters }}
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

const siteFilters: Heptapod.Data.Ui.SitesFilterData = {
    search: null,
    client_id: null,
    state: null,
    tag: null,
    sort: 'attention',
    check: null,
    attention: false,
};
function sites(
    filters: Partial<Heptapod.Data.Ui.SitesFilterData>,
    canCreate: boolean,
) {
    render(
        <SitesIndex
            sites={[]}
            clients={[]}
            tags={[]}
            summary={{ total: 0, attention: 0, paused: 0 }}
            can_create={canCreate}
            filters={{ ...siteFilters, ...filters }}
            pagination={pagination}
            tiles={tiles}
            needs_attention={[]}
            needs_attention_total={0}
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

function upcoming(changes: {
    days?: string;
    type?: string;
    client_id?: number | null;
}) {
    render(
        <UpcomingIndex
            items={[]}
            days="90"
            type="all"
            clients={[]}
            client_id={null}
            calendar_now="2026-10-21T12:00:00Z"
            {...changes}
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

const activityFilters: Heptapod.Data.Ui.ActivityFilterData = {
    q: '',
    client: null,
    check: null,
    period: '24h',
    before: null,
    after: null,
};
function activity(filters: Partial<Heptapod.Data.Ui.ActivityFilterData>) {
    render(
        <ActivityIndex
            events={[]}
            older_cursor={null}
            newer_cursor={null}
            retention_days={90}
            clients={[]}
            filters={{ ...activityFilters, ...filters }}
            expanded_id={null}
            expanded_events={[]}
            event_page={1}
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

const runFilters: Heptapod.Data.Ui.RunsFilterData = {
    client: null,
    check: null,
    type: null,
    state: null,
    before: null,
    show_same_result: false,
    period: '24h',
};
function runs(filters: Partial<Heptapod.Data.Ui.RunsFilterData>) {
    render(
        <RunsIndex
            current={[]}
            queued_more={0}
            finished={[]}
            runs={[]}
            next_cursor={null}
            clients={[]}
            filters={{ ...runFilters, ...filters }}
        />,
    );
}

it('Runs: first run names what appears here, without Clear filters', () => {
    runs({});
    expectFirstRun('No attempts in the last 24 hours.');
    // Showing repeated results only widens the history; it is not a filter.
    cleanup();
    runs({ show_same_result: true });
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

function maintenance(
    changes: Partial<Heptapod.Data.Ui.MaintenanceIndexPageData>,
) {
    render(
        <MaintenanceIndex
            windows={[]}
            next_page={null}
            previous_page={null}
            total={0}
            state="all"
            site_name={null}
            can_manage={true}
            instance_timezone="UTC"
            timezones={['UTC']}
            sites={[]}
            clients={[]}
            tags={[]}
            filter_clients={[]}
            client_id={null}
            site_id={null}
            timezone="UTC"
            {...changes}
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
