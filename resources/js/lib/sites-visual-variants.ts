// MOCKUP ONLY (branch feat-sites-visual, phase 1). Remove in phase 2.
import { useSyncExternalStore } from 'react';

/** Each mockup dimension: its query parameter and its variants; the first one is today's UI. */
export const mockupDimensions = {
    strip: ['current', 'a', 'b', 'c'],
    lighthouse: ['off', 'l1', 'l2', 'l3'],
    head: ['current', 'h1', 'h2', 'h3'],
    incidents: ['current', 'i1', 'i2'],
} as const;

export type MockupDimension = keyof typeof mockupDimensions;
export type MockupVariant<D extends MockupDimension> =
    (typeof mockupDimensions)[D][number];

const listeners = new Set<() => void>();

function read<D extends MockupDimension>(dimension: D): MockupVariant<D> {
    const variants: readonly string[] = mockupDimensions[dimension];
    const storageKey = `sites-visual-mockup.${dimension}`;
    const fromQuery = new URLSearchParams(window.location.search).get(
        dimension,
    );
    if (fromQuery !== null && variants.includes(fromQuery))
        window.localStorage.setItem(storageKey, fromQuery);
    const stored = window.localStorage.getItem(storageKey);
    return (
        stored !== null && variants.includes(stored) ? stored : variants[0]
    ) as MockupVariant<D>;
}

export function setMockupVariant<D extends MockupDimension>(
    dimension: D,
    variant: MockupVariant<D>,
) {
    window.localStorage.setItem(`sites-visual-mockup.${dimension}`, variant);
    const url = new URL(window.location.href);
    url.searchParams.set(dimension, variant);
    window.history.replaceState(window.history.state, '', url);
    listeners.forEach((listener) => listener());
}

export function useMockupVariant<D extends MockupDimension>(
    dimension: D,
): MockupVariant<D> {
    return useSyncExternalStore(
        (listener) => {
            listeners.add(listener);
            return () => listeners.delete(listener);
        },
        () => read(dimension),
        () => mockupDimensions[dimension][0] as MockupVariant<D>,
    );
}
