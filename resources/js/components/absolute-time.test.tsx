import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
} from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import { AbsoluteTime } from './absolute-time';

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

const context = vi.hoisted(() => ({ locale: 'en', timezone: 'UTC' }));
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { timezone: context.timezone } }),
}));
vi.mock('react-i18next', () => ({
    useTranslation: () => ({
        i18n: { language: context.locale },
    }),
}));

it.each([
    [
        'en',
        'UTC',
        'Oct 25, 2026, 18:25',
        'Sunday, October 25, 2026 at 18:25:14',
    ],
    [
        'de',
        'Europe/Berlin',
        '25.10.2026, 19:25',
        'Sonntag, 25. Oktober 2026 um 19:25:14',
    ],
])(
    'shows minute precision in %s with exact seconds available',
    async (locale, timezone, label, exact) => {
        context.locale = locale;
        context.timezone = timezone;
        const value = '2026-10-25T18:25:14Z';

        render(<AbsoluteTime value={value} />);

        const time = screen.getByText(label);
        expect(time.tagName).toBe('TIME');
        expect(time.getAttribute('datetime')).toBe(value);
        expect(time.hasAttribute('title')).toBe(false);
        expect(await tooltipOf(time)).toBe(exact);
    },
);

it('keeps seconds visible for an explicitly requested event clock', async () => {
    context.locale = 'en';
    context.timezone = 'UTC';

    render(<AbsoluteTime value="2026-10-25T18:25:14Z" mode="eventTime" />);

    expect(await tooltipOf(screen.getByText('18:25:14'))).toBe(
        'Sunday, October 25, 2026 at 18:25:14',
    );
});
