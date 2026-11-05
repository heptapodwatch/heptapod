import { act, cleanup, render, within } from '@testing-library/react';
import type { ComponentProps } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { RunBoard } from './run-board';

vi.mock('@inertiajs/react', () => ({
    Link: ({
        prefetch: _prefetch,
        ...props
    }: ComponentProps<'a'> & { prefetch?: boolean }) => <a {...props} />,
    usePage: () => ({ url: '/runs', props: { timezone: 'UTC' } }),
    router: { on: () => () => {}, reload: vi.fn() },
}));
vi.mock('react-i18next', () => {
    const t = (key: string) => key;
    return { useTranslation: () => ({ t, i18n: { language: 'en', t } }) };
});
vi.mock('@laravel/echo-react', () => ({
    useConnectionStatus: () => 'connected',
}));
vi.mock('@/hooks/use-live', () => ({ useLiveReload: () => vi.fn() }));

let reducedMotion = false;
beforeEach(() => {
    vi.useFakeTimers();
    reducedMotion = false;
    // jsdom has no Web Animations; only which column shows a card is under test, not the motion.
    Element.prototype.animate = vi.fn();
    Element.prototype.getAnimations = () => [];
    vi.stubGlobal('matchMedia', (query: string) => ({
        matches: query.includes('reduce') && reducedMotion,
        addEventListener() {},
        removeEventListener() {},
    }));
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    delete (document as { hidden?: boolean }).hidden;
});

const filters: Heptapod.Data.Ui.RunsFilterData = {
    client: null,
    check: null,
    type: null,
    state: null,
    before: null,
    show_same_result: false,
    period: '24h',
};

function active(
    attempt: number,
    phase: 'queued' | 'running',
): Heptapod.Data.Ui.CurrentRunData {
    return {
        check_id: attempt,
        site_id: attempt,
        client_id: 1,
        site_name: `Site ${attempt}`,
        check_type: 'uptime',
        icon_url: null,
        icon_backing: 'none',
        run: {
            check_id: attempt,
            phase,
            attempt_id: attempt,
            queue: 'checks-fast',
            queue_position: phase === 'queued' ? 1 : null,
            progress_n: null,
            progress_total: null,
            phase_label: null,
            started_at: null,
            actor: null,
            due_at: null,
            can_cancel: false,
            requested_at: null,
        },
    };
}

function done(attempt: number): Heptapod.Data.Ui.FinishedRunData {
    return {
        id: 100 + attempt,
        check_id: attempt,
        site_id: attempt,
        site_name: `Site ${attempt}`,
        check_type: 'uptime',
        result: 'succeeded',
        ended_at: '2026-10-21T12:00:01+00:00',
        duration_ms: 87,
        summary: null,
        summary_params: {},
        presentation: null,
        icon_url: null,
        icon_backing: 'none',
        attempt_id: attempt,
    };
}

function board(
    current: Heptapod.Data.Ui.CurrentRunData[],
    finished: Heptapod.Data.Ui.FinishedRunData[],
) {
    return { current, queued_more: 0, finished };
}

function columnOf(container: HTMLElement, site: string): string | null {
    for (const column of ['queued', 'running', 'finished'])
        if (
            within(
                container.querySelector<HTMLElement>(
                    `[data-run-column="${column}"]`,
                )!,
            ).queryByText(site) !== null
        )
            return column;
    return null;
}

function advance(ms: number) {
    act(() => {
        vi.advanceTimersByTime(ms);
    });
}

it('holds a fast run in Queued and Running for their minimum dwell before it reaches Finished', () => {
    const { container, rerender } = render(
        <RunBoard data={board([active(1, 'queued')], [])} filters={filters} />,
    );
    advance(100);
    // The run finished between two reloads: the server never reported it as running.
    rerender(<RunBoard data={board([], [done(1)])} filters={filters} />);
    expect(columnOf(container, 'Site 1')).toBe('queued');

    advance(299);
    expect(columnOf(container, 'Site 1')).toBe('queued');
    advance(1);
    expect(columnOf(container, 'Site 1')).toBe('running');

    advance(1199);
    expect(columnOf(container, 'Site 1')).toBe('running');
    advance(1);
    expect(columnOf(container, 'Site 1')).toBe('finished');
    expect(container.textContent).toContain('87 ms');
});

