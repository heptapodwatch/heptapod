import { renderHook } from '@testing-library/react';
import { I18nextProvider } from 'react-i18next';
import type { ReactNode } from 'react';
import { expect, it, vi } from 'vitest';
import { useFormat } from '@/hooks/use-format';
import i18n from '@/lib/i18n';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { timezone: 'UTC' } }),
    router: { on: () => () => {} },
}));

function formatter(locale: 'en' | 'de') {
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
