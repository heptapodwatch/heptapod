import { clientPagination } from '@/components/list-footer';
import { listFilter } from '@/fixtures/list-filter';
import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import type { ComponentProps } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { router } from '@inertiajs/react';
import MaintenanceIndex from '@/pages/maintenance/index';
import { stubPickerFetch } from '@/test/picker-fetch';

const pageContext = vi.hoisted(() => ({ timezone: 'Europe/Berlin' }));
vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Head: () => null,
    Link: ({
        prefetch: _prefetch,
        ...props
    }: ComponentProps<'a'> & { prefetch?: boolean }) => <a {...props} />,
    usePage: () => ({ props: { timezone: pageContext.timezone } }),
}));
vi.mock('react-i18next', () => {
    const catalog: Record<string, string> = {
        'timezone.wall_time': '({{time}} {{zone}})',
        'timezone.pinned.client': 'Client zone',
        'timezone.pinned.client_named': "{{name}}'s zone",
        'timezone.pinned.viewer': 'Your zone',
        'maintenance.timezone_hint.client_named':
            'From the client {{name}}. Pick another zone to override.',
        'maintenance.timezone_hint.client':
            'From the client zone. Pick another zone to override.',
        'maintenance.timezone_hint.viewer':
            'Your zone. Pick another zone to override.',
    };
    const t = (key: string, params?: Record<string, unknown>) => {
        let text = catalog[key] ?? key;
        if (params) {
            for (const [name, value] of Object.entries(params)) {
                text = text.replaceAll(`{{${name}}}`, String(value));
            }
        }
        return text;
    };
    return { useTranslation: () => ({ t, i18n: { language: 'en', t } }) };
});
vi.mock('@/hooks/use-mobile', () => ({ useIsMobile: () => false }));
vi.mock('@/hooks/use-live', () => ({ useLiveReload: () => vi.fn() }));
HTMLElement.prototype.scrollIntoView = vi.fn();
HTMLElement.prototype.hasPointerCapture = () => false;
let replace: ReturnType<typeof vi.spyOn>;
beforeEach(() => {
    stubTargets();
    replace = vi.spyOn(router, 'replace').mockImplementation(() => {});
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
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
    pageContext.timezone = 'Europe/Berlin';
    window.history.replaceState(null, '', '/');
});

/** Closes the open picker; its trigger gets focus back a tick later, and opening the next picker before that would close it again. */
const closePicker = async (trigger: HTMLElement) => {
    fireEvent.keyDown(document.activeElement ?? document.body, {
        key: 'Escape',
    });
    await waitFor(() => expect(document.activeElement).toBe(trigger));
};
const site = (
    id: number,
    name: string,
    timezone: string,
    client_name: string,
): Heptapod.Data.Ui.PickerSiteData => ({
    id,
    name,
    host: `${name.toLowerCase()}.example.test`,
    client_id: id + 100,
    client_name,
    timezone,
    icon_url: null,
    icon_backing: 'none',
    client_is_internal: false,
    detail: `${client_name} · ${name.toLowerCase()}.example.test`,
});
const client = (
    id: number,
    name: string,
    timezone: string,
): Heptapod.Data.Ui.ClientData => ({
    id,
    name,
    locale: 'en',
    timezone,
    timezone_override: timezone,
    short_name: null,
    accent_color: null,
    archived_at: null,
    is_internal: false,
    avatar: {
        url: null,
        backing: 'none',
        source: 'initials',
        site_id: null,
        site_host: null,
    },
    avatar_site_id: null,
});
// What the target pickers find on the server.
const stubTargets = (
    sites = [
        site(7, 'Website', 'Europe/Berlin', 'Berlin Co'),
        site(8, 'API', 'America/New_York', 'Acme'),
    ],
) =>
    stubPickerFetch({
        sites,
        clients: [
            client(1, 'NY Client', 'America/New_York'),
            client(2, 'Tokyo Client', 'Asia/Tokyo'),
        ],
    });

const planned: Heptapod.Data.Ui.MaintenanceWindowData = {
    id: 3,
    seriesId: null,
    state: 'planned',
    targetType: 'site',
    targetId: 8,
    targetLabel: 'API',
    siteCount: 1,
    name: 'Planned deploy',
    reason: null,
    startsAt: '2030-01-02T10:00:00Z',
    endsAt: '2030-01-02T11:00:00Z',
    timezone: 'UTC',
    recurrenceType: null,
    interval: 1,
    nthWeekday: null,
    dayOfWeek: null,
    muteAlerts: true,
    catchUpAtEnd: true,
    actions: ['update'],
    daysOfWeek: null,
    dayOfMonth: null,
    startTime: null,
    endTime: null,
    anchorDate: null,
    seriesTimezone: null,
};

