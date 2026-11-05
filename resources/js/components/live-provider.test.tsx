import { act, render } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import { LiveProvider } from './live-provider';

const { subscribed, setStreamStatus, joined, left } = vi.hoisted(() => ({
    subscribed: [] as Array<() => void>,
    setStreamStatus: vi.fn(),
    joined: vi.fn(),
    left: vi.fn(),
}));

vi.mock('@laravel/echo-react', () => {
    const channel = {
        listen: vi.fn().mockReturnThis(),
        subscribed: vi.fn((callback: () => void) => {
            subscribed.push(callback);
            return channel;
        }),
        error: vi.fn().mockReturnThis(),
    };
    return {
        echo: () => ({
            join: (name: string) => {
                joined(name);
                return channel;
            },
            leaveChannel: left,
        }),
        useConnectionStatus: () => 'connected',
    };
});
vi.mock('@inertiajs/react', () => ({
    router: { on: () => () => {} },
    usePage: () => ({ props: { liveChannels: ['instance', 'client.2'] } }),
}));
vi.mock('@/lib/live-store', () => ({
    liveConfigured: true,
    markLiveDataFresh: vi.fn(),
    receiveLiveSignal: vi.fn(),
    setStreamStatus,
}));

it('marks the stream ready only after every presence subscription succeeds', () => {
    subscribed.length = 0;
    setStreamStatus.mockClear();
    joined.mockClear();
    left.mockClear();
    const { unmount } = render(<LiveProvider>ready</LiveProvider>);
    expect(joined).toHaveBeenNthCalledWith(1, 'instance');
    expect(joined).toHaveBeenNthCalledWith(2, 'client.2');
    expect(subscribed).toHaveLength(2);
    expect(setStreamStatus).not.toHaveBeenCalledWith('connected');
    act(() => subscribed[0]?.());
    expect(setStreamStatus).not.toHaveBeenCalledWith('connected');
    act(() => subscribed[1]?.());
    expect(setStreamStatus).toHaveBeenCalledWith('connected');
    unmount();
    expect(left).toHaveBeenCalledWith('presence-instance');
    expect(left).toHaveBeenCalledWith('presence-client.2');
});

it('ignores presence subscriptions that succeed after leaving', () => {
    subscribed.length = 0;
    setStreamStatus.mockClear();
    joined.mockClear();
    left.mockClear();
    const { unmount } = render(<LiveProvider>ready</LiveProvider>);
    expect(joined).toHaveBeenCalledTimes(2);
    unmount();
    expect(left).toHaveBeenCalledTimes(2);
    act(() => subscribed.forEach((callback) => callback()));
    expect(setStreamStatus).not.toHaveBeenCalledWith('connected');
});
