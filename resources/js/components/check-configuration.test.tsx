import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
} from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, it, vi } from 'vitest';
import { I18nextProvider } from 'react-i18next';
import { CheckConfiguration } from './check-configuration';
import { CronTasksDetail } from './cron-tasks-detail';
import { SiteCheckRows } from './site-overview-content';
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

vi.hoisted(() => {
    window.matchMedia = vi.fn().mockReturnValue({
        matches: false,
        addEventListener() {},
        removeEventListener() {},
    });
});

function page() {
    const site = sites[0];
    const check = site?.checks[0];
    if (!site || !check) throw new Error('Check fixture missing');
    const page = checkTabPageFixture(site, { ...check, enabled: true });
    return {
        ...page,
        can_run: false,
        check_settings: {
            ...page.check_settings,
            can_update: false,
            config_schema: { fields: {} },
            settings: {},
        },
        schedule: { ...page.schedule, next_due_at: '2026-10-26T20:00:14Z' },
    };
}

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
    ['en', 'Re-checking after recovery · next run Oct 26, 2026, 20:00'],
    [
        'de',
        'Erneute Prüfung nach Wiederherstellung · nächster Lauf 26.10.2026, 20:00',
    ],
])(
    'keeps the 24-hour clock in the held-recovery check headline across days in %s',
    async (locale, headline) => {
        await i18n.changeLanguage(locale);
        const check = {
            ...page().check,
            pending_recheck: false,
            freshness_suppressed_by: null,
            held_after_recovery: true,
            next_run_at: '2026-10-26T20:00:14Z',
        };

        render(
            <I18nextProvider i18n={i18n}>
                <SiteCheckRows siteId={1} checks={[check]} />
            </I18nextProvider>,
            { wrapper: TooltipProvider },
        );

        expect(screen.getByText(headline)).not.toBeNull();
    },
);

it.each([
    ['en', 'Oct 26, 2026, 20:00', 'Monday, October 26, 2026 at 20:00:14'],
    ['de', '26.10.2026, 20:00', 'Montag, 26. Oktober 2026 um 20:00:14'],
])(
    'shows the next check and cron run with date and 24-hour time across days in %s',
    async (locale, label, exact) => {
        await i18n.changeLanguage(locale);
        const checkPage = page();
        render(
            <I18nextProvider i18n={i18n}>
                <CheckConfiguration page={checkPage} />
                <CronTasksDetail
                    page={checkPage}
                    history={{
                        check_type: 'cron',
                        more_tasks: false,
                        history_truncated: false,
                        tasks: [
                            {
                                id: 1,
                                name: 'Backup',
                                result: 'succeeded',
                                type: 'simple',
                                cron_expression: null,
                                timezone: 'UTC',
                                frequency_minutes: 1,
                                grace_minutes: 1,
                                last_ping_at: null,
                                last_duration_seconds: null,
                                next_due_at: '2026-10-26T20:00:14Z',
                                ping_url: 'https://example.test/ping',
                                days: [],
                            },
                        ],
                    }}
                />
            </I18nextProvider>,
            { wrapper: TooltipProvider },
        );

        const times = screen.getAllByText(label);
        expect(times).toHaveLength(2);
        for (const time of times) {
            expect(time.tagName).toBe('TIME');
            expect(time.getAttribute('datetime')).toBe('2026-10-26T20:00:14Z');
            expect(await tooltipOf(time)).toBe(exact);
        }
    },
);

it.each([
    ['en', 'Slowest (100 ms)', 'Structure only (no page requests)'],
    ['de', 'Am langsamsten (100 ms)', 'Nur Struktur (keine Seitenanfragen)'],
])(
    'names stored choice settings by their translated option in %s',
    async (locale, speed, urlsPerRun) => {
        await i18n.changeLanguage(locale);
        const choice = (labelKey: string, options: Record<string, string>) => ({
            type: 'string' as const,
            rules: ['sometimes'],
            properties: {},
            items: null,
            presentation: { labelKey, helpKey: null, options },
            additionalProperties: false,
            targetUrl: false,
            default: null,
            uppercase: false,
            trimmedStrings: false,
        });
        render(
            <I18nextProvider i18n={i18n}>
                <CheckConfiguration
                    page={{
                        ...page(),
                        check_settings: {
                            ...page().check_settings,
                            config_schema: {
                                fields: {
                                    speed: choice('sitemap.speed', {
                                        slowest: 'sitemap.speed.slowest',
                                    }),
                                    urls_per_run: choice(
                                        'sitemap.urls_per_run',
                                        {
                                            '0': 'sitemap.urls_per_run.0',
                                        },
                                    ),
                                },
                            },
                            settings: { speed: 'slowest', urls_per_run: '0' },
                        },
                    }}
                />
            </I18nextProvider>,
            { wrapper: TooltipProvider },
        );

        expect(screen.getByText(speed)).not.toBeNull();
        expect(screen.getByText(urlsPerRun)).not.toBeNull();
        expect(screen.queryByText('slowest')).toBeNull();
    },
);
