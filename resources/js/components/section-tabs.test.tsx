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

it('keeps explicitly ordered site sections stable when check payload order changes', () => {
    const overview = {
        value: 'overview',
        label: 'Overview',
        href: '/site',
        order: 0,
    };
    const uptime = {
        value: '2',
        label: 'Uptime',
        href: '/site/checks/2',
        order: 2,
    };
    const dns = { value: '9', label: 'DNS', href: '/site/checks/9', order: 9 };
    const settings = {
        value: 'settings',
        label: 'Settings',
        href: '/site/settings',
        order: Number.MAX_SAFE_INTEGER,
    };
    const { rerender } = render(
        <SectionTabs
            label="Site sections"
            value="2"
            items={[overview, dns, uptime, settings]}
        />,
    );

    expect(screen.getAllByRole('link').map((link) => link.textContent)).toEqual(
        ['Overview', 'Uptime', 'DNS', 'Settings'],
    );
    rerender(
        <SectionTabs
            label="Site sections"
            value="settings"
            items={[overview, uptime, dns, settings]}
        />,
    );
    expect(screen.getAllByRole('link').map((link) => link.textContent)).toEqual(
        ['Overview', 'Uptime', 'DNS', 'Settings'],
    );
    expect(
        screen
            .getByRole('link', { name: 'Settings' })
            .getAttribute('aria-current'),
    ).toBe('page');
});

it('keeps the decorative separator outside the active Settings link', () => {
    render(
        <SectionTabs
            label="Site sections"
            value="settings"
            items={[
                { value: 'uptime', label: 'Uptime', href: '/site/checks/1' },
                {
                    value: 'settings',
                    label: 'Settings',
                    href: '/site/settings',
                    separated: true,
                },
            ]}
        />,
    );
    const settings = screen.getByRole('link', { name: 'Settings' });
    const separator = screen.getByRole('separator', { hidden: true });
    expect(separator.closest('a')).toBeNull();
    expect(separator.parentElement).toBe(settings.parentElement);
    expect(separator.nextElementSibling).toBe(settings);
    expect(settings.getAttribute('aria-current')).toBe('page');
    expect(screen.getAllByRole('link')).toHaveLength(2);
});

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
                    separated: true,
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

it('pins a separated item (site Settings) outside the scrolling row so it is never cut off', () => {
    render(
        <SectionTabs
            label="Site sections"
            value="uptime"
            items={[
                { value: 'overview', label: 'Overview', href: '/site' },
                { value: 'uptime', label: 'Uptime', href: '/site/checks/1' },
                {
                    value: 'settings',
                    label: 'Settings',
                    href: '/site/settings',
                    separated: true,
                },
            ]}
        />,
    );
    const navigation = screen.getByRole('navigation', {
        name: 'Site sections',
    });
    const scroll = navigation.querySelector('[data-section-tabs-scroll]')!;
    const settings = screen.getByRole('link', { name: 'Settings' });
    expect(scroll.contains(settings)).toBe(false);
    expect(scroll.contains(screen.getByRole('link', { name: 'Uptime' }))).toBe(
        true,
    );
    // Keyboard order still runs through every link, pinned ones last.
    expect(screen.getAllByRole('link').map((link) => link.textContent)).toEqual(
        ['Overview', 'Uptime', 'Settings'],
    );
});
