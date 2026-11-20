import { act, cleanup, render, screen, within } from '@testing-library/react';
import type { ComponentProps } from 'react';
import { afterEach, expect, it, vi } from 'vitest';
import { SidebarStatus } from '@/components/sidebar-status';
import { SidebarProvider } from '@/components/ui/sidebar';
import { receiveLiveSignal } from '@/lib/live-store';

vi.hoisted(() => {
    window.matchMedia = vi.fn().mockReturnValue({
        matches: false,
        addEventListener() {},
        removeEventListener() {},
    });
});

const manualRun: Heptapod.Data.Ui.RunningCheckRunData = {
    check_id: 3,
    site_id: 1,
    site_name: 'Shop',
    check_type: 'sitemap',
    mine: true,
    result: null,
    finished_at: null,
    run: {
        check_id: 3,
        phase: 'running',
        attempt_id: 5,
        queue: 'checks-crawl',
        queue_position: null,
        progress_n: null,
        progress_total: null,
        phase_label: null,
        started_at: '2026-11-20T12:00:00+00:00',
        actor: 'Test User',
        due_at: null,
        can_cancel: false,
        requested_at: '2026-11-20T11:59:59+00:00',
        actor_id: 7,
        origin: 'manual',
    },
};

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Link: ({
        prefetch: _prefetch,
        ...props
    }: ComponentProps<'a'> & { prefetch?: boolean }) => <a {...props} />,
    usePage: () => ({
        url: '/sites',
        props: {
            timezone: 'UTC',
            auth: { user: { id: 7 }, canManageChannels: true },
            notificationAttention: [],
            overallHealth: null,
            runningNow: {
                check_runs: [manualRun],
                active_check_runs: 1,
                imports: [],
                backup: null,
            },
        },
    }),
}));
vi.mock('react-i18next', () => {
    const t = (key: string) => key;
    return { useTranslation: () => ({ t, i18n: { language: 'en', t } }) };
});

afterEach(cleanup);

it("keeps the manual run's row when a scheduled run of the same check finishes", () => {
    render(
        <SidebarProvider>
            <SidebarStatus />
        </SidebarProvider>,
    );
    act(() =>
        receiveLiveSignal({
            topic: 'run.finished',
            ids: { site: 1, check: 3 },
            at: '2026-11-20T12:01:00+00:00',
            run: {
                check_id: 3,
                phase: 'finished',
                attempt_id: 9,
                origin: 'scheduled',
                outcome: 'done',
                result: 'succeeded',
                check_run_id: 40,
                ended_at: '2026-11-20T12:01:00+00:00',
                queue_position: null,
                progress_n: null,
                progress_total: null,
                started_at: '2026-11-20T12:00:30+00:00',
                actor: null,
            },
        }),
    );

    const row = screen.getByRole('link', { name: /Shop/ });
    expect(row.textContent).toContain('You');
    expect(row.textContent).not.toContain('OK');
    expect(within(row).getByRole('img', { name: 'Running' })).toBeTruthy();
});
