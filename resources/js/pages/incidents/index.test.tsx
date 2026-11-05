import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import { I18nextProvider } from 'react-i18next';
import type { ComponentProps } from 'react';
import IncidentsIndex from '@/pages/incidents/index';
import { TooltipProvider } from '@/components/ui/tooltip';
import i18n from '@/lib/i18n';

vi.hoisted(() => {
    window.matchMedia = vi.fn().mockReturnValue({
        matches: false,
        addEventListener() {},
        removeEventListener() {},
    });
});

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({
        href,
        children,
        prefetch: _prefetch,
        ...props
    }: ComponentProps<'a'> & { prefetch?: boolean }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    usePage: () => ({ url: '/incidents', props: { timezone: 'UTC' } }),
    router: { on: () => () => {} },
}));

afterEach(cleanup);

function incident(
    id: number,
    started_at: string,
    duration_seconds: number,
): Heptapod.Data.Ui.IncidentData {
    return {
        id,
        site_id: id,
        site_name: `Site ${id}`,
        client_id: 1,
        check: 'cron',
        cause: 'failed',
        duration_seconds,
        started_at,
        resolved_at: '2026-10-21T11:59:00Z',
        error: null,
        snoozed_until: null,
        summary: null,
        summary_params: {},
        icon_url: null,
        uptime_parent_incident_id: null,
        affected_checks: [],
        uptime_recheck_after: null,
        held_after_recovery: false,
        dependency_headline_key: null,
        next_run_at: null,
        icon_backing: 'none',
        presentation: null,
    };
}

function renderIncidents(
    incidents = [
        // The frozen clock is 2026-10-21T12:00:00Z: one start today, one yesterday.
        incident(1, '2026-10-21T11:56:00Z', 26 * 60 + 14),
        incident(2, '2026-10-20T22:10:00Z', 2 * 3600 + 9 * 60),
    ],
) {
    render(
        <I18nextProvider i18n={i18n}>
            <TooltipProvider>
                <IncidentsIndex
                    incidents={incidents}
                    clients={[]}
                    filters={{
                        status: 'all',
                        cause: null,
                        check: null,
                        client_id: null,
                        started_after: null,
                        started_before: null,
                        period: '30d',
                    }}
                    pagination={{
                        current_page: 1,
                        last_page: 1,
                        per_page: 50,
                        total: 2,
                        previous_page_url: null,
                        next_page_url: null,
                    }}
                    summary={{
                        count: 2,
                        total_duration_seconds: 0,
                        median_duration_seconds: null,
                    }}
                />
            </TooltipProvider>
        </I18nextProvider>,
    );
    const table = screen.getByRole('table');
    return within(table)
        .getAllByRole('row')
        .slice(1)
        .map((row) => within(row).getAllByRole('cell'));
}

/** What a sighted user reads in a cell: the visually hidden screen-reader text is not part of it. */
function visible(cell: HTMLElement | undefined): string {
    const copy = cell!.cloneNode(true) as HTMLElement;
    copy.querySelectorAll('.sr-only').forEach((node) => node.remove());
    return copy.textContent ?? '';
}

it.each([
    ['en', ['Oct 21, 2026, 11:56', 'Oct 20, 2026, 22:10'], ['26m', '2h']],
    ['de', ['21.10.2026, 11:56', '20.10.2026, 22:10'], ['26 Min.', '2 Std.']],
])(
    'shows one kind of start time and a compact duration per row in %s',
    async (locale, started, durations) => {
        await i18n.changeLanguage(locale);
        const rows = renderIncidents();

        expect(rows.map((cells) => visible(cells[4]))).toEqual(started);
        expect(rows.map((cells) => visible(cells[5]))).toEqual(durations);
    },
);

it('gives screen readers the exact time and duration without opening a tooltip', async () => {
    await i18n.changeLanguage('en');
    const rows = renderIncidents();

    expect(rows[0]![4]!.textContent).toBe(
        'Oct 21, 2026, 11:56 (Wednesday, October 21, 2026 at 11:56:00)',
    );
    expect(rows[0]![5]!.textContent).toBe('26m (26m 14s)');
    expect(screen.queryByRole('tooltip')).toBeNull();
});

it('lists the row values in one tooltip when the row link gets keyboard focus', async () => {
    await i18n.changeLanguage('de');
    const rows = renderIncidents();
    const link = within(rows[0]![1]!).getByRole('link');

    await act(async () => {
        fireEvent.focus(link);
    });

    const tooltips = screen.getAllByRole('tooltip');
    expect(tooltips).toHaveLength(1);
    expect(tooltips[0]!.textContent).toBe(
        'Beginn: Mittwoch, 21. Oktober 2026 um 11:56:00Dauer: 26 Min., 14 Sek.',
    );
});

it('opens no combined tooltip when the row link is focused by a pointer press', async () => {
    await i18n.changeLanguage('en');
    const rows = renderIncidents();
    const link = within(rows[0]![1]!).getByRole('link');

    await act(async () => {
        fireEvent.pointerDown(link);
        fireEvent.focus(link);
    });

    expect(screen.queryByRole('tooltip')).toBeNull();
});

it('shows only the hovered cell value on mouse hover', async () => {
    await i18n.changeLanguage('de');
    const rows = renderIncidents();
    const duration = within(rows[0]![5]!).getByText('26 Min.');

    await act(async () => {
        fireEvent.pointerMove(duration, { pointerType: 'mouse' });
    });

    expect(
        (await screen.findAllByRole('tooltip')).map(
            (tooltip) => tooltip.textContent,
        ),
    ).toEqual(['26 Min., 14 Sek.']);
    expect(duration.hasAttribute('tabindex')).toBe(false);
});

it('shows the State column as a StatusBadge and gives open rows the bar in their own tone, resolved rows none', async () => {
    await i18n.changeLanguage('en');
    const rows = renderIncidents([
        {
            ...incident(1, '2026-10-21T11:56:00Z', 60),
            check: 'uptime' as const,
            resolved_at: null,
        },
        {
            ...incident(2, '2026-10-21T11:50:00Z', 60),
            check: 'lighthouse' as const,
            cause: 'errored' as const,
            resolved_at: null,
        },
        {
            ...incident(3, '2026-10-21T11:40:00Z', 60),
            check: 'cron' as const,
            resolved_at: null,
        },
        incident(4, '2026-10-20T22:10:00Z', 2 * 3600 + 9 * 60),
    ]);
    const badges = rows.map((cells) =>
        cells[0]!.querySelector('[data-slot="badge"][data-state]'),
    );
    expect(badges.map((badge) => badge?.getAttribute('data-state'))).toEqual([
        'down',
        'check-error',
        'failed',
        'ok',
    ]);
    expect(badges.map((badge) => badge?.textContent)).toEqual([
        'Open',
        'Open',
        'Open',
        'Resolved',
    ]);
    const bars = rows.map(
        (cells) =>
            /shadow-\[inset_4px_0_0_var\(--([\w-]+)\)\]/.exec(
                cells[0]!.closest('tr')!.className,
            )?.[1] ?? null,
    );
    // Down and Failed share the destructive bar; an open Check error is neutral, never a Down claim.
    expect(bars).toEqual(['status-down', 'status-error', 'status-down', null]);
});