function page(
    canManage: boolean,
    windows: Heptapod.Data.Ui.MaintenanceWindowData[] = [],
    overrides: Partial<Heptapod.Data.Ui.MaintenanceIndexPageData> = {},
) {
    return (
        <MaintenanceIndex
            windows={windows}
            windows_pagination={clientPagination(1, 50, windows.length)}
            filters={{
                state: listFilter('segmented', 'Window state', {
                    default: 'all',
                }),
                site_id: listFilter('chip', 'Site', {
                    value: '8',
                    display: 'API',
                }),
            }}
            can_manage={canManage}
            timezones={[
                'UTC',
                'America/New_York',
                'Asia/Tokyo',
                'Europe/Berlin',
                'Europe/London',
            ]}
            tags={[
                {
                    id: 10,
                    name: 'Release',
                    avatar: null,
                    short_name: null,
                    accent_color: null,
                    is_internal: false,
                },
            ]}
            window_clients={[]}
            {...overrides}
        />
    );
}

it('opens the new-window sheet for the site from the site header link and consumes the one-shot request', async () => {
    window.history.replaceState(null, '', '/maintenance?site_id=8&new=1');
    render(page(true));

    const sheet = screen.getByRole('dialog', {
        name: 'Schedule maintenance',
    });
    // The trigger shows the site avatar (letter fallback "A") plus the name.
    await waitFor(() =>
        expect(
            within(sheet).getByRole('combobox', { name: 'Sites' }).textContent,
        ).toContain('API'),
    );
    expect(replace).toHaveBeenCalledWith(
        expect.objectContaining({ url: '/maintenance?site_id=8' }),
    );
});

it('shows the site-scoped list without a sheet when no new window was requested or the user cannot manage', () => {
    window.history.replaceState(null, '', '/maintenance?site_id=8');
    render(page(true));
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(screen.getByRole('link', { name: 'API' })).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Clear filters' })).toBeTruthy();
    cleanup();

    window.history.replaceState(null, '', '/maintenance?site_id=8&new=1');
    render(page(false));
    expect(screen.queryByRole('dialog')).toBeNull();
});

it('explains why Repeats is locked when editing a window', () => {
    window.history.replaceState(null, '', '/maintenance?site_id=8');
    render(page(true, [planned]));
    fireEvent.click(screen.getByRole('button', { name: 'Edit' }));

    const repeats = screen.getByRole('radiogroup', { name: 'Repeats' });
    expect(repeats.getAttribute('aria-describedby')).toBe(
        'maintenance-repeat-locked',
    );
    expect(
        document.getElementById('maintenance-repeat-locked')?.textContent,
    ).toBe(
        "A window's repeat type cannot change; create a new window instead.",
    );
});

it('defaults the timezone from the target, follows target changes until picked, and pins target and viewer zones', async () => {
    pageContext.timezone = 'Europe/Berlin';
    window.history.replaceState(null, '', '/maintenance?site_id=8&new=1');
    render(page(true));

    const sheet = screen.getByRole('dialog', {
        name: 'Schedule maintenance',
    });
    const zoneTrigger = () =>
        within(sheet).getByRole('combobox', { name: 'Timezone' });

    // The preselected New York client's site moves the new window to America/New_York once it is named.
    await waitFor(() =>
        expect(zoneTrigger().textContent).toContain('America/New_York'),
    );

    // Opening the timezone picker lists the target zone and the viewer zone first.
    fireEvent.click(zoneTrigger());
    const options = screen
        .getAllByRole('option')
        .map((option) => option.textContent ?? '');
    expect(options[0]).toContain('America/New_York');
    expect(options[1]).toContain('Europe/Berlin');
    await closePicker(zoneTrigger());

    // Switching to Tag defaults to the viewer's zone (Europe/Berlin).
    fireEvent.click(within(sheet).getByRole('radio', { name: 'Tag' }));
    expect(zoneTrigger().textContent).toContain('Europe/Berlin');

    // Switching to Client and picking Tokyo Client follows to Asia/Tokyo.
    fireEvent.click(within(sheet).getByRole('radio', { name: 'Client' }));
    fireEvent.click(within(sheet).getByRole('combobox', { name: 'Clients' }));
    fireEvent.click(
        await screen.findByRole('option', { name: /Tokyo Client/ }),
    );
    expect(zoneTrigger().textContent).toContain('Asia/Tokyo');

    // Explicitly picking a timezone locks it against further target changes.
    fireEvent.click(zoneTrigger());
    fireEvent.click(screen.getByRole('option', { name: /Europe\/London/ }));
    expect(zoneTrigger().textContent).toContain('Europe/London');

    fireEvent.click(within(sheet).getByRole('combobox', { name: 'Clients' }));
    fireEvent.click(await screen.findByRole('option', { name: /NY Client/ }));
    expect(zoneTrigger().textContent).toContain('Europe/London');
});

