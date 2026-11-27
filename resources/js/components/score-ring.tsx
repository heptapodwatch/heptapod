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
    size = 'md',
}: {
    score: number;
    label: string;
    tone?: ScoreRingTone;
    /** Shown inside instead of the bare score ("92 %"). */
    text?: string;
    threshold?: number;
    /** `sm` for table rows: the bare number inside, the unit in the column header. */
    size?: 'md' | 'sm';
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
                'grid place-items-center rounded-full font-mono tabular-nums',
                size === 'sm' ? 'size-9 text-xs' : 'size-14 text-sm',
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
                    <span
                        className={cn(
                            'absolute top-0 left-1/2 w-0.5 -translate-x-1/2 bg-foreground',
                            size === 'sm' ? 'h-1' : 'h-1.5',
                        )}
                    />
                </span>
            )}
            <span
                className={cn(
                    'grid place-items-center rounded-full bg-card',
                    size === 'sm' ? 'size-7' : 'size-11',
                )}
                style={{
                    color: tone === 'neutral' ? 'var(--foreground)' : color,
                }}
            >
                {text ?? score}
            </span>
        </div>
    );
}
