import { act, renderHook } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import { useBufferedChanges } from './use-buffered-changes';

vi.mock('@inertiajs/react', () => ({ usePage: () => ({ url: '/sites' }) }));

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