it('keeps an existing windows stored timezone when editing and switching targets', async () => {
    pageContext.timezone = 'Europe/Berlin';
    window.history.replaceState(null, '', '/maintenance?site_id=8');
    render(page(true, [{ ...planned, timezone: 'UTC' }]));
    fireEvent.click(screen.getByRole('button', { name: 'Edit' }));

    const sheet = screen.getByRole('dialog', {
        name: 'Maintenance window settings',
    });
    const zoneTrigger = within(sheet).getByRole('combobox', {
        name: 'Timezone',
    });
    expect(zoneTrigger.textContent).toContain('UTC');

    fireEvent.click(within(sheet).getByRole('combobox', { name: 'Sites' }));
    fireEvent.click(await screen.findByRole('option', { name: /API/ }));
    expect(zoneTrigger.textContent).toContain('UTC');
});

it('displays the same maintenance windows in each viewers timezone with source wall time only when differing', () => {
    const windows = [
        {
            ...planned,
            id: 11,
            startsAt: '2026-11-21T14:00:00Z',
            endsAt: '2026-11-21T15:00:00Z',
            timezone: 'America/New_York',
        },
        {
            ...planned,
            id: 12,
            startsAt: '2026-11-21T16:00:00Z',
            endsAt: '2026-11-21T17:00:00Z',
            timezone: 'Europe/Berlin',
        },
    ];
    window.history.replaceState(null, '', '/maintenance');

    // Berlin viewer: NY window shows Berlin time + (09:00–10:00 New York); Berlin window has no duplicate zone hint.
    pageContext.timezone = 'Europe/Berlin';
    render(page(true, windows, { filters: {} }));
    expect(screen.getByText('Nov 21, 2026, 15:00')).toBeTruthy();
    expect(screen.getByText('Nov 21, 2026, 16:00')).toBeTruthy();
    expect(screen.getByText('(09:00–10:00 New York)')).toBeTruthy();
    expect(screen.getByText('Nov 21, 2026, 17:00')).toBeTruthy();
    expect(screen.getByText('Nov 21, 2026, 18:00')).toBeTruthy();
    expect(screen.queryByText(/Berlin\)/)).toBeNull();
    cleanup();

    // New York viewer: NY window has no duplicate zone hint; Berlin window shows NY time + (17:00–18:00 Berlin).
    pageContext.timezone = 'America/New_York';
    render(page(true, windows, { filters: {} }));
    expect(screen.getByText('Nov 21, 2026, 09:00')).toBeTruthy();
    expect(screen.getByText('Nov 21, 2026, 10:00')).toBeTruthy();
    expect(screen.queryByText(/New York\)/)).toBeNull();
    expect(screen.getByText('Nov 21, 2026, 11:00')).toBeTruthy();
    expect(screen.getByText('Nov 21, 2026, 12:00')).toBeTruthy();
    expect(screen.getByText('(17:00–18:00 Berlin)')).toBeTruthy();
});

it('keeps untouched default times strictly ordered when following a target into a DST fall-back fold', async () => {
    vi.useFakeTimers({ toFake: ['Date'] });
    try {
        // 2026-11-01T04:45Z: +1h is 01:45 EDT (05:45Z), +2h is 01:45 EST (06:45Z) in America/New_York.
        vi.setSystemTime(new Date('2026-11-01T04:45:00Z'));
        pageContext.timezone = 'Europe/Berlin';
        window.history.replaceState(null, '', '/maintenance');
        render(page(true, [], { filters: {} }));

        fireEvent.click(
            screen.getAllByRole('button', { name: 'New window' })[0]!,
        );
        const sheet = screen.getByRole('dialog', {
            name: 'Schedule maintenance',
        });
        fireEvent.click(within(sheet).getByRole('combobox', { name: 'Sites' }));
        fireEvent.click(await screen.findByRole('option', { name: /API/ }));

        const starts = within(sheet).getByLabelText(
            'Starts',
        ) as HTMLInputElement;
        const ends = within(sheet).getByLabelText('Ends') as HTMLInputElement;
        expect(starts.value).toBe('2026-11-01 01:45');
        expect(ends.value).toBe('2026-11-01 02:45');

        // Switching to Daily keeps a 1-hour wall-time window (01:45 → 02:45), not a 24-hour "Next day" window.
        fireEvent.click(within(sheet).getByRole('radio', { name: 'Daily' }));
        expect(within(sheet).queryByText('Next day')).toBeNull();
        expect(
            (within(sheet).getByLabelText('Ends') as HTMLInputElement).value,
        ).toBe('02:45');
    } finally {
        vi.useRealTimers();
    }
});

