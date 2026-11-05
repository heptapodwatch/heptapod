import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import { CheckStrip, Sparkline, StatusBadge } from './monitoring';
import { galleryChecks } from '@/fixtures/design';
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
