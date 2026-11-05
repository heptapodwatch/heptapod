import { cleanup, render, screen, within } from '@testing-library/react';
import type { ComponentProps } from 'react';
import { afterEach, expect, it, vi } from 'vitest';
import { AppSidebar } from '@/components/app-sidebar';
import { SidebarProvider } from '@/components/ui/sidebar';

vi.hoisted(() => {
    window.matchMedia = vi.fn().mockReturnValue({
        matches: false,
        addEventListener() {},
        removeEventListener() {},
    });
});
vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Link: ({
        prefetch: _prefetch,
        onSuccess: _onSuccess,
        ...props
    }: ComponentProps<'a'> & {
        prefetch?: boolean;
        onSuccess?: () => void;
    }) => <a {...props} />,
    usePage: () => ({
        url: '/sites',
        props: {
            timezone: 'UTC',
            auth: {
                user: null,
                canManageMembers: true,
                canManageChannels: true,
                canManageInstance: true,
                canViewSystemHealth: true,
                canViewUpdates: true,
            },
            notificationAttention: [],
        },
    }),
}));
vi.mock('react-i18next', () => {
    const t = (key: string) => key;
    return { useTranslation: () => ({ t, i18n: { language: 'en', t } }) };
});

afterEach(cleanup);

const links = (nav: HTMLElement) =>
    within(nav)
        .getAllByRole('link')
        .map((link) => link.textContent);

it('puts the daily views first and groups planning and history below them, Upcoming last', () => {
    render(
        <SidebarProvider>
            <AppSidebar onOpenSearch={vi.fn()} />
        </SidebarProvider>,
    );

    expect(links(screen.getByRole('navigation', { name: 'Main' }))).toEqual([
        'Sites',
        'Incidents',
        'Clients',
    ]);
    expect(
        links(screen.getByRole('navigation', { name: 'Planning & history' })),
    ).toEqual(['Maintenance', 'Activity', 'Upcoming']);
    expect(
        links(screen.getByRole('navigation', { name: 'Operations' })),
    ).toEqual(['Runs', 'System health', 'Updates & backups']);
});
