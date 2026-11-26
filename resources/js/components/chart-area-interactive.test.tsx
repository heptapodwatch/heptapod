import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { ChartAreaInteractive } from './chart-area-interactive';

const reload = vi.hoisted(() => vi.fn());
vi.mock('@inertiajs/react', () => ({
    router: { reload },
    usePage: () => ({ props: { timezone: 'UTC' } }),
}));

const series = (
    key: Heptapod.Enums.ResponseTimeRange,
): Heptapod.Data.Ui.ResponseTimeSeriesData => ({
    key,
    points: [],
    recent_average_ms: null,
});

beforeEach(() => {
    vi.stubGlobal(
        'ResizeObserver',
        class {
            observe() {}
            unobserve() {}
            disconnect() {}
        },
    );
});
afterEach(() => {
    cleanup();
    reload.mockClear();
    vi.unstubAllGlobals();
});

const view = (name: string) => screen.getByRole('radio', { name });
const phaseCaption = () => screen.getByText('5-minute maximum per phase');

it('shows the phases at 24 hours and returns to the total when a longer range is chosen', () => {
    render(<ChartAreaInteractive series={series('24h')} phases={[]} />);
    fireEvent.click(view('Phases'));
    expect(view('Phases').getAttribute('aria-checked')).toBe('true');
    expect(phaseCaption().getAttribute('aria-hidden')).toBeNull();

    fireEvent.click(
        within(
            screen.getByRole('radiogroup', { name: 'Time range' }),
        ).getByRole('radio', { name: '7 days' }),
    );
    expect(reload).toHaveBeenCalledWith(
        expect.objectContaining({ data: { response_range: '7d' } }),
    );
    expect(view('Total').getAttribute('aria-checked')).toBe('true');
    expect(phaseCaption().getAttribute('aria-hidden')).toBe('true');
});

it('keeps Phases unavailable past 24 hours and says why', async () => {
    render(<ChartAreaInteractive series={series('7d')} phases={[]} />);
    const phases = view('Phases');
    expect(phases.getAttribute('aria-disabled')).toBe('true');
    fireEvent.click(phases);
    expect(phases.getAttribute('aria-checked')).toBe('false');
    expect(view('Total').getAttribute('aria-checked')).toBe('true');
    expect(
        (await screen.findAllByText('Phases are kept for 24 hours.')).length,
    ).toBeGreaterThan(0);
});
