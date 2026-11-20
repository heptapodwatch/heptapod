import { ERROR_DECK } from '@/lib/mark-errors/deck';
import type { Sequence } from '@/lib/mark-errors/format';
import { MarkPlayer } from '@/lib/mark-errors/player';

/** How long a holding error sequence (e.g. Er · check error) stays up before settling back to H. */
const HOLD_MS = 1400;

const LIGHTER_FIRST_IDS = new Set(['playful-slip', 'patterns-seeded-flicker']);
const FAULT_FIRST_IDS = new Set(['glyphs-err', 'status-check-error']);
const CALM_503_IDS = new Set(['display-check-error-word']);

/** Filtered error sequences allowed for a given HTTP status. */
export function poolForStatus(status: number): readonly Sequence[] {
    if (status === 503) {
        return ERROR_DECK.filter((sequence) => CALM_503_IDS.has(sequence.id));
    }
    return ERROR_DECK;
}

/** Optional preferred subset for the initial on-load play (e.g. lighter sequences on 404/410, fault ones on 500). */
export function preferredFirstIdsForStatus(
    status: number,
): ReadonlySet<string> | null {
    if (status === 404 || status === 410) return LIGHTER_FIRST_IDS;
    if (status === 500) return FAULT_FIRST_IDS;
    return null;
}

function shuffle<T>(items: readonly T[], random: () => number): T[] {
    const copy = [...items];
    for (let i = copy.length - 1; i > 0; i--) {
        const j = Math.floor(random() * (i + 1));
        const current = copy[i]!;
        copy[i] = copy[j]!;
        copy[j] = current;
    }
    return copy;
}

export type ErrorDeck = {
    next: () => Sequence;
    pool: () => readonly Sequence[];
};

/**
 * Shuffled error-sequence deck for one error page:
 * - Draws only from the status's filtered error pool (never the full studio library).
 * - Opens on load with a status-appropriate sequence when configured (404/410 lighter, 500 fault, 503 calm).
 * - Never repeats a sequence until every sequence in the pool has played once.
 * - A freshly reshuffled deck never opens with the sequence that just played (when pool size > 1).
 */
export function createErrorDeck(
    status: number,
    random: () => number = Math.random,
): ErrorDeck {
    const pool = poolForStatus(status);
    if (pool.length === 0) {
        throw new Error(`error-mark: empty error deck for status ${status}`);
    }

    let remaining: Sequence[] = [];
    let lastPlayed = '';
    let initialBuilt = false;

    const refill = () => {
        if (!initialBuilt) {
            initialBuilt = true;
            const preferredIds = preferredFirstIdsForStatus(status);
            if (preferredIds !== null) {
                const preferred = shuffle(
                    pool.filter((sequence) => preferredIds.has(sequence.id)),
                    random,
                );
                const first = preferred.pop();
                if (first) {
                    const rest = shuffle(
                        pool.filter((sequence) => sequence.id !== first.id),
                        random,
                    );
                    // `pop()` draws from the end, so put `first` last so it plays first on load.
                    remaining = [...rest, first];
                    return;
                }
            }
        }

        remaining = shuffle(pool, random);
        if (pool.length > 1 && remaining.at(-1)?.id === lastPlayed) {
            const duplicate = remaining.pop();
            if (duplicate) remaining.unshift(duplicate);
        }
    };

    return {
        pool: () => pool,
        next: () => {
            if (remaining.length === 0) refill();
            const sequence = remaining.pop();
            if (!sequence) {
                throw new Error('error-mark: deck unexpectedly empty');
            }
            lastPlayed = sequence.id;
            return sequence;
        },
    };
}

/**
 * Attaches the error-page mark player to an inline 7-segment Heptapod SVG (`data-seg="a".."g"`).
 * Plays one sequence once on load and a different shuffled one on each hover or keyboard focus.
 * Stays static under `prefers-reduced-motion: reduce` and pauses while the tab is hidden or off-screen.
 */
export function attachErrorMark(
    svg: SVGSVGElement,
    status: number,
    host: Element = svg,
): () => void {
    const deck = createErrorDeck(status);
    const player = new MarkPlayer(svg);
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
    const finePointer = window.matchMedia('(hover: hover) and (pointer: fine)');

    let releaseTimer = 0;
    let loadPlayed = false;
    let destroyed = false;

    const clearReleaseTimer = () => {
        if (releaseTimer !== 0) {
            window.clearTimeout(releaseTimer);
            releaseTimer = 0;
        }
    };

    const playNext = async () => {
        if (destroyed || reduced.matches || document.hidden) return;
        clearReleaseTimer();
        const sequence = deck.next();
        svg.dataset.errorSeq = sequence.id;
        const reached = await player.play(sequence, { loop: false });
        if (destroyed) return;
        if (reached && sequence.hold) {
            releaseTimer = window.setTimeout(() => {
                releaseTimer = 0;
                if (!destroyed && player.held === sequence) {
                    void player.release();
                }
            }, HOLD_MS);
        }
    };

    const playOnLoadIfVisible = () => {
        if (loadPlayed || destroyed || reduced.matches || document.hidden) {
            return;
        }
        loadPlayed = true;
        void playNext();
    };

    const enter = () => {
        if (destroyed || reduced.matches) return;
        loadPlayed = true;
        player.stepApart(true);
        void playNext();
    };

    const leave = () => {
        if (destroyed) return;
        player.stepApart(false);
    };

    const onPointerEnter = (event: Event) => {
        const pointer = event as PointerEvent;
        if (pointer.pointerType === 'mouse' || finePointer.matches) {
            enter();
        }
    };

    const onFocusIn = (event: Event) => {
        const target = event.target;
        if (target instanceof Element && target.matches(':focus-visible')) {
            enter();
        }
    };

    const onVisibilityChange = () => {
        if (!document.hidden) {
            playOnLoadIfVisible();
        }
    };

    const onReducedChange = () => {
        if (reduced.matches) {
            clearReleaseTimer();
            delete svg.dataset.errorSeq;
        }
    };

    host.addEventListener('pointerenter', onPointerEnter);
    host.addEventListener('pointerleave', leave);
    host.addEventListener('focusin', onFocusIn);
    host.addEventListener('focusout', leave);
    document.addEventListener('visibilitychange', onVisibilityChange);
    reduced.addEventListener('change', onReducedChange);

    // Play once on load (deferred one frame so computed currentColor reflects light/dark theme).
    const frameId = window.requestAnimationFrame(playOnLoadIfVisible);

    return () => {
        destroyed = true;
        window.cancelAnimationFrame(frameId);
        clearReleaseTimer();
        host.removeEventListener('pointerenter', onPointerEnter);
        host.removeEventListener('pointerleave', leave);
        host.removeEventListener('focusin', onFocusIn);
        host.removeEventListener('focusout', leave);
        document.removeEventListener('visibilitychange', onVisibilityChange);
        reduced.removeEventListener('change', onReducedChange);
        player.destroy();
    };
}
