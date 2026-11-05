import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
} from '@testing-library/react';
import { I18nextProvider } from 'react-i18next';
import { afterEach, expect, it, vi } from 'vitest';
import i18n from '@/lib/i18n';
import { PresentationChanges } from './presentation-changes';

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    usePage: () => ({ props: { timezone: 'UTC' } }),
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

it.each([
    [
        'en',
        'crawl.collection_reused',
        'Evaluated the crawl from Oct 25, 2026, 18:25',
    ],
    [
        'de',
        'crawl.collection_reused',
        'Crawl vom 25.10.2026, 18:25 ausgewertet',
    ],
    [
        'en',
        'performance.no_data_while_down',
        'No response-time data while the site is down (since Oct 25, 2026, 18:25)',
    ],
    [
        'de',
        'performance.no_data_while_down',
        'Keine Antwortzeiten, solange die Website ausgefallen ist (seit 25.10.2026, 18:25)',
    ],
    [
        'en',
        'freshness.suppressed.check.down',
        'No current check data while the site is down (since Oct 25, 2026, 18:25)',
    ],
    [
        'de',
        'freshness.suppressed.check.down',
        'Keine aktuellen Prüfdaten, solange die Website ausgefallen ist (seit 25.10.2026, 18:25)',
    ],
])(
    'preserves the exact time behind %s prose for %s',
    async (locale, key, label) => {
        await i18n.changeLanguage(locale);

        render(
            <I18nextProvider i18n={i18n}>
                <PresentationChanges
                    changes={[]}
                    details={[
                        {
                            key,
                            params: { date: '2026-10-25T18:25:14Z' },
                            labels: {},
                            shortKey: null,
                            untrusted: {},
                            trusted: [],
                            lists: {},
                        },
                    ]}
                />
            </I18nextProvider>,
        );

        const prose = screen.getByText(label);
        expect(await tooltipOf(prose)).toBe(
            locale === 'en'
                ? 'Sunday, October 25, 2026 at 18:25:14'
                : 'Sonntag, 25. Oktober 2026 um 18:25:14',
        );
    },
);

it.each([
    ['en', ['A'], 'A records'],
    ['en', ['A', 'AAAA'], 'A and AAAA records'],
    ['en', ['A', 'AAAA', 'MX'], 'A, AAAA and MX records'],
    ['de', ['A'], 'A-Einträge'],
    ['de', ['A', 'AAAA'], 'A- und AAAA-Einträge'],
    ['de', ['A', 'AAAA', 'MX'], 'A-, AAAA- und MX-Einträge'],
] as const)('renders DNS lists in %s for %j', async (locale, types, prefix) => {
    await i18n.changeLanguage(locale);
    const cases: [string, string][] = [
        [
            'dns.presentation.changed',
            locale === 'de'
                ? ' geändert: 1 entfernt, 2 hinzugefügt'
                : ' changed: 1 removed, 2 added',
        ],
        [
            'dns.presentation.added',
            locale === 'de' ? ' geändert: 2 hinzugefügt' : ' changed: 2 added',
        ],
        [
            'dns.presentation.removed',
            locale === 'de' ? ' geändert: 1 entfernt' : ' changed: 1 removed',
        ],
        [
            'dns.presentation.changed_short',
            locale === 'de' ? ' geändert' : ' changed',
        ],
        [
            'dns.issue.records_missing',
            locale === 'de' ? ' fehlen' : ' are missing',
        ],
    ];
    for (const [key, suffix] of cases) {
        for (const value of [[...types], types.join(', ')]) {
            const view = render(
                <I18nextProvider i18n={i18n}>
                    <PresentationChanges
                        changes={[
                            {
                                key,
                                params: {
                                    ...(typeof value === 'string'
                                        ? { types: value }
                                        : {}),
                                    added: 2,
                                    removed: 1,
                                },
                                lists: Array.isArray(value)
                                    ? { types: value }
                                    : {},
                                labels: {},
                                shortKey: null,
                                untrusted: {},
                                trusted: ['types'],
                            },
                        ]}
                        details={[]}
                    />
                </I18nextProvider>,
            );
            expect(screen.getByText(prefix + suffix)).toBeTruthy();
            view.unmount();
        }
    }
});
