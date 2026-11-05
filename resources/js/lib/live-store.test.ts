// @vitest-environment jsdom

import { act, renderHook } from '@testing-library/react';
import { expect, test, vi } from 'vitest';
import {
    receiveLiveSignal,
    setStreamStatus,
    useRunState,
    useStreamState,
} from './live-store';

test('reads public Reverb transport from HTML and defaults to the page origin', async () => {
    const meta = document.createElement('meta');
    meta.name = 'heptapod-reverb';
    meta.content = JSON.stringify({
        key: 'public-key',
        host: null,
        port: null,
        scheme: null,
        path: '',
    });
    document.head.append(meta);
    vi.resetModules();
    const { reverbClient } = await import('./live-store');
    expect(reverbClient).toEqual({
        key: 'public-key',
        host: window.location.hostname,
        port: window.location.port ? Number(window.location.port) : 80,
        scheme: 'http',
        path: '',
    });
    meta.content = JSON.stringify({
        key: 'second-key',
        host: 'socket.example.test',
        port: 9443,
        scheme: 'https',
        path: '/live',
    });
    vi.resetModules();
    const custom = await import('./live-store');
    expect(custom.reverbClient).toEqual({
        key: 'second-key',
        host: 'socket.example.test',
        port: 9443,
        scheme: 'https',
        path: '/live',
    });
    meta.remove();
});

test('disconnect invalidates run states missed while the socket was unavailable', () => {
    const { result, unmount } = renderHook(() => useRunState(852));
    act(() => {
        setStreamStatus('connected');
        receiveLiveSignal({
            topic: 'run.started',
            ids: { check: 852 },
            at: '2026-10-03T12:00:00Z',
            run: {
                check_id: 852,
                phase: 'started',
                queue_position: null,
                progress_n: null,
                progress_total: null,
                started_at: null,
                actor: null,
            },
        });
    });
    expect(result.current?.phase).toBe('started');

    act(() => {
        setStreamStatus('connecting');
        setStreamStatus('connected');
    });
    expect(result.current).toBeUndefined();
    unmount();
});

test('reconnecting after brief and prolonged interruptions requests one catch-up each', () => {
    const { result, unmount } = renderHook(useStreamState);
    act(() => {
        setStreamStatus('connecting');
        setStreamStatus('connected');
    });
    const initial = result.current.reconnected;
    act(() => {
        setStreamStatus('connecting');
        setStreamStatus('connected');
    });
    expect(result.current.reconnected).toBe(initial + 1);
    act(() => {
        setStreamStatus('lost');
        setStreamStatus('connecting');
        setStreamStatus('connected');
    });
    expect(result.current.reconnected).toBe(initial + 2);
    unmount();
});
