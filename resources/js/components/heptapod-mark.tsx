import { useEffect, useState } from 'react';

/**
 * The Heptapod mark: an H lit on one seven-segment digit (heavy variant, 120-unit grid).
 * Segments a–g follow the usual seven-segment naming; the H lights b c e f g and keeps a and d as ghosts.
 * State changes only opacity and fill colour; the hover "step apart" in heptapod-mark.css is the only movement.
 */
type Segment = 'a' | 'b' | 'c' | 'd' | 'e' | 'f' | 'g';

const thickness = 13;
const gap = 2.5;
const [left, right, top, middle, bottom] = [40, 80, 26, 60, 94];
const vertical = (x: number, from: number, to: number) => ({
    x: x - thickness / 2,
    y: from,
    width: thickness,
    height: to - from,
});
const horizontal = (y: number) => ({
    x: left + thickness / 2 + gap,
    y: y - thickness / 2,
    width: right - left - thickness - 2 * gap,
    height: thickness,
});
const segments: Record<Segment, ReturnType<typeof horizontal>> = {
    a: horizontal(top),
    b: vertical(right, top + gap, middle - gap),
    c: vertical(right, middle + gap, bottom - gap),
    d: horizontal(bottom),
    e: vertical(left, middle + gap, bottom - gap),
    f: vertical(left, top + gap, middle - gap),
    g: horizontal(middle),
};

const lit: Record<'h' | 1 | 2 | 3 | 4 | 5 | 6 | 7 | 8 | 9, Segment[]> = {
    h: ['b', 'c', 'e', 'f', 'g'],
    1: ['b', 'c'],
    2: ['a', 'b', 'g', 'e', 'd'],
    3: ['a', 'b', 'g', 'c', 'd'],
    4: ['f', 'g', 'b', 'c'],
    5: ['a', 'f', 'g', 'c', 'd'],
    6: ['a', 'f', 'g', 'e', 'd', 'c'],
    7: ['a', 'b', 'c'],
    8: ['a', 'b', 'c', 'd', 'e', 'f', 'g'],
    9: ['a', 'b', 'c', 'd', 'f', 'g'],
};

export function HeptapodMark({
    downCount,
    recovered,
}: {
    downCount: number;
    recovered: boolean;
}) {
    const hidden = useDocumentHidden();
    const glyph =
        downCount > 0 ? lit[Math.min(downCount, 9) as keyof typeof lit] : lit.h;
    const state =
        downCount > 9
            ? 'over'
            : downCount > 0
              ? 'down'
              : recovered
                ? 'recovered'
                : 'still';
    return (
        <svg
            className="hp-mark"
            data-state={state}
            data-paused={hidden ? '' : undefined}
            viewBox="33.5 19.5 53 81"
            aria-hidden="true"
            focusable="false"
        >
            {(Object.keys(segments) as Segment[]).map((key) => (
                <rect
                    key={key}
                    className={`hp-seg hp-${key}`}
                    data-lit={glyph.includes(key) ? '' : undefined}
                    rx={thickness / 2}
                    {...segments[key]}
                />
            ))}
        </svg>
    );
}

/** No mark animation runs while the tab is hidden. */
function useDocumentHidden(): boolean {
    const [hidden, setHidden] = useState(
        () => typeof document !== 'undefined' && document.hidden,
    );
    useEffect(() => {
        const update = () => setHidden(document.hidden);
        document.addEventListener('visibilitychange', update);
        return () => document.removeEventListener('visibilitychange', update);
    }, []);
    return hidden;
}
