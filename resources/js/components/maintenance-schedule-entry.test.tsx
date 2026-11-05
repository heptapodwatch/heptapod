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

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Head: () => null,
    Link: ({
        prefetch: _prefetch,
        ...props
    }: ComponentProps<'a'> & { prefetch?: boolean }) => <a {...props} />,
    usePage: () => ({ props: { timezone: 'UTC' } }),
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
    window.history.replaceState(null, '', '/');
});

const target = (id: number, name: string) => ({
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
) {
    return (
        <MaintenanceIndex
            windows={windows}
            next_page={null}
            previous_page={null}
            total={windows.length}
            state="all"
            site_name="API"
            can_manage={canManage}
            timezone="UTC"
            instance_timezone="UTC"
            timezones={['UTC']}
            sites={[target(7, 'Website'), target(8, 'API')]}
            clients={[]}
            tags={[]}
            filter_clients={[]}
            client_id={null}
            site_id={8}
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
