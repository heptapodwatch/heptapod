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

it('reloads after the first subscription only when a change since render is reported, and after every reconnect', async () => {
    // A fresh store: the tab's first connection is the case under test.
    vi.resetModules();
    const store = await import('@/lib/live-store');
    const { useLiveReload: freshReload } = await import('./use-live');
    const { unmount } = renderHook(() =>
        freshReload(['state.changed'], ['sites']),
    );
    const settle = () =>
        act(() => {
            vi.advanceTimersByTime(1_000);
        });

    act(() => {
        store.setStreamStatus('connecting');
    });
    act(() => {
        expect(store.setStreamStatus('connected')).toBe(true);
    });
    settle();
    expect(reload).not.toHaveBeenCalled();
    act(() => store.requestCatchUp());
    settle();
    expect(reload).toHaveBeenCalledTimes(1);
    act(() => {
        reload.mock.calls[0]?.[0].onFinish();
    });
    act(() => {
        store.setStreamStatus('lost');
        expect(store.setStreamStatus('connected')).toBe(false);
    });
    settle();
    expect(reload).toHaveBeenCalledTimes(2);
    unmount();
});

it('shows stale data after a partial reload fails and retries on the next poll', () => {
    act(() => {
        setStreamStatus('connected');
        markLiveDataFresh();
    });
    const { result, unmount: unmountState } = renderHook(() =>
        useStreamState('dataReloadFailed'),
    );
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
    expect(result.current).toBe(true);
    act(() => {
        vi.advanceTimersByTime(16_000);
    });
    expect(reload).toHaveBeenCalledTimes(2);
    act(() => {
        markLiveDataFresh();
        reload.mock.calls[1]?.[0].onFinish();
    });
    expect(result.current).toBe(false);
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
