import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import type { ComponentProps } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { router } from '@inertiajs/react';
import MaintenanceIndex from '@/pages/maintenance/index';

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

const target = (
    id: number,
    name: string,
    timezone: string | null = null,
    extra: Partial<Heptapod.Data.Ui.MaintenanceTargetOptionData> = {},
) => ({
    id,
    name,
    avatar: null,
    short_name: null,
    accent_color: null,
    is_internal: false,
    client_name: null,
    host: null,
    icon_url: null,
    icon_backing: 'none' as const,
    timezone,
    ...extra,
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
            next_page={null}
            previous_page={null}
            total={windows.length}
            per_page={50}
            state="all"
            site_name="API"
            can_manage={canManage}
            timezones={[
                'UTC',
                'America/New_York',
                'Asia/Tokyo',
                'Europe/Berlin',
                'Europe/London',
            ]}
            sites={[
                target(7, 'Website', 'Europe/Berlin', {
                    client_name: 'Berlin Co',
                }),
                target(8, 'API', 'America/New_York', { client_name: 'Acme' }),
            ]}
            clients={[
                target(1, 'NY Client', 'America/New_York'),
                target(2, 'Tokyo Client', 'Asia/Tokyo'),
            ]}
            tags={[target(10, 'Release', null)]}
            filter_clients={[]}
            client_id={null}
            site_id={8}
            {...overrides}
        />
    );
}

it('opens the new-window sheet for the site from the site header link and consumes the one-shot request', () => {
    window.history.replaceState(null, '', '/maintenance?site_id=8&new=1');
    render(page(true));

    const sheet = screen.getByRole('dialog', {
        name: 'New maintenance window',
    });
    // The trigger shows the site avatar (letter fallback "A") plus the name.
    expect(
        within(sheet).getByRole('combobox', { name: 'Sites' }).textContent,
    ).toContain('API');
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

it('defaults the timezone from the target, follows target changes until picked, and pins target and viewer zones', () => {
    pageContext.timezone = 'Europe/Berlin';
    window.history.replaceState(null, '', '/maintenance?site_id=8&new=1');
    render(page(true));

    const sheet = screen.getByRole('dialog', {
        name: 'New maintenance window',
    });
    const zoneTrigger = () =>
        within(sheet).getByRole('combobox', { name: 'Timezone' });

    // Preselected New York client's site starts in America/New_York.
    expect(zoneTrigger().textContent).toContain('America/New_York');

    // Opening the timezone picker lists the target zone and the viewer zone first.
    fireEvent.click(zoneTrigger());
    const options = screen
        .getAllByRole('option')
        .map((option) => option.textContent ?? '');
    expect(options[0]).toContain('America/New_York');
    expect(options[1]).toContain('Europe/Berlin');
    fireEvent.keyDown(document.activeElement ?? document.body, {
        key: 'Escape',
    });

    // Switching to Tag defaults to the viewer's zone (Europe/Berlin).
    fireEvent.click(within(sheet).getByRole('radio', { name: 'Tag' }));
    expect(zoneTrigger().textContent).toContain('Europe/Berlin');

    // Switching to Client and picking Tokyo Client follows to Asia/Tokyo.
    fireEvent.click(within(sheet).getByRole('radio', { name: 'Client' }));
    fireEvent.click(within(sheet).getByRole('combobox', { name: 'Clients' }));
    fireEvent.click(screen.getByRole('option', { name: /Tokyo Client/ }));
    expect(zoneTrigger().textContent).toContain('Asia/Tokyo');

    // Explicitly picking a timezone locks it against further target changes.
    fireEvent.click(zoneTrigger());
    fireEvent.click(screen.getByRole('option', { name: /Europe\/London/ }));
    expect(zoneTrigger().textContent).toContain('Europe/London');

    fireEvent.click(within(sheet).getByRole('combobox', { name: 'Clients' }));
    fireEvent.click(screen.getByRole('option', { name: /NY Client/ }));
    expect(zoneTrigger().textContent).toContain('Europe/London');
});

it('keeps an existing windows stored timezone when editing and switching targets', () => {
    pageContext.timezone = 'Europe/Berlin';
    window.history.replaceState(null, '', '/maintenance?site_id=8');
    render(page(true, [{ ...planned, timezone: 'UTC' }]));
    fireEvent.click(screen.getByRole('button', { name: 'Edit' }));

    const sheet = screen.getByRole('dialog', {
        name: 'Edit maintenance window',
    });
    const zoneTrigger = within(sheet).getByRole('combobox', {
        name: 'Timezone',
    });
    expect(zoneTrigger.textContent).toContain('UTC');

    fireEvent.click(within(sheet).getByRole('combobox', { name: 'Sites' }));
    fireEvent.click(screen.getByRole('option', { name: /API/ }));
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
    render(page(true, windows, { site_id: null, site_name: null }));
    expect(screen.getByText('Nov 21, 2026, 15:00')).toBeTruthy();
    expect(screen.getByText('Nov 21, 2026, 16:00')).toBeTruthy();
    expect(screen.getByText('(09:00–10:00 New York)')).toBeTruthy();
    expect(screen.getByText('Nov 21, 2026, 17:00')).toBeTruthy();
    expect(screen.getByText('Nov 21, 2026, 18:00')).toBeTruthy();
    expect(screen.queryByText(/Berlin\)/)).toBeNull();
    cleanup();

    // New York viewer: NY window has no duplicate zone hint; Berlin window shows NY time + (17:00–18:00 Berlin).
    pageContext.timezone = 'America/New_York';
    render(page(true, windows, { site_id: null, site_name: null }));
    expect(screen.getByText('Nov 21, 2026, 09:00')).toBeTruthy();
    expect(screen.getByText('Nov 21, 2026, 10:00')).toBeTruthy();
    expect(screen.queryByText(/New York\)/)).toBeNull();
    expect(screen.getByText('Nov 21, 2026, 11:00')).toBeTruthy();
    expect(screen.getByText('Nov 21, 2026, 12:00')).toBeTruthy();
    expect(screen.getByText('(17:00–18:00 Berlin)')).toBeTruthy();
});

