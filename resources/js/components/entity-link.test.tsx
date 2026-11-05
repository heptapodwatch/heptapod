import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
} from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, it, vi } from 'vitest';
import { EntityLink } from './entity-link';

vi.mock('@inertiajs/react', () => ({
    router: { visit: vi.fn() },
    Link: ({
        href,
        children,
        prefetch: _prefetch,
        ...props
    }: {
        href: string;
        children: ReactNode;
        prefetch?: boolean;
    }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
}));

afterEach(cleanup);

/** The shared tooltip a trigger opens on keyboard focus (the replacement for a native title). */
async function tooltipOf(trigger: Element): Promise<string | null> {
    await act(async () => {
        fireEvent.focus(trigger);
    });
    const text = screen.queryByRole('tooltip')?.textContent ?? null;
    await act(async () => {
        fireEvent.blur(trigger);
    });
    return text;
}

it('keeps the avatar in the click target but outside the underlined name', () => {
    render(
        <EntityLink
            name="Demo Client 01"
            href="/clients/2"
            icon={<img alt="" data-testid="avatar" />}
        />,
    );
    const link = screen.getByRole('link');
    const name = screen.getByText('Demo Client 01');
    expect(link.contains(screen.getByTestId('avatar'))).toBe(true);
    expect(name.contains(screen.getByTestId('avatar'))).toBe(false);
    expect(name.className).toContain('underline');
});

it('drops the underline on row hover, like hovering the name', () => {
    render(<EntityLink name="Shop" href="/sites/1" />);
    expect(screen.getByText('Shop').className).toContain(
        'group-hover/row:no-underline',
    );
});

it('shows the link look without a nested link inside a linked card', () => {
    render(<EntityLink name="Shop" insideLink />);
    expect(screen.queryByRole('link')).toBeNull();
    expect(screen.getByText('Shop').className).toContain('underline');
});

it('renders an unlinked name as plain text', () => {
    render(<EntityLink name="Shop" />);
    expect(screen.queryByRole('link')).toBeNull();
    expect(screen.getByText('Shop').className).not.toContain('underline');
});

it('names the full entity in the shared tooltip while the one-line name is cut off', async () => {
    const name = 'Synthetic Lighthouse audit budget with a very long name';
    render(<EntityLink name={name} href="/sites/75" />);
    const text = screen.getByText(name);
    expect(text.hasAttribute('title')).toBe(false);
    Object.defineProperty(text, 'scrollWidth', { value: 400 });
    Object.defineProperty(text, 'clientWidth', { value: 120 });
    expect(await tooltipOf(screen.getByRole('link'))).toBe(name);
});
