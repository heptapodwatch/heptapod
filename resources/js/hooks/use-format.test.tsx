import { renderHook } from '@testing-library/react';
import { I18nextProvider } from 'react-i18next';
import type { ReactNode } from 'react';
import { expect, it, vi } from 'vitest';
import { useFormat } from '@/hooks/use-format';
import i18n from '@/lib/i18n';

const pageContext = vi.hoisted(() => ({ timezone: 'UTC' }));
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { timezone: pageContext.timezone } }),
    router: { on: () => () => {} },
}));

function formatter(locale: 'en' | 'de', timezone = 'UTC') {
    pageContext.timezone = timezone;
    void i18n.changeLanguage(locale);
    return renderHook(() => useFormat(), {
        wrapper: ({ children }: { children: ReactNode }) => (
            <I18nextProvider i18n={i18n}>{children}</I18nextProvider>
        ),
    }).result.current;
}

it.each([
    [60, 'Every minute', 'Jede Minute'],
    [300, 'Every 5 minutes', 'Alle 5 Minuten'],
    [3600, 'Every hour', 'Jede Stunde'],
    [7200, 'Every 2 hours', 'Alle 2 Stunden'],
    [86400, 'Every day', 'Täglich'],
    [172800, 'Every 2 days', 'Alle 2 Tage'],
    [5400, 'Every 1 hr, 30 min', 'Alle 1 Std., 30 Min.'],
    [30, 'Every 30 seconds', 'Alle 30 Sekunden'],
] as const)('formats the check interval %i in EN and DE', (seconds, en, de) => {
    expect(formatter('en').intervalPhrase(seconds)).toBe(en);
    expect(formatter('de').intervalPhrase(seconds)).toBe(de);
});

it('formats maintenance source wall times only when the window zone differs from the viewers zone', () => {
    const berlinEn = formatter('en', 'Europe/Berlin');
    const berlinDe = formatter('de', 'Europe/Berlin');

    // Same zone: no duplicate wall time.
    expect(
        berlinEn.windowSourceTime(
            '2026-11-21T14:00:00Z',
            '2026-11-21T15:00:00Z',
            'Europe/Berlin',
        ),
    ).toBeNull();
    expect(
        berlinEn.instantSourceTime('2026-11-21T15:00:00Z', 'Europe/Berlin'),
    ).toBeNull();

    // Same calendar day in Berlin (15:00–16:00) and New York (09:00–10:00).
    expect(
        berlinEn.windowSourceTime(
            '2026-11-21T14:00:00Z',
            '2026-11-21T15:00:00Z',
            'America/New_York',
        ),
    ).toBe('(09:00–10:00 New York)');
    expect(
        berlinDe.instantSourceTime('2026-11-21T15:00:00Z', 'America/New_York'),
    ).toBe('(10:00 New York)');

    // Different calendar day: Berlin is Nov 22 02:00–04:00, New York is Nov 21 20:00–22:00.
    expect(
        berlinEn.windowSourceTime(
            '2026-11-22T01:00:00Z',
            '2026-11-22T03:00:00Z',
            'America/New_York',
        ),
    ).toBe('(Nov 21, 2026, 20:00–22:00 New York)');
    expect(
        berlinDe.windowSourceTime(
            '2026-11-22T01:00:00Z',
            '2026-11-22T03:00:00Z',
            'America/New_York',
        ),
    ).toBe('(21.11.2026, 20:00–22:00 New York)');
    expect(
        berlinEn.instantSourceTime('2026-11-22T03:00:00Z', 'America/New_York'),
    ).toBe('(Nov 21, 2026, 22:00 New York)');
});
