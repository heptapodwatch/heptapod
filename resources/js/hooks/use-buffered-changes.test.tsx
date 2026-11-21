import { act, renderHook } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import { useBufferedChanges } from './use-buffered-changes';

type Listener = (event: { detail: Record<string, unknown> }) => void;
const listeners = vi.hoisted(() => new Map<string, Set<Listener>>());

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ url: '/sites' }),
    router: {
        on: (event: string, listener: Listener) => {
            const set = listeners.get(event) ?? new Set<Listener>();
            set.add(listener);
            listeners.set(event, set);
            return () => set.delete(listener);
        },
    },
}));

function emit(event: string, detail: Record<string, unknown>) {
    for (const listener of listeners.get(event) ?? []) {
        listener({ detail });
    }
}

it('updates existing rows while buffering insertion and reordering', () => {
    const initial = [
        { id: 1, status: 'healthy' },
        { id: 2, status: 'healthy' },
    ];
    const fresh = [
        { id: 2, status: 'failed' },
        { id: 3, status: 'healthy' },
        { id: 1, status: 'healthy' },
    ];
    const { result, rerender } = renderHook(
        ({ rows }) => useBufferedChanges(rows, (row) => row.id),
        { initialProps: { rows: initial } },
    );

    rerender({ rows: fresh });
    expect(result.current.rows).toEqual([
        { id: 1, status: 'healthy' },
        { id: 2, status: 'failed' },
    ]);
    expect(result.current.pendingCount).toBe(1);

    act(() => result.current.apply());
    expect(result.current.rows).toEqual(fresh);
    expect(result.current.pendingCount).toBe(0);
});

it('applies only the own mutation while keeping previously buffered foreign changes pending', () => {
    const initial = [
        { id: 1, label: 'Mine v1' },
        { id: 2, label: 'Foreign v1' },
    ];
    const afterForeign = [
        { id: 1, label: 'Mine v1' },
        { id: 2, label: 'Foreign v2' },
        { id: 3, label: 'Foreign new' },
    ];
    const afterOwnEdit = [
        { id: 1, label: 'Mine v2' },
        { id: 2, label: 'Foreign v2' },
        { id: 3, label: 'Foreign new' },
        { id: 4, label: 'Mine created' },
    ];
    const { result, rerender } = renderHook(
        ({ rows }) =>
            useBufferedChanges(
                rows,
                (row) => row.id,
                null,
                (row) => JSON.stringify(row),
                'windows',
            ),
        { initialProps: { rows: initial } },
    );

    rerender({ rows: afterForeign });
    expect(result.current.rows).toEqual(initial);
    expect(result.current.pendingCount).toBe(2);

    act(() => {
        emit('start', { visit: { id: 'v1', method: 'patch' } });
        emit('success', {
            visitId: 'v1',
            page: { props: { windows: afterOwnEdit } },
        });
    });
    rerender({ rows: afterOwnEdit });
    act(() => {
        emit('finish', { visit: { id: 'v1', method: 'patch' } });
    });

    expect(result.current.rows).toEqual([
        { id: 1, label: 'Mine v2' },
        { id: 2, label: 'Foreign v1' },
        { id: 4, label: 'Mine created' },
    ]);
    expect(result.current.pendingCount).toBe(2);

    act(() => result.current.apply());
    expect(result.current.rows).toEqual(afterOwnEdit);
    expect(result.current.pendingCount).toBe(0);
});
