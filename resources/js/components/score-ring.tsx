export function ScoreRing({ score, label }: { score: number; label: string }) {
    const color =
        score >= 90
            ? 'var(--status-ok)'
            : score >= 50
              ? 'var(--status-warn)'
              : 'var(--status-down)';
    return (
        <div
            role="img"
            aria-label={`${label}: ${score}/100`}
            className="grid size-14 place-items-center rounded-full font-mono text-sm tabular-nums"
            style={{
                background: `conic-gradient(${color} ${score}%, var(--muted) 0)`,
            }}
        >
            <span
                className="grid size-11 place-items-center rounded-full bg-card"
                style={{ color }}
            >
                {score}
            </span>
        </div>
    );
}
