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

it('applies only the own mutation in Inertia prop-commit-before-success order while keeping buffered foreign changes pending', () => {
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

    // Production Inertia 3.11 order: start -> props commit (flushSync) -> success -> finish.
    act(() => {
        emit('start', { visit: { id: 'v1', method: 'patch' } });
    });
    rerender({ rows: afterOwnEdit });
    act(() => {
        emit('success', {
            visitId: 'v1',
            page: { props: { windows: afterOwnEdit } },
        });
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

it('repositions an edited row to its new server sort order without leaving its own reorder pending', () => {
    const initial = [
        { id: 1, starts: '10:00' },
        { id: 2, starts: '11:00' },
        { id: 3, starts: '12:00' },
    ];
    const afterForeign = [
        { id: 1, starts: '10:00' },
        { id: 2, starts: '11:00' },
        { id: 9, starts: '11:30' },
        { id: 3, starts: '12:00' },
    ];
    const afterReschedule = [
        { id: 2, starts: '11:00' },
        { id: 9, starts: '11:30' },
        { id: 3, starts: '12:00' },
        { id: 1, starts: '13:00' },
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
    expect(result.current.pendingCount).toBe(1);

    act(() => {
        emit('start', { visit: { id: 'v2', method: 'patch' } });
    });
    rerender({ rows: afterReschedule });
    act(() => {
        emit('success', {
            visitId: 'v2',
            page: { props: { windows: afterReschedule } },
        });
        emit('finish', { visit: { id: 'v2', method: 'patch' } });
    });

    expect(result.current.rows).toEqual([
        { id: 2, starts: '11:00' },
        { id: 3, starts: '12:00' },
        { id: 1, starts: '13:00' },
    ]);
    expect(result.current.pendingCount).toBe(1);
});

it('keeps a content-only own edit in place without revealing a buffered foreign reorder', () => {
    const initial = [
        { id: 'A', starts: '10:00', reason: 'A' },
        { id: 'B', starts: '11:00', reason: 'B' },
        { id: 'C', starts: '12:00', reason: 'C v1' },
    ];
    // Foreign tab rescheduled B after C.
    const afterForeignReorder = [
        { id: 'A', starts: '10:00', reason: 'A' },
        { id: 'C', starts: '12:00', reason: 'C v1' },
        { id: 'B', starts: '13:00', reason: 'B' },
    ];
    // This tab edited only C's reason (position unchanged in server order).
    const afterOwnReasonEdit = [
        { id: 'A', starts: '10:00', reason: 'A' },
        { id: 'C', starts: '12:00', reason: 'C v2' },
        { id: 'B', starts: '13:00', reason: 'B' },
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

    rerender({ rows: afterForeignReorder });
    expect(result.current.rows).toEqual(initial);
    expect(result.current.pendingCount).toBe(1);

    act(() => {
        emit('start', { visit: { id: 'v3', method: 'patch' } });
    });
    rerender({ rows: afterOwnReasonEdit });
    act(() => {
        emit('success', {
            visitId: 'v3',
            page: { props: { windows: afterOwnReasonEdit } },
        });
        emit('finish', { visit: { id: 'v3', method: 'patch' } });
    });

    expect(result.current.rows).toEqual([
        { id: 'A', starts: '10:00', reason: 'A' },
        { id: 'B', starts: '11:00', reason: 'B' },
        { id: 'C', starts: '12:00', reason: 'C v2' },
    ]);
    expect(result.current.pendingCount).toBe(1);

    act(() => result.current.apply());
    expect(result.current.rows).toEqual(afterOwnReasonEdit);
    expect(result.current.pendingCount).toBe(0);
});