it('queues a burst of fast runs instead of dropping any, each on its own clock', () => {
    const { container, rerender } = render(
        <RunBoard data={board([active(1, 'running')], [])} filters={filters} />,
    );
    advance(1000);
    rerender(
        <RunBoard
            data={board([active(2, 'running')], [done(1)])}
            filters={filters}
        />,
    );
    advance(100);
    rerender(
        <RunBoard data={board([], [done(2), done(1)])} filters={filters} />,
    );
    expect(columnOf(container, 'Site 1')).toBe('running');
    expect(columnOf(container, 'Site 2')).toBe('running');

    advance(100);
    expect(columnOf(container, 'Site 1')).toBe('finished');
    expect(columnOf(container, 'Site 2')).toBe('running');
    advance(1000);
    expect(columnOf(container, 'Site 2')).toBe('finished');
});

it('shows a run that was never on the board in its real column at once', () => {
    const { container, rerender } = render(
        <RunBoard data={board([], [])} filters={filters} />,
    );
    rerender(<RunBoard data={board([], [done(3)])} filters={filters} />);
    expect(columnOf(container, 'Site 3')).toBe('finished');
});

it('names a failed uptime run Down and a failed run of another check Failed', () => {
    const { container } = render(
        <RunBoard
            data={board(
                [],
                [
                    { ...done(1), result: 'failed' },
                    { ...done(2), result: 'failed', check_type: 'cron' },
                ],
            )}
            filters={filters}
        />,
    );
    const finished = container.querySelector<HTMLElement>(
        '[data-run-column="finished"]',
    )!;
    const [uptime, cron] = within(finished).getAllByRole('listitem');
    expect(within(uptime!).getByRole('img').getAttribute('data-state')).toBe(
        'down',
    );
    expect(within(cron!).getByRole('img').getAttribute('data-state')).toBe(
        'failed',
    );
});

it('moves cards without delay under reduced motion', () => {
    reducedMotion = true;
    const { container, rerender } = render(
        <RunBoard data={board([active(1, 'queued')], [])} filters={filters} />,
    );
    rerender(<RunBoard data={board([], [done(1)])} filters={filters} />);
    expect(columnOf(container, 'Site 1')).toBe('finished');
});

it('shows the newest eight finished runs and links the rest to the attempt history', () => {
    const many = Array.from({ length: 20 }, (_, index) => done(index + 1));
    const { container, rerender } = render(
        <RunBoard data={board([], many)} filters={filters} />,
    );
    const column = container.querySelector<HTMLElement>(
        '[data-run-column="finished"]',
    )!;
    expect(within(column).getAllByRole('listitem')).toHaveLength(8);
    expect(within(column).getByText('Site 8')).toBeTruthy();
    expect(within(column).queryByText('Site 9')).toBeNull();
    expect(
        within(column)
            .getByRole('link', { name: 'runs.board.showHistory' })
            .getAttribute('href'),
    ).toBe('#run-history');

    rerender(<RunBoard data={board([], many.slice(0, 8))} filters={filters} />);
    expect(
        within(column).queryByRole('link', { name: 'runs.board.showHistory' }),
    ).toBeNull();
});

function finishedAt(attempt: number, endedAt: string) {
    return { ...done(attempt), ended_at: endedAt };
}

function setHidden(hidden: boolean) {
    Object.defineProperty(document, 'hidden', {
        configurable: true,
        get: () => hidden,
    });
    act(() => {
        document.dispatchEvent(new Event('visibilitychange'));
    });
}

it('shows a run that finished while the tab was hidden in Finished at once after return', () => {
    const { container, rerender } = render(
        <RunBoard data={board([active(1, 'queued')], [])} filters={filters} />,
    );
    setHidden(true);
    advance(60_000);
    setHidden(false);
    // The resync reload after return reports the run as finished long ago.
    rerender(<RunBoard data={board([], [done(1)])} filters={filters} />);
    expect(columnOf(container, 'Site 1')).toBe('finished');

    // Later fast runs get their dwell again.
    rerender(
        <RunBoard
            data={board([active(2, 'running')], [done(1)])}
            filters={filters}
        />,
    );
    rerender(
        <RunBoard data={board([], [done(2), done(1)])} filters={filters} />,
    );
    expect(columnOf(container, 'Site 2')).toBe('running');
});

