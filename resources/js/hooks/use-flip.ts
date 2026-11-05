import { EASE_OUT } from '@/lib/motion';
import { useCallback, useLayoutEffect, useRef } from 'react';

/**
 * FLIP for keyed elements, ported from the landing's motion.ts flip(): elements carry
 * `data-flip-key`; `flip(change)` reads where every keyed element is on screen, runs `change`
 * (a React state update) and, after the commit, animates each element from where the eye saw it
 * to its new place. Transform and opacity only, on the Web Animations API.
 *
 * - Interruptible: the first read includes a running move, and the move is cancelled before the
 *   new place is measured, so an element caught mid-flight continues from where it is.
 * - Batched: all reads happen in one pass, then all writes; a burst costs one layout per phase.
 * - A key that appears fades in; a key that disappears fades out as a copy in its old place; a key
 *   that is still there but hidden (an inactive tab) slides out the same way.
 * - Reduced motion or a hidden tab: the change applies at once, nothing moves.
 */
const MOVE_MS = 320;
const ENTER_MS = 280;
const EXIT_MS = 220;

type Seen = { element: HTMLElement; box: DOMRect; opacity: number };

/**
 * Keyed elements, or null when a key repeats: two elements under one key would share one origin.
 * Development throws. Production reports the error and skips this animation batch only; the
 * state change itself has already been committed by React.
 */
function keyed(root: HTMLElement): HTMLElement[] | null {
    const elements = [...root.querySelectorAll<HTMLElement>('[data-flip-key]')];
    const seen = new Set<string>();
    for (const element of elements) {
        const key = element.dataset.flipKey ?? '';
        if (seen.has(key)) {
            const error = new Error(`Duplicate data-flip-key: ${key}`);
            if (import.meta.env.DEV) throw error;
            console.error(error);
            return null;
        }
        seen.add(key);
    }
    return elements;
}

function capture(root: HTMLElement): Map<string, Seen> | null {
    const elements = keyed(root);
    if (elements === null) return null;
    const seen = new Map<string, Seen>();
    for (const element of elements) {
        const key = element.dataset.flipKey;
        if (key === undefined) continue;
        seen.set(key, {
            element,
            box: element.getBoundingClientRect(),
            opacity: Number(getComputedStyle(element).opacity),
        });
    }
    return seen;
}

function ghost(
    root: HTMLElement,
    from: Seen,
    rootBox: DOMRect,
    slide: boolean,
) {
    const copy = from.element.cloneNode(true);
    if (!(copy instanceof HTMLElement)) return;
    copy.removeAttribute('data-flip-key');
    copy.setAttribute('aria-hidden', 'true');
    copy.inert = true;
    Object.assign(copy.style, {
        position: 'absolute',
        left: `${from.box.left - rootBox.left}px`,
        top: `${from.box.top - rootBox.top}px`,
        width: `${from.box.width}px`,
        height: `${from.box.height}px`,
        margin: '0',
        pointerEvents: 'none',
        zIndex: '1',
    });
    root.append(copy);
    copy.animate(
        {
            opacity: [from.opacity, 0],
            transform: [
                'translate(0px, 0px)',
                slide ? 'translate(24px, 0px)' : 'translate(0px, 0px)',
            ],
        },
        { duration: EXIT_MS, easing: EASE_OUT },
    ).finished.then(
        () => copy.remove(),
        () => copy.remove(),
    );
}

function play(root: HTMLElement, before: Map<string, Seen>) {
    const now = keyed(root);
    if (now === null) return;
    // Cancel running moves first, so the new place is measured without them.
    for (const element of now)
        for (const animation of element.getAnimations())
            if (!(animation instanceof CSSAnimation)) animation.cancel();
    const rootBox = root.getBoundingClientRect();
    const after = now.map((element) => ({
        element,
        key: element.dataset.flipKey,
        box: element.getBoundingClientRect(),
    }));
    const present = new Set<string>();
    for (const { element, key, box } of after) {
        if (key === undefined) continue;
        present.add(key);
        const from = before.get(key);
        const shown = box.width > 0;
        if (from === undefined || from.box.width === 0) {
            if (shown)
                element.animate(
                    {
                        opacity: [0, 1],
                        transform: ['translateY(-6px)', 'translateY(0px)'],
                    },
                    { duration: ENTER_MS, easing: EASE_OUT },
                );
            continue;
        }
        if (!shown) {
            ghost(root, from, rootBox, true);
            continue;
        }
        const dx = from.box.left - box.left;
        const dy = from.box.top - box.top;
        if (Math.abs(dx) < 0.5 && Math.abs(dy) < 0.5 && from.opacity === 1)
            continue;
        element.animate(
            {
                transform: [
                    `translate(${dx}px, ${dy}px)`,
                    'translate(0px, 0px)',
                ],
                opacity: [from.opacity, 1],
            },
            { duration: MOVE_MS, easing: EASE_OUT },
        );
    }
    for (const [key, from] of before)
        if (!present.has(key) && from.box.width > 0)
            ghost(root, from, rootBox, false);
}

export function motionAllowed(): boolean {
    return (
        !document.hidden &&
        !window.matchMedia('(prefers-reduced-motion: reduce)').matches
    );
}

/** Attach `ref` to a positioned container; wrap state changes that move its keyed children in `flip`. */
export function useFlip<T extends HTMLElement>() {
    const ref = useRef<T>(null);
    const pending = useRef<Map<string, Seen> | null>(null);
    useLayoutEffect(() => {
        const before = pending.current;
        pending.current = null;
        if (before !== null && ref.current !== null && motionAllowed())
            play(ref.current, before);
    });
    const flip = useCallback((change: () => void) => {
        // Keep the first read when several changes land before one commit: the DOM is unchanged.
        if (
            pending.current === null &&
            ref.current !== null &&
            motionAllowed()
        ) {
            pending.current = capture(ref.current);
            // A change that renders nothing must not leave a stale read for a later commit.
            requestAnimationFrame(() => {
                pending.current = null;
            });
        }
        change();
    }, []);
    return { ref, flip };
}
