import { describe, expect, it } from 'vitest';
import {
    createErrorDeck,
    poolForStatus,
    preferredFirstIdsForStatus,
} from '@/lib/error-mark';
import { ERROR_DECK } from '@/lib/mark-errors/deck';

const EXPECTED_IDS = [
    'glyphs-err',
    'display-check-error-word',
    'status-check-error',
    'status-failed',
    'patterns-seeded-flicker',
    'playful-slip',
];

function seededRandom(seed: number): () => number {
    let state = seed >>> 0;
    return () => {
        state = (Math.imul(1664525, state) + 1013904223) >>> 0;
        return state / 0x100000000;
    };
}

describe('error mark deck rules', () => {
    it('syncs only the 6 curated error sequences and never the full studio library', () => {
        expect(ERROR_DECK.map((sequence) => sequence.id)).toEqual(EXPECTED_IDS);
    });

    it('keeps 503 calm with only display-check-error-word', () => {
        const pool = poolForStatus(503);
        expect(pool.map((sequence) => sequence.id)).toEqual([
            'display-check-error-word',
        ]);

        const deck = createErrorDeck(503, seededRandom(7));
        expect(deck.next().id).toBe('display-check-error-word');
        expect(deck.next().id).toBe('display-check-error-word');
    });

    it('opens 404 and 410 with lighter sequences and 500 with fault sequences', () => {
        for (const status of [404, 410]) {
            const preferred = preferredFirstIdsForStatus(status);
            expect(preferred).not.toBeNull();
            for (let seed = 1; seed <= 25; seed++) {
                const first = createErrorDeck(
                    status,
                    seededRandom(seed),
                ).next();
                expect(preferred?.has(first.id)).toBe(true);
            }
        }

        const preferred500 = preferredFirstIdsForStatus(500);
        expect(preferred500).not.toBeNull();
        for (let seed = 1; seed <= 25; seed++) {
            const first = createErrorDeck(500, seededRandom(seed)).next();
            expect(preferred500?.has(first.id)).toBe(true);
        }
    });

    it('never repeats a sequence within a full pass and never opens a fresh deck with the last played sequence', () => {
        for (const status of [403, 404, 410, 413, 500]) {
            for (let seed = 1; seed <= 20; seed++) {
                const deck = createErrorDeck(status, seededRandom(seed));
                const size = deck.pool().length;
                expect(size).toBe(EXPECTED_IDS.length);

                let previousLast = '';
                for (let round = 0; round < 5; round++) {
                    const seen = new Set<string>();
                    let firstInRound = '';
                    let lastInRound = '';
                    for (let step = 0; step < size; step++) {
                        const sequence = deck.next();
                        if (step === 0) firstInRound = sequence.id;
                        if (step === size - 1) lastInRound = sequence.id;
                        expect(EXPECTED_IDS).toContain(sequence.id);
                        expect(seen.has(sequence.id)).toBe(false);
                        seen.add(sequence.id);
                    }
                    expect(seen.size).toBe(size);
                    if (previousLast !== '') {
                        expect(firstInRound).not.toBe(previousLast);
                    }
                    previousLast = lastInRound;
                }
            }
        }
    });
});
