import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
} from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, it, vi } from 'vitest';
import { TextLink, TextLinkButton } from './text-link';

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key: string) => key }),
}));
vi.mock('@inertiajs/react', () => ({
    Link: ({
        href,
        children,
        ...props
    }: {
        href: string;
        children: ReactNode;
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

it('opens external http(s) links in a new tab with rel and a screen-reader hint', () => {
    render(
        <TextLink external url href="https://partner.example.org/a?b=c">
            https://partner.example.org/a?b=c
        </TextLink>,
    );
    const link = screen.getByRole('link');
    expect(link.getAttribute('href')).toBe('https://partner.example.org/a?b=c');
    expect(link.getAttribute('target')).toBe('_blank');
    expect(link.getAttribute('rel')).toBe('noopener noreferrer');
    expect(link.textContent).toContain('(opens in a new tab)');
});

it.each([
    'javascript:void(openChat())',
    'data:text/html,<b>x</b>',
    'htp:/broken loc',
])('renders %s as plain text exactly as stored', (value) => {
    render(
        <TextLink external url href={value}>
            {value}
        </TextLink>,
    );
    expect(screen.queryByRole('link')).toBeNull();
    expect(screen.getByText(value).textContent).toBe(value);
});

it('underlines only the text, never the icon, in both variants', () => {
    for (const variant of ['primary', 'secondary'] as const) {
        const { unmount } = render(
            <TextLink
                variant={variant}
                href="/sites/1"
                icon={<img alt="" data-testid="icon" />}
            >
                Shop
            </TextLink>,
        );
        const text = screen.getByText('Shop');
        expect(text.className).toContain('underline');
        expect(text.contains(screen.getByTestId('icon'))).toBe(false);
        unmount();
    }
});

it('truncates to one line and shows the full text (or title) in the shared tooltip only while cut off', async () => {
    render(
        <>
            <TextLink variant="secondary" href="/sites/1" truncate>
                Demo Client 01 · Shop with a long name
            </TextLink>
            <TextLinkButton truncate onClick={() => undefined}>
                Show all 12 sites with a long label
            </TextLinkButton>
            <TextLink href="/sites/2" truncate title="Shop">
                Demo Client 01 · Shop
            </TextLink>
        </>,
    );
    const links = screen.getAllByRole('link');
    expect(links).toHaveLength(2);
    const link = links[0] as HTMLElement;
    const named = links[1] as HTMLElement;
    const button = screen.getByRole('button');
    for (const element of [link, button, named]) {
        expect(element.hasAttribute('title')).toBe(false);
        const text = element.querySelector('[data-link-text]')!;
        expect(text.className).toContain('truncate');
        expect(text.className).toContain('underline');
        Object.defineProperty(text, 'scrollWidth', { value: 300 });
        Object.defineProperty(text, 'clientWidth', { value: 100 });
    }
    expect(await tooltipOf(link)).toBe(
        'Demo Client 01 · Shop with a long name',
    );
    expect(await tooltipOf(button)).toBe('Show all 12 sites with a long label');
    expect(await tooltipOf(named)).toBe('Shop');
});