it('keeps Finished newest-first when a held run arrives after newer and older results', () => {
    const { container, rerender } = render(
        <RunBoard
            data={board(
                [active(1, 'running')],
                [finishedAt(3, '2026-10-21T11:59:00+00:00')],
            )}
            filters={filters}
        />,
    );
    // Run 1 finishes; run 4 (never on the board) finishes later and shows at once.
    rerender(
        <RunBoard
            data={board(
                [],
                [
                    finishedAt(4, '2026-10-21T12:00:02+00:00'),
                    finishedAt(1, '2026-10-21T12:00:01+00:00'),
                    finishedAt(3, '2026-10-21T11:59:00+00:00'),
                ],
            )}
            filters={filters}
        />,
    );
    const order = () =>
        [
            ...container.querySelectorAll(
                '[data-run-column="finished"] li[data-flip-key]',
            ),
        ].map((item) => item.textContent?.match(/Site \d+/)?.[0]);
    expect(order()).toEqual(['Site 4', 'Site 3']);
    advance(1200);
    expect(order()).toEqual(['Site 4', 'Site 1', 'Site 3']);
});

it('counts in the column headings and the mobile tabs match the cards shown during a dwell', () => {
    const { container, rerender } = render(
        <RunBoard
            data={board([active(1, 'queued'), active(2, 'running')], [])}
            filters={filters}
        />,
    );
    rerender(
        <RunBoard data={board([], [done(2), done(1)])} filters={filters} />,
    );
    const tabs = within(
        container.querySelector<HTMLElement>(
            '[aria-label="runs.board.columns"]',
        )!,
    ).getAllByRole('radio');
    for (const step of [0, 400, 800]) {
        advance(step);
        for (const [index, column] of [
            'queued',
            'running',
            'finished',
        ].entries()) {
            const cards = container.querySelectorAll(
                `[data-run-column="${column}"] li[data-flip-key]`,
            ).length;
            expect(
                container.querySelector(`#run-column-${column}`)?.textContent,
            ).toMatch(new RegExp(`\\s${cards}$`));
            expect(tabs[index]?.textContent).toMatch(new RegExp(`${cards}$`));
        }
    }
    expect(columnOf(container, 'Site 1')).toBe('running');
    expect(columnOf(container, 'Site 2')).toBe('finished');
});

it('clears the dwell timer on unmount and shows the new scope without held cards', () => {
    const { container, rerender, unmount } = render(
        <RunBoard data={board([active(1, 'queued')], [])} filters={filters} />,
    );
    vi.advanceTimersToNextFrame();
    // Other pending timers (the delayed Reload offer) are not the board's dwell.
    const baseline = vi.getTimerCount();
    rerender(<RunBoard data={board([], [done(1)])} filters={filters} />);
    vi.advanceTimersToNextFrame();
    expect(columnOf(container, 'Site 1')).toBe('queued');
    expect(vi.getTimerCount()).toBe(baseline + 1);

    // A filter change shows the new scope as it is; no dwell timer is left from the old one.
    rerender(
        <RunBoard
            data={board([], [done(1)])}
            filters={{ ...filters, type: 'uptime' }}
        />,
    );
    vi.advanceTimersToNextFrame();
    expect(columnOf(container, 'Site 1')).toBe('finished');
    expect(vi.getTimerCount()).toBe(baseline);

    rerender(
        <RunBoard
            data={board([active(2, 'queued')], [done(1)])}
            filters={{ ...filters, type: 'uptime' }}
        />,
    );
    rerender(
        <RunBoard
            data={board([], [done(2), done(1)])}
            filters={{ ...filters, type: 'uptime' }}
        />,
    );
    vi.advanceTimersToNextFrame();
    expect(vi.getTimerCount()).toBe(baseline + 1);
    unmount();
    expect(vi.getTimerCount()).toBe(0);
});
