import { Link } from '@inertiajs/react';
import {
    CircleAlert,
    CircleCheck,
    CircleX,
    Clock3,
    TriangleAlert,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { useFormat } from '@/hooks/use-format';
import { focusRingClass } from '@/lib/interaction';
import type { RunState } from '@/lib/live-store';
import { cn } from '@/lib/utils';

/** The run family (UI rules, Runs): RunGlyph, RunLine, RunCard. Nothing else draws a run. */
export type RunLifecycle = 'queued' | 'running' | 'finished';
export type RunOutcome = 'ok' | 'warning' | 'failed' | 'check-error';
export type RunProgress = { done: number; total: number };

const outcomeColor: Record<RunOutcome, string> = {
    ok: 'var(--status-ok)',
    warning: 'var(--status-warn)',
    failed: 'var(--status-down)',
    'check-error': 'var(--status-error)',
};
const outcomeIcon = {
    ok: CircleCheck,
    warning: TriangleAlert,
    failed: CircleX,
    'check-error': CircleAlert,
};
export const runLifecycleWord: Record<RunLifecycle, string> = {
    queued: 'Queued',
    running: 'Running',
    finished: 'Finished',
};
export const runOutcomeWord: Record<RunOutcome, string> = {
    ok: 'OK',
    warning: 'Warning',
    failed: 'Failed',
    'check-error': 'Check error',
};

/** A run names its result with one of four words; an uptime failure is Failed here, never Down. */
export function runOutcome(
    result: Heptapod.Contracts.RunResult | Heptapod.Contracts.CheckResult,
): RunOutcome {
    switch (result) {
        case 'succeeded':
            return 'ok';
        case 'warning':
            return 'warning';
        case 'failed':
            return 'failed';
        case 'errored-or-timed-out':
            return 'check-error';
        case 'pending':
            throw new Error('A finished run has no pending result.');
    }
}

/** The live phase of a run signal or hydrated state as a lifecycle word. */
export function runLifecycle(phase: RunState['phase']): RunLifecycle {
    return phase === 'queued'
        ? 'queued'
        : phase === 'finished'
          ? 'finished'
          : 'running';
}

export function runProgress(
    run: Pick<RunState, 'progress_n' | 'progress_total'>,
): RunProgress | undefined {
    return run.progress_n !== null &&
        run.progress_total !== null &&
        run.progress_total > 0
        ? {
              done: Math.min(run.progress_n, run.progress_total),
              total: run.progress_total,
          }
        : undefined;
}

/**
 * The one detail of a run in flight: the queue position, the progress count in its unit ("3,214 of 5,000 URLs"),
 * the percentage where the line is narrow, or the start time.
 */
export function useRunDetail() {
    const { t, i18n } = useTranslation();
    const format = useFormat();
    return (
        run: Pick<
            RunState,
            | 'phase'
            | 'queue_position'
            | 'progress_n'
            | 'progress_total'
            | 'started_at'
            | 'phase_label'
        >,
        { narrow = false }: { narrow?: boolean } = {},
    ): string | undefined => {
        if (run.phase === 'queued') {
            return run.queue_position === null
                ? undefined
                : `#${format.number(run.queue_position)}`;
        }
        const progress = runProgress(run);
        if (progress) {
            if (narrow) {
                return `${format.number(Math.floor((progress.done / progress.total) * 100))} %`;
            }
            const key = `run.progress.${run.phase_label}`;
            return t(i18n.exists(key) ? key : 'run.progress.other', {
                done: format.number(progress.done),
                total: format.number(progress.total),
            });
        }
        return run.started_at
            ? t('run.since', { time: format.time(run.started_at) })
            : undefined;
    };
}

/**
 * Queued = hollow clock; running = a ring that fills with progress, or a spinning arc without it (static under reduced
 * motion); finished = the result icon in its colour. Never the StatusDot shape of a check state.
 */
export function RunGlyph({
    phase,
    progress,
    outcome,
    className,
}: {
    phase: RunLifecycle;
    progress?: RunProgress;
    outcome?: RunOutcome;
    className?: string;
}) {
    const { t } = useTranslation();
    const label =
        phase === 'finished' && outcome
            ? `${t(runLifecycleWord[phase])} · ${t(runOutcomeWord[outcome])}`
            : t(runLifecycleWord[phase]);
    const size = cn('size-3.5 shrink-0', className);
    if (phase === 'queued') {
        return (
            <Clock3
                role="img"
                aria-label={label}
                className={cn(size, 'text-muted-foreground')}
            />
        );
    }
    if (phase === 'finished') {
        if (!outcome) throw new Error('A finished run needs its result.');
        const Icon = outcomeIcon[outcome];
        return (
            <Icon
                role="img"
                aria-label={label}
                className={size}
                style={{ color: outcomeColor[outcome] }}
            />
        );
    }
    const circumference = 2 * Math.PI * 5;
    const share = progress ? progress.done / progress.total : 0.3;
    return (
        <svg
            role="img"
            aria-label={label}
            viewBox="0 0 14 14"
            className={cn(
                size,
                'text-status-info',
                !progress && 'motion-safe:animate-[spin_1.1s_linear_infinite]',
            )}
        >
            <circle
                cx="7"
                cy="7"
                r="5"
                fill="none"
                strokeWidth="2"
                className="stroke-muted"
            />
            <circle
                cx="7"
                cy="7"
                r="5"
                fill="none"
                stroke="currentColor"
                strokeWidth="2"
                strokeLinecap="round"
                strokeDasharray={`${share * circumference} ${circumference}`}
                transform="rotate(-90 7 7)"
            />
        </svg>
    );
}

/** The one "You": who started a run, nothing more. */
export function YouMarker() {
    const { t } = useTranslation();
    return (
        <Badge variant="outline" className="h-5 shrink-0 px-1.5 text-micro">
            {t('You')}
        </Badge>
    );
}

/** A finished run's result: glyph and word. */
export function RunResultChip({ outcome }: { outcome: RunOutcome }) {
    const { t } = useTranslation();
    return (
        <Badge variant="outline" className="gap-1.5">
            <RunGlyph phase="finished" outcome={outcome} className="size-3" />
            {t(runOutcomeWord[outcome])}
        </Badge>
    );
}

export type RunLineProps = {
    phase: RunLifecycle;
    /** "Check · Site", or the state word where the page already names the check. */
    label: ReactNode;
    /** Exactly one: start time, progress count, queue position or result word. */
    detail?: ReactNode;
    progress?: RunProgress;
    outcome?: RunOutcome;
    mine?: boolean;
    /** The thin bar below the line; drawn only when progress exists. */
    bar?: boolean;
    href?: string;
    /** An action at the end of the line (Cancel, Open check). */
    trailing?: ReactNode;
    /** Wrap instead of truncating the label, where the line sits in a narrow slot (the Run check reason). */
    wrap?: boolean;
    className?: string;
};

export function RunLine({
    phase,
    label,
    detail,
    progress,
    outcome,
    mine = false,
    bar = false,
    href,
    trailing,
    wrap = false,
    className,
}: RunLineProps) {
    const { t } = useTranslation();
    const body = (
        <>
            <span
                className={cn(
                    'flex min-w-0 items-center gap-2 text-sm',
                    wrap && 'flex-wrap gap-y-0.5',
                )}
            >
                <RunGlyph phase={phase} progress={progress} outcome={outcome} />
                <span
                    data-truncate={wrap ? undefined : ''}
                    className={cn('min-w-0', !wrap && 'truncate')}
                >
                    {label}
                </span>
                {detail !== undefined && (
                    <span className="shrink-0 text-xs text-muted-foreground tabular-nums">
                        {detail}
                    </span>
                )}
                {mine && <YouMarker />}
                {trailing && (
                    <span className="ms-auto shrink-0">{trailing}</span>
                )}
            </span>
            {bar && progress && phase === 'running' && (
                <progress
                    className="ms-5.5 mt-1.5 block h-1 w-[calc(100%-1.375rem)] overflow-hidden rounded-full [&::-moz-progress-bar]:bg-status-info [&::-webkit-progress-bar]:bg-muted [&::-webkit-progress-value]:bg-status-info"
                    value={progress.done}
                    max={progress.total}
                    aria-label={t('Run progress')}
                />
            )}
        </>
    );
    return href ? (
        <Link
            href={href}
            className={cn(
                'block rounded-md px-2 py-1.5 hover:bg-accent',
                focusRingClass,
                className,
            )}
        >
            {body}
        </Link>
    ) : (
        <span className={cn('block px-2 py-1.5', className)}>{body}</span>
    );
}

/** A run's state where a table cell or header holds one value: the lifecycle word in flight, the result chip after. */
export function RunStatus({
    phase,
    outcome,
}: {
    phase: RunLifecycle;
    outcome?: RunOutcome;
}) {
    const { t } = useTranslation();
    if (phase === 'finished') {
        if (!outcome) throw new Error('A finished run needs its result.');
        return <RunResultChip outcome={outcome} />;
    }
    return (
        <span className="inline-flex items-center gap-1.5 text-xs">
            <RunGlyph phase={phase} />
            {t(runLifecycleWord[phase])}
        </span>
    );
}