it('keeps untouched default times strictly ordered when following a target into a DST fall-back fold', () => {
    vi.useFakeTimers();
    try {
        // 2026-11-01T04:45Z: +1h is 01:45 EDT (05:45Z), +2h is 01:45 EST (06:45Z) in America/New_York.
        vi.setSystemTime(new Date('2026-11-01T04:45:00Z'));
        pageContext.timezone = 'Europe/Berlin';
        window.history.replaceState(null, '', '/maintenance');
        render(page(true, [], { site_id: null, site_name: null }));

        fireEvent.click(
            screen.getAllByRole('button', { name: 'New window' })[0]!,
        );
        const sheet = screen.getByRole('dialog', {
            name: 'New maintenance window',
        });
        fireEvent.click(within(sheet).getByRole('combobox', { name: 'Sites' }));
        fireEvent.click(screen.getByRole('option', { name: /API/ }));

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

it('labels pinned timezone options and shows a following hint until the user overrides the zone', () => {
    pageContext.timezone = 'Europe/Berlin';
    window.history.replaceState(null, '', '/maintenance?site_id=8&new=1');
    render(
        page(true, [], {
            sites: [
                target(7, 'Website', 'Europe/Berlin', {
                    client_name: 'Berlin Co',
                }),
                target(8, 'API', 'America/New_York', { client_name: 'Acme' }),
                target(9, 'Enterprise', 'Asia/Tokyo', {
                    client_name: 'Very Long Agency Client Name GmbH',
                }),
            ],
        }),
    );

    const sheet = screen.getByRole('dialog', {
        name: 'New maintenance window',
    });
    const zoneTrigger = () =>
        within(sheet).getByRole('combobox', { name: 'Timezone' });

    // 1) Preselected NY site (client Acme): client hint visible and wired to aria-describedby.
    expect(
        within(sheet).getByText(
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
    fireEvent.keyDown(document.activeElement ?? document.body, {
        key: 'Escape',
    });

    // 2) Site whose client shares the viewer's zone: one pinned option carries both badges.
    fireEvent.click(within(sheet).getByRole('combobox', { name: 'Sites' }));
    fireEvent.click(screen.getByRole('option', { name: /Website/ }));
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
    fireEvent.keyDown(document.activeElement ?? document.body, {
        key: 'Escape',
    });

    // 3) Long client name falls back to generic "Client zone" badge while keeping the name in the hint.
    fireEvent.click(within(sheet).getByRole('combobox', { name: 'Sites' }));
    fireEvent.click(screen.getByRole('option', { name: /Enterprise/ }));
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
    fireEvent.keyDown(document.activeElement ?? document.body, {
        key: 'Escape',
    });

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
