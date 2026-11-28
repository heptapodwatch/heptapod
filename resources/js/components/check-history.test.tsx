import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import type { ReactNode } from 'react';
import { I18nextProvider } from 'react-i18next';
import { CheckHistory } from './check-history';
import { TooltipProvider } from '@/components/ui/tooltip';
import { checkTabPageFixture, sites } from '@/fixtures/design';
import i18n from '@/lib/i18n';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { timezone: 'UTC' } }),
    router: { on: () => () => {} },
    Link: ({ children, href }: { children: ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
}));

type Run = Heptapod.Data.Ui.CheckRunData;

function part(key: string, params: Record<string, number> = {}) {
    return {
        key,
        params,
        labels: {},
        shortKey: null,
        trusted: [],
        lists: {},
        untrusted: {},
    };
}

function run(
    id: number,
    median = 1950,
    result: Run['result'] = 'warning',
): Run {
    return {
        id,
        result,
        started_at: null,
        ended_at: `2026-10-25T${String(12 + id).padStart(2, '0')}:00:00Z`,
        summary: null,
        summary_params: {},
        presentation: {
            parts: [
                part('performance.recovery_band', {
                    median,
                    recovery_below: 1800,
                }),
            ],
            history_label: part('checks.history.performance_recovery_band', {
                median,
                recovery_below: 1800,
            }),
            changes: [part('Recovery counter {{count}}', { count: id })],
            details: [part('Measured {{value}} ms', { value: 1900 + id })],
            explanation: null,
            names_check: false,
            headline_limit: 2,
            headline_count: 1,
            more_reasons: 0,
        },
    };
}

function page(runs: Run[]) {
    const site = sites[0];
    const check = site?.checks[0];
    if (!site || !check) throw new Error('Check fixture missing');
    return {
        ...checkTabPageFixture(site, { ...check, key: 'performance' }),
        history: null,
        runs,
    };
}

afterEach(cleanup);

it.each([
    ['en', 'Unchanged · 3 runs', 'Show', 'Retained runs'],
    ['de', 'Unverändert · 3 Läufe', 'Zeigen', 'Gespeicherte Läufe'],
])(
    'collapses identical Warning headlines despite changing details in %s and retains every run on expansion',
    async (locale, unchanged, show, retained) => {
        await i18n.changeLanguage(locale);
        render(
            <I18nextProvider i18n={i18n}>
                <CheckHistory
                    page={page([run(5), run(4), run(3), run(2), run(1)])}
                />
            </I18nextProvider>,
            { wrapper: TooltipProvider },
        );

        const summary = screen.getByText(unchanged).closest('button');
        expect(summary).not.toBeNull();
        expect(within(summary!).getByText(show)).not.toBeNull();
        expect(screen.getAllByText(/Median 1[,.]950 ms/)).toHaveLength(2);
        expect(
            document.querySelectorAll('[data-check-history] > ol > li'),
        ).toHaveLength(3);
        expect(summary!.getAttribute('aria-expanded')).toBe('false');
        expect(screen.queryByRole('list', { name: retained })).toBeNull();
        fireEvent.click(summary!);
        expect(summary!.getAttribute('aria-expanded')).toBe('true');
        expect(screen.getAllByText(/Median 1[,.]950 ms/)).toHaveLength(5);
        const runs = screen.getByRole('list', { name: retained });
        expect(within(runs).getAllByRole('listitem')).toHaveLength(3);
        expect(
            within(runs).getByText(/Recovery counter 4 · Measured 1[,.]904 ms/),
        ).not.toBeNull();
    },
);

it('starts a new streak when the headline median or result changes and never joins nonconsecutive runs', async () => {
    await i18n.changeLanguage('en');
    render(
        <I18nextProvider i18n={i18n}>
            <CheckHistory
                page={page([
                    run(5),
                    run(4),
                    run(3, 1951),
                    run(2, 1951, 'failed'),
                    run(1),
                ])}
            />
        </I18nextProvider>,
        { wrapper: TooltipProvider },
    );

    expect(screen.queryByText(/Unchanged/)).toBeNull();
    expect(
        document.querySelectorAll('[data-check-history] > ol > li'),
    ).toHaveLength(5);
    expect(screen.getAllByText(/Median 1,951 ms/)).toHaveLength(2);
});

it('uses the same absolute seconds for individual, grouped and expanded minute-frequency runs', async () => {
    await i18n.changeLanguage('en');
    vi.useFakeTimers();
    try {
        vi.setSystemTime(new Date('2026-10-25T17:05:00Z'));
        const data = page([run(5), run(4), run(3), run(2), run(1)]);
        render(
            <I18nextProvider i18n={i18n}>
                <CheckHistory
                    page={{
                        ...data,
                        schedule: { ...data.schedule, interval_seconds: 60 },
                    }}
                />
            </I18nextProvider>,
            { wrapper: TooltipProvider },
        );

        expect(screen.getByText('Today · 17:00:00')).not.toBeNull();
        const group = screen.getByRole('button', { name: /Unchanged/ });
        expect(group.textContent).toContain('Today · 14:00:00 – 16:00:00');
        expect(
            Array.from(group.querySelectorAll('time'), (time) => time.dateTime),
        ).toEqual(['2026-10-25T14:00:00Z', '2026-10-25T16:00:00Z']);
        fireEvent.click(group);
        expect(screen.getByText('Today · 16:00:00')).not.toBeNull();
    } finally {
        vi.useRealTimers();
    }
});
