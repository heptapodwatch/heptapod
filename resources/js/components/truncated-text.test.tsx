import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
} from '@testing-library/react';
import { afterEach, expect, it } from 'vitest';
import { TooltipHint, TruncatedText } from './truncated-text';
import { ListRow } from './ui/table';

afterEach(cleanup);

/** jsdom has no layout: give the element the box a browser would measure. */
function measure(element: Element, scrollWidth: number, clientWidth: number) {
    Object.defineProperty(element, 'scrollWidth', { value: scrollWidth });
    Object.defineProperty(element, 'clientWidth', { value: clientWidth });
}

async function focus(element: Element) {
    await act(async () => {
        fireEvent.focus(element);
    });
}

it('shows the full text in the shared tooltip while the text is cut off', async () => {
    render(
        <TruncatedText content="Kestrel Mobility · Booking engine">
            <a href="/sites/1" className="truncate">
                Kestrel Mobility · Booking engine
            </a>
        </TruncatedText>,
    );
    const link = screen.getByRole('link');
    measure(link, 320, 120);
    await focus(link);

    expect(screen.getByRole('tooltip').textContent).toBe(
        'Kestrel Mobility · Booking engine',
    );
    expect(link.hasAttribute('title')).toBe(false);
});

it('shows no tooltip when the text fits', async () => {
    render(
        <TruncatedText content="Shop">
            <a href="/sites/2" className="truncate">
                Shop
            </a>
        </TruncatedText>,
    );
    const link = screen.getByRole('link');
    measure(link, 40, 120);
    await focus(link);

    expect(screen.queryByRole('tooltip')).toBeNull();
});

it('measures a marked descendant, such as the text span inside a link', async () => {
    render(
        <TruncatedText content="https://booking.calloway-hotels.example/reservations">
            <a href="/sites/3">
                <span data-truncate className="truncate">
                    https://booking.calloway-hotels.example/reservations
                </span>
            </a>
        </TruncatedText>,
    );
    const link = screen.getByRole('link');
    measure(link, 200, 200);
    measure(link.querySelector('[data-truncate]')!, 380, 200);
    await focus(link);

    expect(screen.getByRole('tooltip').textContent).toBe(
        'https://booking.calloway-hotels.example/reservations',
    );
});

it('always shows a hint, and renders the child alone without content', async () => {
    render(
        <>
            <TooltipHint content="Sunday, October 25, 2026 at 18:25:14">
                <button type="button">Oct 25, 18:25</button>
            </TooltipHint>
            <TooltipHint content={undefined}>
                <span>No hint</span>
            </TooltipHint>
        </>,
    );
    await focus(screen.getByRole('button'));

    expect(screen.getByRole('tooltip').textContent).toBe(
        'Sunday, October 25, 2026 at 18:25:14',
    );
    expect(screen.getByText('No hint').hasAttribute('data-state')).toBe(false);
});

it('makes a standalone value one keyboard stop that shows the exact value, with the value as screen-reader text', async () => {
    render(
        <TooltipHint content="Tuesday, October 27, 2026 at 22:22:35">
            <time dateTime="2026-10-27T22:22:35Z">22:22</time>
        </TooltipHint>,
    );
    const time = screen.getByText('22:22');

    expect(time.tabIndex).toBe(0);
    expect(time.hasAttribute('data-hint-focus')).toBe(true);
    expect(time.parentElement?.textContent).toBe(
        '22:22 (Tuesday, October 27, 2026 at 22:22:35)',
    );
    await focus(time);
    expect(screen.getByRole('tooltip').textContent).toBe(
        'Tuesday, October 27, 2026 at 22:22:35',
    );
});

it('adds no tab stop to standalone text that fits', () => {
    render(
        <TruncatedText content="Shop">
            <span className="truncate">Shop</span>
        </TruncatedText>,
    );

    expect(screen.getByText('Shop').hasAttribute('tabindex')).toBe(false);
});

it('makes a cut-off value in a read-only list row (no link or button) one keyboard stop with the full value', async () => {
    // The import result rows carry no link or button that could host the row's combined tooltip.
    Object.defineProperty(HTMLElement.prototype, 'scrollWidth', {
        configurable: true,
        get(this: HTMLElement) {
            return this.classList.contains('truncate') ? 756 : 0;
        },
    });
    Object.defineProperty(HTMLElement.prototype, 'clientWidth', {
        configurable: true,
        get: () => 256,
    });
    try {
        render(
            <ListRow>
                <TruncatedText
                    label="URL"
                    content="https://onboarding-portal.eu-central.production.kestrelmobility.example/customers"
                >
                    <p className="truncate">
                        https://onboarding-portal.eu-central.production.kestrelmobility.example/customers
                    </p>
                </TruncatedText>
            </ListRow>,
        );
        const url = screen.getByText(/onboarding-portal/);
        expect(url.tabIndex).toBe(0);
        await focus(url);
        expect(screen.getByRole('tooltip').textContent).toBe(
            'https://onboarding-portal.eu-central.production.kestrelmobility.example/customers',
        );
    } finally {
        // jsdom defines both on Element.prototype; removing the overrides restores them.
        Reflect.deleteProperty(HTMLElement.prototype, 'scrollWidth');
        Reflect.deleteProperty(HTMLElement.prototype, 'clientWidth');
    }
});
