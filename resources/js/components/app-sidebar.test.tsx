import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import { useState, type ComponentProps } from 'react';
import { afterEach, expect, it, vi } from 'vitest';
import { AppSidebar } from '@/components/app-sidebar';
import { KeyboardShortcutsDialog } from '@/components/keyboard-shortcuts-dialog';
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
            <AppSidebar onOpenSearch={vi.fn()} onOpenShortcuts={vi.fn()} />
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

function SidebarWithShortcuts() {
    const [open, setOpen] = useState(false);
    return (
        <SidebarProvider>
            <AppSidebar
                onOpenSearch={vi.fn()}
                onOpenShortcuts={() => setOpen(true)}
            />
            <KeyboardShortcutsDialog
                open={open}
                onOpenChange={setOpen}
                shortcuts={[]}
            />
        </SidebarProvider>
    );
}

it('opens the keyboard shortcuts dialog from the help button in the logo row', () => {
    render(<SidebarWithShortcuts />);

    fireEvent.click(screen.getByRole('button', { name: 'Keyboard shortcuts' }));

    expect(
        screen.getByRole('dialog', { name: 'Keyboard shortcuts' }),
    ).toBeTruthy();
});

it('collapses and expands via the visible toggle button, persists sidebar_state, and omits the border rail', () => {
    document.cookie = 'sidebar_state=; max-age=0; path=/';
    const { container } = render(
        <SidebarProvider defaultOpen>
            <AppSidebar onOpenSearch={vi.fn()} onOpenShortcuts={vi.fn()} />
        </SidebarProvider>,
    );

    const sidebar = container.querySelector('[data-slot="sidebar"]');
    expect(sidebar?.getAttribute('data-state')).toBe('expanded');
    expect(container.querySelector('[data-slot="sidebar-rail"]')).toBeNull();

    fireEvent.click(screen.getByRole('button', { name: 'Collapse sidebar' }));

    expect(sidebar?.getAttribute('data-state')).toBe('collapsed');
    expect(document.cookie).toContain('sidebar_state=false');
    expect(screen.getByRole('button', { name: 'Expand sidebar' })).toBeTruthy();

    fireEvent.click(screen.getByRole('button', { name: 'Expand sidebar' }));

    expect(sidebar?.getAttribute('data-state')).toBe('expanded');
    expect(document.cookie).toContain('sidebar_state=true');
});

it('starts collapsed when defaultOpen is false and toggles with Cmd/Ctrl+B', () => {
    document.cookie = 'sidebar_state=; max-age=0; path=/';
    const { container } = render(
        <SidebarProvider defaultOpen={false}>
            <AppSidebar onOpenSearch={vi.fn()} onOpenShortcuts={vi.fn()} />
        </SidebarProvider>,
    );

    const sidebar = container.querySelector('[data-slot="sidebar"]');
    expect(sidebar?.getAttribute('data-state')).toBe('collapsed');
    expect(screen.getByRole('button', { name: 'Expand sidebar' })).toBeTruthy();

    fireEvent.keyDown(window, { key: 'b', metaKey: true });

    expect(sidebar?.getAttribute('data-state')).toBe('expanded');
    expect(document.cookie).toContain('sidebar_state=true');

    fireEvent.keyDown(window, { key: 'b', ctrlKey: true });

    expect(sidebar?.getAttribute('data-state')).toBe('collapsed');
    expect(document.cookie).toContain('sidebar_state=false');
});