it('labels pinned timezone options and shows a following hint until the user overrides the zone', async () => {
    pageContext.timezone = 'Europe/Berlin';
    window.history.replaceState(null, '', '/maintenance?site_id=8&new=1');
    stubTargets([
        site(7, 'Website', 'Europe/Berlin', 'Berlin Co'),
        site(8, 'API', 'America/New_York', 'Acme'),
        site(
            9,
            'Enterprise',
            'Asia/Tokyo',
            'Very Long Agency Client Name GmbH',
        ),
    ]);
    render(page(true, []));

    const sheet = screen.getByRole('dialog', {
        name: 'Schedule maintenance',
    });
    const zoneTrigger = () =>
        within(sheet).getByRole('combobox', { name: 'Timezone' });

    // 1) Preselected NY site (client Acme): client hint visible and wired to aria-describedby.
    expect(
        await within(sheet).findByText(
            'From the client Acme. Pick another zone to override.',
        ),
    ).toBeTruthy();
    expect(zoneTrigger().getAttribute('aria-describedby')).toContain(
        'maintenance-timezone-hint',
    );

    // Picker shows "Acme's zone" on America/New_York and "Your zone" on Europe/Berlin.
    fireEvent.click(zoneTrigger());
    let options = screen
        .getAllByRole('option')
        .map((option) => option.textContent ?? '');
    expect(options[0]).toContain('America/New_York');
    expect(options[0]).toContain("Acme's zone");
    expect(options[1]).toContain('Europe/Berlin');
    expect(options[1]).toContain('Your zone');
    await closePicker(zoneTrigger());

    // 2) Site whose client shares the viewer's zone: one pinned option carries both badges.
    fireEvent.click(within(sheet).getByRole('combobox', { name: 'Sites' }));
    fireEvent.click(await screen.findByRole('option', { name: /Website/ }));
    expect(
        within(sheet).getByText(
            'From the client Berlin Co. Pick another zone to override.',
        ),
    ).toBeTruthy();
    fireEvent.click(zoneTrigger());
    options = screen
        .getAllByRole('option')
        .map((option) => option.textContent ?? '');
    expect(options[0]).toContain('Europe/Berlin');
    expect(options[0]).toContain("Berlin Co's zone");
    expect(options[0]).toContain('Your zone');
    expect(
        options.filter((text) => text.includes('Europe/Berlin')),
    ).toHaveLength(1);
    await closePicker(zoneTrigger());

    // 3) Long client name falls back to generic "Client zone" badge while keeping the name in the hint.
    fireEvent.click(within(sheet).getByRole('combobox', { name: 'Sites' }));
    fireEvent.click(await screen.findByRole('option', { name: /Enterprise/ }));
    expect(
        within(sheet).getByText(
            'From the client Very Long Agency Client Name GmbH. Pick another zone to override.',
        ),
    ).toBeTruthy();
    fireEvent.click(zoneTrigger());
    options = screen
        .getAllByRole('option')
        .map((option) => option.textContent ?? '');
    expect(options[0]).toContain('Asia/Tokyo');
    expect(options[0]).toContain('Client zone');
    await closePicker(zoneTrigger());

    // 4) Tag window shows "Your zone" hint and only pins viewer zone.
    fireEvent.click(within(sheet).getByRole('radio', { name: 'Tag' }));
    expect(
        within(sheet).getByText('Your zone. Pick another zone to override.'),
    ).toBeTruthy();

    // 5) Explicitly picking a zone hides the hint and clears aria-describedby.
    fireEvent.click(zoneTrigger());
    fireEvent.click(screen.getByRole('option', { name: /Europe\/London/ }));
    expect(
        within(sheet).queryByText(/Pick another zone to override/),
    ).toBeNull();
    expect(zoneTrigger().getAttribute('aria-describedby')).toBeNull();
});
