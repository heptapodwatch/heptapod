import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import {
    markLiveDataFresh,
    receiveLiveSignal,
    setStreamStatus,
    useStreamState,
} from '@/lib/live-store';
import { useLiveReload } from './use-live';

const { reload } = vi.hoisted(() => ({ reload: vi.fn() }));
vi.mock('@inertiajs/react', () => ({
    router: { reload, on: () => () => {} },
    usePage: () => ({ url: '/' }),
}));

beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-10-21T12:00:00Z'));
    act(() => {
        setStreamStatus('offline');
        markLiveDataFresh();
    });
});

afterEach(() => {
    vi.useRealTimers();
    reload.mockReset();
});

it('reloads exactly once when the first private subscription becomes ready', () => {
    const { unmount } = renderHook(() =>
        useLiveReload(['state.changed'], ['sites']),
    );

    act(() => setStreamStatus('connecting'));
    expect(reload).not.toHaveBeenCalled();
    act(() => setStreamStatus('connected'));
    act(() => {
        vi.advanceTimersByTime(1_000);
    });
    expect(reload).toHaveBeenCalledTimes(1);
    act(() => setStreamStatus('connected'));
    act(() => {
        vi.advanceTimersByTime(1_000);
    });
    expect(reload).toHaveBeenCalledTimes(1);
    unmount();
});

it('shows stale data after a partial reload fails and retries on the next poll', () => {
    act(() => {
        setStreamStatus('connected');
        markLiveDataFresh();
    });
    const { result, unmount: unmountState } = renderHook(useStreamState);
    const { unmount } = renderHook(() =>
        useLiveReload(['state.changed'], ['sites']),
    );
    act(() => {
        receiveLiveSignal({ topic: 'state.changed', ids: {}, at: '' });
        vi.advanceTimersByTime(1_000);
    });
    expect(reload).toHaveBeenCalledTimes(1);
    act(() => {
        expect(
            reload.mock.calls[0]?.[0].onNetworkError(
                new Error('Network error'),
            ),
        ).toBe(false);
        reload.mock.calls[0]?.[0].onFinish();
    });
    expect(result.current.dataReloadFailed).toBe(true);
    act(() => {
        vi.advanceTimersByTime(16_000);
    });
    expect(reload).toHaveBeenCalledTimes(2);
    act(() => {
        markLiveDataFresh();
        reload.mock.calls[1]?.[0].onFinish();
    });
    expect(result.current.dataReloadFailed).toBe(false);
    unmount();
    unmountState();
});

it('starts a reload during a continuous stream of changes', () => {
    const { unmount } = renderHook(() =>
        useLiveReload(['state.changed'], ['sites']),
    );

    for (let index = 0; index < 5; index++) {
        act(() => {
            receiveLiveSignal({ topic: 'state.changed', ids: {}, at: '' });
            vi.advanceTimersByTime(200);
        });
    }
    expect(reload).toHaveBeenCalledTimes(1);
    unmount();
});
