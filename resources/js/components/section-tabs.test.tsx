import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { ComponentProps } from 'react';
import { afterEach, expect, it, vi } from 'vitest';
import { SectionTabs } from './section-tabs';

vi.mock('@inertiajs/react', () => ({
    Link: ({
        prefetch: _prefetch,
        ...props
    }: ComponentProps<'a'> & { prefetch?: boolean }) => <a {...props} />,
}));

const scrollIntoView = vi.fn();
HTMLElement.prototype.scrollIntoView = scrollIntoView;
vi.stubGlobal(
    'ResizeObserver',
    class {
        observe() {}
        disconnect() {}
    },
);
afterEach(cleanup);

it('scrolls the active navigation link into view and moves focus without navigating with arrows and Home/End', () => {
    const view = render(
        <SectionTabs
            label="Client navigation"
            value="sites"
            items={[
                { value: 'sites', label: 'Sites', href: '/clients/1' },
                {
                    value: 'incidents',
                    label: 'Incidents',
                    href: '/clients/1?tab=incidents',
                },
                {
                    value: 'settings',
                    label: 'Settings',
                    href: '/clients/1/settings',
                },
            ]}
        />,
    );
    const links = screen.getAllByRole('link');
    expect(scrollIntoView).toHaveBeenLastCalledWith({
        block: 'nearest',
        inline: 'center',
    });
    links[0]!.focus();
    fireEvent.keyDown(links[0]!, { key: 'ArrowLeft' });
    expect(document.activeElement).toBe(links[2]);
    fireEvent.keyDown(links[2]!, { key: 'Home' });
    expect(document.activeElement).toBe(links[0]);
    fireEvent.keyDown(links[0]!, { key: 'ArrowRight' });
    expect(document.activeElement).toBe(links[1]);
    fireEvent.keyDown(links[1]!, { key: 'End' });
    expect(document.activeElement).toBe(links[2]);
    view.rerender(
        <SectionTabs
            label="Client navigation"
            value="settings"
            items={[
                { value: 'sites', label: 'Sites', href: '/clients/1' },
                {
                    value: 'settings',
                    label: 'Settings',
                    href: '/clients/1/settings',
                },
            ]}
        />,
    );
    expect(
        screen
            .getByRole('link', { name: 'Settings' })
            .getAttribute('aria-current'),
    ).toBe('page');
    expect(screen.queryByRole('tab')).toBeNull();
});

it('marks the scrollable edges so a cut-off row fades instead of clipping a label', () => {
    render(
        <SectionTabs
            label="Settings"
            value="notifications"
            items={[
                {
                    value: 'members',
                    label: 'Members',
                    href: '/settings/members',
                },
                {
                    value: 'notifications',
                    label: 'Notifications',
                    href: '/settings/notifications/team',
                },
            ]}
        />,
    );
    const row = screen
        .getByRole('navigation', { name: 'Settings' })
        .querySelector<HTMLElement>('[data-section-tabs-scroll]')!;
    expect(row.hasAttribute('data-overflow-start')).toBe(false);
    expect(row.hasAttribute('data-overflow-end')).toBe(false);

    Object.defineProperty(row, 'scrollWidth', { value: 600 });
    Object.defineProperty(row, 'clientWidth', { value: 390 });
    row.scrollLeft = 105;
    fireEvent.scroll(row);
    expect(row.hasAttribute('data-overflow-start')).toBe(true);
    expect(row.hasAttribute('data-overflow-end')).toBe(true);

    row.scrollLeft = 210;
    fireEvent.scroll(row);
    expect(row.hasAttribute('data-overflow-start')).toBe(true);
    expect(row.hasAttribute('data-overflow-end')).toBe(false);
});
