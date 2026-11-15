import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import { CheckStrip, StatusBadge } from './monitoring';
import { Sparkline } from './sparkline';
import { galleryChecks } from '@/fixtures/design';
import { checkState } from '@/lib/status';
import { TooltipProvider } from '@/components/ui/tooltip';
import type { ResponsePointDto } from '@/types/monitoring';

vi.mock('react-i18next', () => ({
    useTranslation: () => ({
        t: (key: string) => key,
        i18n: { language: 'en', t: (key: string) => key },
    }),
}));
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { timezone: 'UTC' } }),
}));

afterEach(cleanup);

it('renders the OK status as visible text, not an icon alone', () => {
    render(<StatusBadge state="ok" />);
    expect(screen.getByText('OK').textContent).toBe('OK');
});

// TODO: Replace per-cell image assertions with the single summarized strip contract after test-free mode.
it.todo('renders compact check status cells in stable type order');

const sample = (
    time: string,
    value: number | null,
    marks: Partial<ResponsePointDto> = {},
): ResponsePointDto => ({
    time,
    value,
    failure_count: 0,
    error_count: 0,
    warning_count: 0,
    failure_status: null,
    ...marks,
});

function sparkline(data: ResponsePointDto[]) {
    return render(
        <TooltipProvider>
            <Sparkline data={data} state="ok" decorative />
        </TooltipProvider>,
    ).container;
}

it('shows the muted no-trend placeholder instead of a lone dot for a single sample', () => {
    const container = sparkline([sample('2026-10-21T11:00:00Z', 240)]);
    expect(within(container).getByText('OK')).toBeTruthy();
    expect(container.querySelector('.recharts-wrapper')).toBeNull();
});

it.each([
    ['error', { error_count: 1 }, '[data-sparkline-error]'],
    ['warning', { warning_count: 1 }, '[data-sparkline-warning]'],
])(
    'keeps a single %s bucket visible as a mark without a line',
    (_, marks, selector) => {
        const container = sparkline([
            sample('2026-10-21T11:00:00Z', 240, marks),
        ]);
        expect(container.querySelector(selector)).not.toBeNull();
        expect(within(container).queryByText('OK')).toBeNull();
        expect(container.querySelector('.recharts-line')).toBeNull();
    },
);

it('keeps a single failed bucket visible as a mark without a line', () => {
    const container = sparkline([
        sample('2026-10-21T11:00:00Z', 240, {
            failure_count: 1,
            failure_status: 'failed',
        }),
    ]);
    expect(container.querySelector('[data-sparkline-failure]')).not.toBeNull();
    expect(within(container).queryByText('OK')).toBeNull();
    expect(container.querySelector('.recharts-line')).toBeNull();
});

it('moves the tooltip straight to the next check cell when the pointer slides across the strip', async () => {
    // Radix schedules the first open with a 0 ms timer; fake timers make every step explicit.
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'Date'] });
    render(<CheckStrip checks={galleryChecks} />);
    const uptime = screen.getByRole('img', { name: /^checks.uptime.label · / });
    const performance = screen.getByRole('img', {
        name: /^checks.performance.label · /,
    });

    act(() => {
        fireEvent.pointerMove(uptime, { pointerType: 'mouse' });
        vi.advanceTimersByTime(0);
    });
    expect(screen.getByRole('tooltip').textContent).toContain(
        'checks.uptime.label',
    );

    act(() => {
        fireEvent.pointerLeave(uptime, { pointerType: 'mouse' });
        fireEvent.pointerMove(performance, { pointerType: 'mouse' });
        vi.advanceTimersByTime(0);
    });

    expect(
        screen.getAllByRole('tooltip').map((tooltip) => tooltip.textContent),
    ).toEqual([expect.stringContaining('checks.performance.label')]);
});

it('draws each check slot from its own state: snoozed keeps the result colour, turned off is empty, not set up dashed', () => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2026-10-03T12:00:00Z'));
    const base = galleryChecks.find((check) => check.key === 'uptime')!;
    const check = (
        key: Heptapod.Contracts.CheckKey,
        overrides: Partial<typeof base>,
    ) => ({
        ...base,
        id: base.id + key.length,
        key,
        label_key: key,
        ...overrides,
    });
    render(
        <CheckStrip
            checks={[
                check('uptime', { state: 'ok', result: 'succeeded' }),
                check('sitemap', {
                    state: 'failed',
                    result: 'failed',
                    snoozed_until: '2026-10-03T13:00:00Z',
                }),
                // An expired snooze reads as the plain result again.
                check('dns', {
                    state: 'failed',
                    result: 'failed',
                    snoozed_until: '2026-10-03T11:00:00Z',
                }),
                // A snooze on a turned-off check does not resurface it.
                check('domain', {
                    state: 'disabled',
                    enabled: false,
                    snoozed_until: '2026-10-03T13:00:00Z',
                }),
                check('broken_links', {
                    state: 'ok',
                    freshness_suppressed_by: 'down',
                    freshness_suppression_label_key: 'Site is down',
                }),
            ]}
        />,
    );
    const slot = (key: string) =>
        screen.getByRole('img', { name: new RegExp(`^${key} · `) });
    const dot = (key: string) =>
        slot(key).querySelector<HTMLElement>('.status-ring');

    expect(slot('sitemap').dataset.checkMark).toBe('snoozed');
    expect(dot('sitemap')?.dataset).toMatchObject({
        state: 'failed',
        snoozed: 'true',
    });
    expect(slot('sitemap').getAttribute('aria-label')).toContain('Failed');
    expect(dot('dns')?.dataset.snoozed).toBeUndefined();
    expect(dot('dns')?.dataset.state).toBe('failed');
    expect(slot('domain').dataset.checkMark).toBe('off');
    expect(slot('domain').getAttribute('aria-label')).toBe(
        'domain · Turned off',
    );
    expect(dot('domain')).toBeNull();
    // A gap without current data reads as no result yet, never as Off.
    expect(dot('broken_links')?.dataset.state).toBe('pending');
    expect(slot('checks.lighthouse.label').dataset.checkMark).toBe('unset');
    expect(
        screen
            .queryAllByRole('img')
            .some((element) => element.dataset.state === 'paused'),
    ).toBe(false);
    vi.useRealTimers();
});

it('keeps a snoozed failure failed when an uptime outage also suppresses its freshness gap', () => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2026-10-03T12:00:00Z'));
    const gap = {
        state: 'failed' as const,
        freshness_suppressed_by: 'down' as const,
    };
    // Attention, counts and sorting read this state: a snooze silences alerts, it never hides the failure.
    expect(checkState({ ...gap, snoozed_until: '2026-10-03T13:00:00Z' })).toBe(
        'failed',
    );
    expect(checkState({ ...gap, snoozed_until: null })).toBe('pending');
    vi.useRealTimers();
});
