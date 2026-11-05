import { cleanup, render, screen } from '@testing-library/react';
import { I18nextProvider } from 'react-i18next';
import { afterEach, expect, it, vi } from 'vitest';
import { CronTasksDetail } from './cron-tasks-detail';
import i18n from '@/lib/i18n';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { timezone: 'UTC' } }),
    router: { on: () => () => {} },
}));

afterEach(cleanup);

it.each([
    ['en', 1, 'Every minute'],
    ['de', 1, 'Jede Minute'],
    ['en', 60, 'Every hour'],
    ['de', 60, 'Jede Stunde'],
    ['en', 1440, 'Every day'],
    ['de', 1440, 'Täglich'],
    ['en', 90, 'Every 1 hr, 30 min'],
    ['de', 90, 'Alle 1 Std., 30 Min.'],
] as const)(
    'shows the %s schedule for %i minutes as %s',
    async (locale, minutes, phrase) => {
        await i18n.changeLanguage(locale);

        render(
            <I18nextProvider i18n={i18n}>
                <CronTasksDetail
                    page={{ can_run: false, site: { id: 1 }, check: { id: 1 } }}
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
                                frequency_minutes: minutes,
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
        );

        expect(
            screen.getByText((text) => text.startsWith(`${phrase} · UTC`)),
        ).not.toBeNull();
    },
);
