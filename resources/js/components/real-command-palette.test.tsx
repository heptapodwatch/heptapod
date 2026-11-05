import { router } from '@inertiajs/react';
import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
} from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { RealCommandPalette } from '@/components/real-command-palette';
import { navigation } from '@/lib/navigation';

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    usePage: () => ({ props: { timezone: 'UTC', locale: 'en' } }),
}));
vi.mock('react-i18next', () => {
    const t = (key: string) => key;
    return { useTranslation: () => ({ t, i18n: { language: 'en', t } }) };
});
HTMLElement.prototype.scrollIntoView = vi.fn();
let visit: ReturnType<typeof vi.spyOn>;
let post: ReturnType<typeof vi.spyOn>;
const onShowShortcuts = vi.fn();

beforeEach(() => {
    visit = vi.spyOn(router, 'visit').mockImplementation(() => {});
    post = vi.spyOn(router, 'post').mockImplementation(() => {});
    vi.stubGlobal(
        'ResizeObserver',
        class {
            observe() {}
            unobserve() {}
            disconnect() {}
        },
    );
    vi.stubGlobal(
        'fetch',
        vi.fn(async () =>
            Response.json({ sites: [], clients: [], incidents: [] }),
        ),
    );
    window.matchMedia = vi.fn().mockReturnValue({
        matches: false,
        addEventListener: vi.fn(),
    });
    localStorage.clear();
    onShowShortcuts.mockClear();
});
afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
    document.documentElement.classList.remove('dark');
});

function palette() {
    render(
        <RealCommandPalette
            open
            onOpenChange={vi.fn()}
            onOpen={vi.fn()}
            onCloseAutoFocus={vi.fn()}
            destinations={navigation}
            onShowShortcuts={onShowShortcuts}
        />,
    );
}

const option = (name: string) => screen.getByRole('option', { name });

it('offers every destination once and navigates to it', () => {
    palette();
    const hrefs = new Set(navigation.map((entry) => entry.href));
    const goTo = screen.getByText('Go to', {
        selector: '[cmdk-group-heading]',
    }).parentElement!;
    expect(goTo.querySelectorAll('[cmdk-item]')).toHaveLength(hrefs.size);
    fireEvent.click(option('Notifications'));
    expect(visit).toHaveBeenCalledWith('/settings/notifications/team');
});

it('switches the device theme, opens the shortcut sheet and logs out from the palette', async () => {
    palette();
    fireEvent.click(option('Theme: Dark'));
    expect(document.documentElement.classList.contains('dark')).toBe(true);
    expect(localStorage.getItem('appearance')).toBe('dark');

    fireEvent.click(screen.getByRole('option', { name: /Keyboard shortcuts/ }));
    expect(onShowShortcuts).toHaveBeenCalledTimes(1);

    fireEvent.click(option('Log out'));
    expect(post).toHaveBeenCalledWith('/logout');
    await act(async () => {});
});
