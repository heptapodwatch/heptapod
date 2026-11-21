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
    const t = (key: string) => key;
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

const target = (id: number, name: string, timezone: string | null = null) => ({
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
                target(7, 'Website', 'Europe/Berlin'),
                target(8, 'API', 'America/New_York'),
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
