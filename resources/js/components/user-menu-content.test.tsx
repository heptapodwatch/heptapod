import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import type { User } from '@/types';

const visits = vi.hoisted(() => vi.fn());
vi.mock('@inertiajs/react', () => ({
    Link: ({ children }: { children: React.ReactNode }) => (
        <button type="button">{children}</button>
    ),
    router: { flushAll: vi.fn(), visit: visits, patch: visits, post: visits },
}));
vi.mock('react-i18next', () => {
    const t = (key: string) => key;
    return { useTranslation: () => ({ t }) };
});

const user: User = {
    id: 1,
    name: 'Alex Hollis',
    email: 'test@example.test',
    locale: 'en',
    timezone: 'UTC',
    roles: [],
    email_verified_at: null,
    created_at: '2026-10-21T12:00:00Z',
    updated_at: '2026-10-21T12:00:00Z',
};

beforeEach(() => {
    vi.resetModules();
    localStorage.clear();
    document.cookie = 'appearance=;path=/;max-age=0';
    document.documentElement.classList.remove('dark');
    window.matchMedia = vi.fn().mockReturnValue({
        matches: false,
        addEventListener: vi.fn(),
    });
    globalThis.fetch = vi.fn();
});
afterEach(cleanup);

async function openMenu() {
    const { initializeTheme } = await import('@/hooks/use-appearance');
    initializeTheme();
    const { UserMenuContent } = await import('./user-menu-content');
    const { DropdownMenu, DropdownMenuContent } =
        await import('@/components/ui/dropdown-menu');
    render(
        <DropdownMenu open>
            <DropdownMenuContent>
                <UserMenuContent user={user} />
            </DropdownMenuContent>
        </DropdownMenu>,
    );
}

it('applies the chosen theme at once and keeps it on this device across a reload, without a server request', async () => {
    await openMenu();
    expect(
        screen
            .getByRole('menuitemradio', { name: 'System' })
            .getAttribute('aria-checked'),
    ).toBe('true');

    fireEvent.click(screen.getByRole('menuitemradio', { name: 'Dark' }));

    expect(document.documentElement.classList.contains('dark')).toBe(true);
    expect(localStorage.getItem('appearance')).toBe('dark');
    expect(document.cookie).toContain('appearance=dark');
    expect(
        screen
            .getByRole('menuitemradio', { name: 'Dark' })
            .getAttribute('aria-checked'),
    ).toBe('true');
    expect(visits).not.toHaveBeenCalled();
    expect(globalThis.fetch).not.toHaveBeenCalled();

    // A reload starts from fresh modules and the stored device choice.
    cleanup();
    document.documentElement.classList.remove('dark');
    vi.resetModules();
    await openMenu();
    expect(document.documentElement.classList.contains('dark')).toBe(true);
    expect(
        screen
            .getByRole('menuitemradio', { name: 'Dark' })
            .getAttribute('aria-checked'),
    ).toBe('true');
});

it('offers identity, theme and log out, but no second Settings entry', async () => {
    await openMenu();
    expect(screen.getByText('test@example.test')).toBeTruthy();
    expect(
        screen.getAllByRole('menuitemradio').map((item) => item.textContent),
    ).toEqual(['System', 'Light', 'Dark']);
    expect(screen.getByText('Log out')).toBeTruthy();
    expect(screen.queryByText('Settings')).toBeNull();
});
