import { cn } from '@/lib/utils';

/** A gauge for a value that is not a score (server usage): calm, near the threshold, or at/over it. */
export type ScoreRingTone = 'neutral' | 'warning' | 'failed';

const toneColor: Record<ScoreRingTone, string> = {
    // The quiet chart tone, so the warning and failed rings stand out.
    neutral: 'var(--chart-4)',
    warning: 'var(--status-warn)',
    failed: 'var(--status-down)',
};

/**
 * A 0–100 value as a ring with the number inside. Without `tone` it is a Lighthouse score (coloured by score band);
 * with `tone` the caller colours it, shows `text` inside and may mark an alert `threshold` with a tick on the ring.
 */
export function ScoreRing({
    score,
    label,
    tone,
    text,
    threshold,
}: {
    score: number;
    label: string;
    tone?: ScoreRingTone;
    /** Shown inside instead of the bare score ("92 %"). */
    text?: string;
    threshold?: number;
}) {
    const color =
        tone !== undefined
            ? toneColor[tone]
            : score >= 90
              ? 'var(--status-ok)'
              : score >= 50
                ? 'var(--status-warn)'
                : 'var(--status-down)';
    const shown = Math.min(100, Math.max(0, score));
    return (
        <div
            role="img"
            aria-label={
                text === undefined
                    ? `${label}: ${score}/100`
                    : `${label}: ${text}`
            }
            className={cn(
                'grid size-14 place-items-center rounded-full font-mono text-sm tabular-nums',
                threshold !== undefined && 'relative',
            )}
            style={{
                background: `conic-gradient(${color} ${shown}%, var(--muted) 0)`,
            }}
        >
            {threshold !== undefined && (
                <span
                    aria-hidden
                    className="absolute inset-0"
                    style={{ transform: `rotate(${threshold * 3.6}deg)` }}
                >
                    <span className="absolute top-0 left-1/2 h-1.5 w-0.5 -translate-x-1/2 bg-foreground" />
                </span>
            )}
            <span
                className="grid size-11 place-items-center rounded-full bg-card"
                style={{
                    color: tone === 'neutral' ? 'var(--foreground)' : color,
                }}
            >
                {text ?? score}
            </span>
        </div>
    );
}
